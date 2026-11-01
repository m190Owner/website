<?php
declare(strict_types=1);
function migrate_notifications(): void {
 global $db;if((int)$db->query('PRAGMA user_version')->fetchColumn()>=5)return;
 transaction(function()use($db){if((int)$db->query('PRAGMA user_version')->fetchColumn()>=5)return;
  $db->exec("ALTER TABLE reactions RENAME TO old_reactions;
   CREATE TABLE reactions(message_id INTEGER NOT NULL REFERENCES messages(id) ON DELETE CASCADE,user_id INTEGER NOT NULL REFERENCES users(id),emoji TEXT NOT NULL,PRIMARY KEY(message_id,user_id,emoji));
   INSERT INTO reactions SELECT message_id,user_id,'❤️' FROM old_reactions;
   DROP TABLE old_reactions;
   CREATE TABLE notifications(user_id INTEGER NOT NULL REFERENCES users(id),message_id INTEGER NOT NULL REFERENCES messages(id) ON DELETE CASCADE,kind TEXT NOT NULL,seen INTEGER NOT NULL DEFAULT 0,PRIMARY KEY(user_id,message_id));
   PRAGMA user_version=5;");
 });
}
function can_view_as(string $cid,int $who): bool {
 global $user;$saved=$user;$user=['id'=>$who];try{channel($cid);return true;}catch(ApiError $e){if(in_array($e->status,[403,404],true))return false;throw $e;}finally{$user=$saved;}
}
function mention_names(string $body): array {
 $body=preg_replace('/```[\s\S]*?```|`[^`\n]+`/',' ',$body);
 preg_match_all('/(?<![a-z0-9_@.])@([a-z0-9_]{3,24})(?![a-z0-9_])/i',$body,$matches);return array_values(array_unique(array_map('strtolower',$matches[1])));
}
function notify_message(int $id,array $channel,string $body,?int $reply): void {
 $targets=[];if($reply){$original=one('SELECT user_id FROM messages WHERE id=? AND channel_id=? AND deleted=0',[$reply,$channel['id']]);if($original)$targets[(int)$original['user_id']]='reply';}
 $names=mention_names($body);if(count($names)>20)fail(400,'Mention up to 20 people per message.');
 foreach($names as $name){$person=one('SELECT id FROM users WHERE username=?',[$name]);if($person)$targets[(int)$person['id']]='mention';}
 foreach($targets as $who=>$kind){if($who===uid()||blocked($who)||!can_view_as($channel['id'],$who))continue;sql('INSERT OR IGNORE INTO notifications VALUES(?,?,?,0)',[$who,$id,$kind]);
  // shortcut: retain the latest 500 events per person; add paged archival if larger inboxes are needed.
  sql('DELETE FROM notifications WHERE user_id=? AND message_id NOT IN (SELECT message_id FROM notifications WHERE user_id=? ORDER BY message_id DESC LIMIT 500)',[$who,$who]);
 }
}
function inbox_rows(bool $unread=false): array {
 $rows=sql('SELECT n.message_id,n.kind,n.seen,m.channel_id,m.body,m.created,m.user_id,u.name,u.username,u.avatar,c.name AS channel_name,c.server_id,s.name AS server_name FROM notifications n JOIN messages m ON m.id=n.message_id JOIN users u ON u.id=m.user_id JOIN channels c ON c.id=m.channel_id LEFT JOIN servers s ON s.id=c.server_id WHERE n.user_id=? AND m.deleted=0'.($unread?' AND n.seen=0':'').' AND NOT EXISTS(SELECT 1 FROM blocks b WHERE (b.user_id=? AND b.target=m.user_id) OR (b.target=? AND b.user_id=m.user_id)) ORDER BY n.message_id DESC LIMIT 500',[uid(),uid(),uid()])->fetchAll();
 $visible=[];$result=[];foreach($rows as $row){$cid=$row['channel_id'];if(!array_key_exists($cid,$visible))$visible[$cid]=can_view_as($cid,uid());if(!$visible[$cid])continue;if(!$row['server_id']&&one('SELECT 1 FROM channels WHERE id=? AND pair_key IS NOT NULL',[$cid]))$row['channel_name']=$row['name'];$result[]=$row;}return $result;
}
function inbox_count(array $channels): int {
 $visible=array_fill_keys(array_column($channels,'id'),true);$count=0;
 foreach(sql('SELECT m.channel_id,count(*) AS total FROM notifications n JOIN messages m ON m.id=n.message_id WHERE n.user_id=? AND n.seen=0 AND m.deleted=0 AND NOT EXISTS(SELECT 1 FROM blocks b WHERE (b.user_id=? AND b.target=m.user_id) OR (b.target=? AND b.user_id=m.user_id)) GROUP BY m.channel_id',[uid(),uid(),uid()])->fetchAll() as $r)if(isset($visible[$r['channel_id']]))$count+=(int)$r['total'];return $count;
}
function react_message(int $id,array $c,array $in): void {
 $emoji=$in['emoji']??'❤️';if(!is_string($emoji)||strlen($emoji)>50)fail(400,'Invalid reaction.');if(array_key_exists('selected',$in)&&!is_bool($in['selected']))fail(400,'Invalid reaction state.');
 transaction(function()use($id,$c,$in,$emoji){$exists=!!one('SELECT 1 FROM reactions WHERE message_id=? AND user_id=? AND emoji=?',[$id,uid(),$emoji]);$add=$in['selected']??!$exists;if(!$add){sql('DELETE FROM reactions WHERE message_id=? AND user_id=? AND emoji=?',[$id,uid(),$emoji]);return;}if($exists)return;
  if(!in_array($emoji,['❤️','👍','👎','😂','🎉','😮','😢','🔥','✅','👀'],true)){
   if(!preg_match('/^custom:([a-f0-9]{32})$/',$emoji,$match)||!$c['server_id']||!one("SELECT 1 FROM media WHERE id=? AND server_id=? AND slot LIKE 'emoji:%'",[$match[1],$c['server_id']]))fail(400,'Choose a reaction from this server.');
  }
  if(sql('SELECT count(*) FROM reactions WHERE message_id=? AND user_id=?',[$id,uid()])->fetchColumn()>=10)fail(409,'Use up to 10 reactions per message.');if(!one('SELECT 1 FROM reactions WHERE message_id=? AND emoji=?',[$id,$emoji])&&sql('SELECT count(DISTINCT emoji) FROM reactions WHERE message_id=?',[$id])->fetchColumn()>=20)fail(409,'This message already has 20 different reactions.');
  sql('INSERT INTO reactions VALUES(?,?,?)',[$id,uid(),$emoji]);
 });
}
function notification_actions(string $action,array $in): array {
 switch($action){
 case 'inbox':$rows=inbox_rows(($in['unread']??false)===true);return ['items'=>array_slice($rows,0,100),'unread'=>count(array_filter($rows,fn($r)=>!$r['seen'])),'limited'=>count($rows)>100];
 case 'inbox_read':
  if(($in['all']??false)===true)sql('UPDATE notifications SET seen=1 WHERE user_id=?',[uid()]);else{$id=positive_id($in['message_id']??null);sql('UPDATE notifications SET seen=1 WHERE user_id=? AND message_id=?',[uid(),$id]);}return ['ok'=>true];
 case 'mention_members':
  $c=channel(text_field($in,'channel_id',32,32));$people=$c['server_id']?sql('SELECT u.* FROM users u JOIN members m ON m.user_id=u.id WHERE m.server_id=? ORDER BY u.name',[$c['server_id']])->fetchAll():sql('SELECT u.* FROM users u JOIN channel_members m ON m.user_id=u.id WHERE m.channel_id=? ORDER BY u.name',[$c['id']])->fetchAll();
  return ['members'=>array_values(array_map('public_user',array_filter($people,fn($p)=>(int)$p['id']!==uid()&&!blocked((int)$p['id'])&&can_view_as($c['id'],(int)$p['id']))))];
 case 'message_context':
  $id=positive_id($in['message_id']??null);$m=one('SELECT channel_id FROM messages WHERE id=? AND deleted=0',[$id]);if(!$m)fail(404,'Message no longer available.');$c=channel($m['channel_id']);$after=sql('SELECT id FROM messages WHERE channel_id=? AND deleted=0 AND id>=? ORDER BY id LIMIT 30',[$c['id'],$id])->fetchAll();$last=end($after);return ['channel'=>$c,'messages'=>messages_for($c['id'],(int)$last['id']+1)];
 }fail(404,'Unknown notification action.');
}
