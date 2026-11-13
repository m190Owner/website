<?php
declare(strict_types=1);
function migrate_everyone_alerts(): void {
 global $db;
 if((int)$db->query('PRAGMA user_version')->fetchColumn()>=24)return;
 transaction(function()use($db){
  if((int)$db->query('PRAGMA user_version')->fetchColumn()>=24)return;
  $db->exec('ALTER TABLE server_notification_preferences ADD COLUMN suppress_everyone INTEGER NOT NULL DEFAULT 0 CHECK(suppress_everyone IN (0,1)); PRAGMA user_version=24;');
 });
}
function migrate_category_notification_mutes(): void {
 global $db;if((int)$db->query('PRAGMA user_version')->fetchColumn()>=23)return;
 transaction(function()use($db){if((int)$db->query('PRAGMA user_version')->fetchColumn()>=23)return;
  $db->exec("CREATE TABLE category_notification_mutes(user_id INTEGER NOT NULL REFERENCES users(id) ON DELETE CASCADE,server_id TEXT NOT NULL REFERENCES servers(id) ON DELETE CASCADE,category TEXT NOT NULL,muted_until INTEGER NOT NULL,revision INTEGER NOT NULL,silent_through INTEGER NOT NULL DEFAULT 0,PRIMARY KEY(user_id,server_id,category));
   CREATE TRIGGER category_mutes_delete AFTER DELETE ON channels WHEN OLD.parent_id IS NULL BEGIN
    DELETE FROM category_notification_mutes WHERE server_id=OLD.server_id AND category=OLD.category AND NOT EXISTS(SELECT 1 FROM channels WHERE server_id=OLD.server_id AND category=OLD.category AND parent_id IS NULL);
   END;
   CREATE TRIGGER category_mutes_move AFTER UPDATE OF category,server_id,parent_id ON channels WHEN OLD.parent_id IS NULL BEGIN
    DELETE FROM category_notification_mutes WHERE server_id=OLD.server_id AND category=OLD.category AND NOT EXISTS(SELECT 1 FROM channels WHERE server_id=OLD.server_id AND category=OLD.category AND parent_id IS NULL);
   END; PRAGMA user_version=23;");
 });
}
function migrate_role_alerts(): void {
 global $db;if((int)$db->query('PRAGMA user_version')->fetchColumn()>=22)return;
 transaction(function()use($db){if((int)$db->query('PRAGMA user_version')->fetchColumn()>=22)return;$db->exec('ALTER TABLE server_notification_preferences ADD COLUMN suppress_roles INTEGER NOT NULL DEFAULT 0 CHECK(suppress_roles IN (0,1)); PRAGMA user_version=22;');});
}
function migrate_category_notifications(): void {
 global $db;if((int)$db->query('PRAGMA user_version')->fetchColumn()>=21)return;
 transaction(function()use($db){if((int)$db->query('PRAGMA user_version')->fetchColumn()>=21)return;
  $db->exec("CREATE TABLE next_notification_preferences(user_id INTEGER NOT NULL REFERENCES users(id) ON DELETE CASCADE,channel_id TEXT NOT NULL REFERENCES channels(id) ON DELETE CASCADE,mode TEXT NOT NULL CHECK(mode IN ('all','mentions','muted','inherit','category')),PRIMARY KEY(user_id,channel_id));
   INSERT INTO next_notification_preferences SELECT * FROM notification_preferences;
   DROP TABLE notification_preferences; ALTER TABLE next_notification_preferences RENAME TO notification_preferences;
   CREATE TABLE category_notification_preferences(user_id INTEGER NOT NULL REFERENCES users(id) ON DELETE CASCADE,server_id TEXT NOT NULL REFERENCES servers(id) ON DELETE CASCADE,category TEXT NOT NULL,mode TEXT NOT NULL CHECK(mode IN ('all','mentions','muted')),PRIMARY KEY(user_id,server_id,category));
   CREATE TRIGGER category_notifications_delete AFTER DELETE ON channels WHEN OLD.parent_id IS NULL BEGIN
    DELETE FROM category_notification_preferences WHERE server_id=OLD.server_id AND category=OLD.category AND NOT EXISTS(SELECT 1 FROM channels WHERE server_id=OLD.server_id AND category=OLD.category AND parent_id IS NULL);
   END;
   CREATE TRIGGER category_notifications_move AFTER UPDATE OF category,server_id,parent_id ON channels WHEN OLD.parent_id IS NULL BEGIN
    DELETE FROM category_notification_preferences WHERE server_id=OLD.server_id AND category=OLD.category AND NOT EXISTS(SELECT 1 FROM channels WHERE server_id=OLD.server_id AND category=OLD.category AND parent_id IS NULL);
   END;
   PRAGMA user_version=21;");
 });
}
function migrate_server_notification_mutes(): void {
 global $db;if((int)$db->query('PRAGMA user_version')->fetchColumn()>=20)return;
 transaction(function()use($db){if((int)$db->query('PRAGMA user_version')->fetchColumn()>=20)return;
  $db->exec("CREATE TABLE server_notification_mutes(user_id INTEGER NOT NULL REFERENCES users(id) ON DELETE CASCADE,server_id TEXT NOT NULL REFERENCES servers(id) ON DELETE CASCADE,muted_until INTEGER NOT NULL,revision INTEGER NOT NULL,silent_through INTEGER NOT NULL DEFAULT 0,PRIMARY KEY(user_id,server_id)); PRAGMA user_version=20;");
 });
}
// Called after channel access or server membership validation, inside the preference transaction.
function save_notification_mute(string $id,array $in,bool $server=false,?string $category=null): ?array {
 $duration=array_key_exists('mute_for',$in)?$in['mute_for']:'keep';
 if($duration!=='keep'&&(!is_int($duration)||!in_array($duration,[0,900,3600,28800,86400],true)))fail(400,'Choose a mute duration.');
 $table=$category!==null?'category_notification_mutes':($server?'server_notification_mutes':'notification_mutes');$key=$server||$category!==null?'server_id':'channel_id';$ids=[uid(),$id];if($category!==null)$ids[]=$category;$where="user_id=? AND $key=?".($category!==null?' AND category=?':'');$columns="user_id,$key".($category!==null?',category':'');
 $mute=one("SELECT * FROM $table WHERE $where",$ids);if($duration==='keep')return $mute;
 if(!isset($in['mute_revision'])||!is_int($in['mute_revision'])||$in['mute_revision']!==(int)($mute['revision']??0))fail(409,'The mute changed elsewhere. Reopen notification settings and try again.');
 if($duration===0&&(int)($mute['muted_until']??0)<=time())return $mute;
 $watermark=$server?'SELECT COALESCE(max(m.id),0) FROM messages m JOIN channels c ON c.id=m.channel_id WHERE c.server_id=?':'SELECT COALESCE(max(id),0) FROM messages WHERE channel_id=?';
 if($category!==null)$watermark='SELECT COALESCE(max(m.id),0) FROM messages m JOIN channels c ON c.id=m.channel_id LEFT JOIN channels p ON p.id=c.parent_id WHERE c.server_id=? AND COALESCE(p.category,c.category)=?';
 $mute=['muted_until'=>time()+$duration,'revision'=>(int)($mute['revision']??0)+1,'silent_through'=>$duration===0?(int)sql($watermark,$category!==null?[$id,$category]:[$id])->fetchColumn():0];
 sql("INSERT INTO $table($columns,muted_until,revision,silent_through) VALUES(".implode(',',array_fill(0,count($ids)+3,'?')).") ON CONFLICT($columns) DO UPDATE SET muted_until=excluded.muted_until,revision=excluded.revision,silent_through=excluded.silent_through",[...$ids,$mute['muted_until'],$mute['revision'],$mute['silent_through']]);return $mute;
}
function migrate_notification_mutes(): void {
 global $db;if((int)$db->query('PRAGMA user_version')->fetchColumn()>=19)return;
 transaction(function()use($db){if((int)$db->query('PRAGMA user_version')->fetchColumn()>=19)return;
  $db->exec("CREATE TABLE notification_mutes(user_id INTEGER NOT NULL REFERENCES users(id) ON DELETE CASCADE,channel_id TEXT NOT NULL REFERENCES channels(id) ON DELETE CASCADE,muted_until INTEGER NOT NULL,revision INTEGER NOT NULL,silent_through INTEGER NOT NULL DEFAULT 0,PRIMARY KEY(user_id,channel_id)); PRAGMA user_version=19;");
 });
}
function notification_mute_fields(?array $mute): array {
 $until=(int)($mute['muted_until']??0);return ['notification_muted_until'=>$until,'notification_muted'=>$until>time(),'notification_mute_revision'=>(int)($mute['revision']??0)];
}
function migrate_server_notifications(): void {
 global $db;if((int)$db->query('PRAGMA user_version')->fetchColumn()>=18)return;
 transaction(function()use($db){if((int)$db->query('PRAGMA user_version')->fetchColumn()>=18)return;
  $db->exec("CREATE TABLE next_notification_preferences(user_id INTEGER NOT NULL REFERENCES users(id) ON DELETE CASCADE,channel_id TEXT NOT NULL REFERENCES channels(id) ON DELETE CASCADE,mode TEXT NOT NULL CHECK(mode IN ('all','mentions','muted','inherit')),PRIMARY KEY(user_id,channel_id));
   INSERT INTO next_notification_preferences SELECT * FROM notification_preferences;
   DROP TABLE notification_preferences; ALTER TABLE next_notification_preferences RENAME TO notification_preferences;
   CREATE TABLE server_notification_preferences(user_id INTEGER NOT NULL REFERENCES users(id) ON DELETE CASCADE,server_id TEXT NOT NULL REFERENCES servers(id) ON DELETE CASCADE,mode TEXT NOT NULL CHECK(mode IN ('all','mentions','muted')),PRIMARY KEY(user_id,server_id)); PRAGMA user_version=18;");
 });
}
function migrate_notification_preferences(): void {
 global $db;if((int)$db->query('PRAGMA user_version')->fetchColumn()>=17)return;
 transaction(function()use($db){if((int)$db->query('PRAGMA user_version')->fetchColumn()>=17)return;
  $db->exec("CREATE TABLE notification_preferences(user_id INTEGER NOT NULL REFERENCES users(id) ON DELETE CASCADE,channel_id TEXT NOT NULL REFERENCES channels(id) ON DELETE CASCADE,mode TEXT NOT NULL CHECK(mode IN ('all','mentions','muted')),PRIMARY KEY(user_id,channel_id)); PRAGMA user_version=17;");
 });
}
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
function mention_text(string $body): string {
 $body=preg_replace('/```[\s\S]*?```|`[^`\n]+`/',' ',$body);
 $body=preg_replace('~\[([^\]\n]+)\]\(([^()\s]+(?:\([^()\s]*\)[^()\s]*)*)\)~','$1',$body);
 $body=preg_replace('~(?<![a-z0-9_@/\\\\])https?://(?:(?!\~\~)[^\s<>"\'`*|])+~i',' ',$body);
 return $body;
}
function mention_names(string $body): array {
 preg_match_all('/(?<![a-z0-9_@.])@([a-z0-9_]{3,24})(?![a-z0-9_])/i',mention_text($body),$matches);return array_values(array_unique(array_map('strtolower',$matches[1])));
}
function mention_roles(string $body): array {
 preg_match_all('/<@&([a-f0-9]{32})>/',mention_text($body),$matches);return array_values(array_unique($matches[1]));
}
function role_preview(string $body,array $names): string {
 // Match code and whole links first so only mention text, including link labels, is substituted.
 return preg_replace_callback('~```[\s\S]*?```|`[^`\n]+`|\[([^\]\n]+)\]\(([^()\s]+(?:\([^()\s]*\)[^()\s]*)*)\)|<@&([a-f0-9]{32})>~',function($m)use($names){
  if(isset($m[3]))return '@'.($names[$m[3]]??'Deleted role');
  if(isset($m[1]))return '['.role_preview($m[1],$names).']('.$m[2].')';
  return $m[0];
 },$body);
}
function notify_message(int $id,array $channel,string $body,?int $reply,?string $broadcast=null): void {
 $targets=[];if($reply){$original=one('SELECT user_id FROM messages WHERE id=? AND channel_id=? AND deleted=0',[$reply,$channel['id']]);if($original)$targets[(int)$original['user_id']]='reply';}
 $names=mention_names($body);$roles=mention_roles($body);if(count($names)+count($roles)>20)fail(400,'Use up to 20 person or role mentions per message.');
 if($roles){if(!$channel['server_id'])fail(400,'Role mentions are only available in servers.');require_permission($channel['server_id'],'mention_roles');
  foreach($roles as $role)if(!one('SELECT id FROM roles WHERE id=? AND server_id=?',[$role,$channel['server_id']]))fail(400,'A mentioned role is no longer available in this server.');
  // shortcut: bound role expansion for shared hosting; use queued delivery before supporting larger mentions.
  $marks=implode(',',array_fill(0,count($roles),'?'));$people=sql("SELECT DISTINCT user_id FROM member_roles WHERE server_id=? AND role_id IN ($marks) LIMIT 201",[$channel['server_id'],...$roles])->fetchAll();if(count($people)>200)fail(400,'This role mention is too large. Mention a smaller role.');
  foreach($people as $person)$targets[(int)$person['user_id']]??='role';
 }
 if($broadcast){
  if(!$channel['server_id']||!in_array($broadcast,$names,true))fail(400,"Use @$broadcast in a server message.");
  require_permission($channel['server_id'],'mention_everyone');
  $people=$broadcast==='here'
   ?sql("SELECT m.user_id FROM members m JOIN users u ON u.id=m.user_id WHERE m.server_id=? AND u.status='online' AND EXISTS(SELECT 1 FROM sessions s WHERE s.user_id=u.id AND s.expires>? AND s.seen>=? AND s.idle=0) LIMIT 101",[$channel['server_id'],time(),time()-45])->fetchAll()
   :sql('SELECT user_id FROM members WHERE server_id=? LIMIT 101',[$channel['server_id']])->fetchAll();
  if(count($people)>100)fail(400,'This server is too large for an everyone mention.');
  foreach($people as $person){
   $who=(int)$person['user_id'];
   if(!isset($targets[$who]))$targets[$who]='everyone';
   elseif($targets[$who]==='role')$targets[$who]='role_everyone';
  }
  $names=array_diff($names,['everyone','here']);
 }
 foreach($names as $name){$person=one('SELECT id FROM users WHERE username=?',[$name]);if($person)$targets[(int)$person['id']]='mention';}
 foreach($targets as $who=>$kind){if($who===uid()||blocked($who)||!can_view_as($channel['id'],$who))continue;sql('INSERT OR IGNORE INTO notifications VALUES(?,?,?,0)',[$who,$id,$kind]);
  // shortcut: retain the latest 500 events per person; add paged archival if larger inboxes are needed.
  sql('DELETE FROM notifications WHERE user_id=? AND message_id NOT IN (SELECT message_id FROM notifications WHERE user_id=? ORDER BY message_id DESC LIMIT 500)',[$who,$who]);
 }
}
function inbox_rows(bool $unread=false): array {
 $rows=sql('SELECT n.message_id,n.kind,n.seen,m.channel_id,m.body,m.created,m.user_id,u.name,u.username,u.avatar,c.name AS channel_name,c.server_id,s.name AS server_name FROM notifications n JOIN messages m ON m.id=n.message_id JOIN users u ON u.id=m.user_id JOIN channels c ON c.id=m.channel_id LEFT JOIN servers s ON s.id=c.server_id WHERE n.user_id=? AND m.deleted=0'.($unread?' AND n.seen=0':'').' AND NOT EXISTS(SELECT 1 FROM blocks b WHERE (b.user_id=? AND b.target=m.user_id) OR (b.target=? AND b.user_id=m.user_id)) ORDER BY n.message_id DESC LIMIT 500',[uid(),uid(),uid()])->fetchAll();
 $visible=[];$result=[];$roleNames=[];foreach($rows as $row){$cid=$row['channel_id'];if(!array_key_exists($cid,$visible))$visible[$cid]=can_view_as($cid,uid());if(!$visible[$cid])continue;$sid=$row['server_id'];if($sid&&!isset($roleNames[$sid]))$roleNames[$sid]=array_column(sql('SELECT id,name FROM roles WHERE server_id=?',[$sid])->fetchAll(),'name','id');$row['preview']=role_preview($row['body'],$roleNames[$sid]??[]);if(in_array($row['kind'],['role','everyone','role_everyone'],true))$row['kind']='mention';if(!$row['server_id']&&one('SELECT 1 FROM channels WHERE id=? AND pair_key IS NOT NULL',[$cid]))$row['channel_name']=$row['name'];$result[]=$row;}return $result;
}
function inbox_summary(array $channels): array {
 $visible=array_fill_keys(array_column($channels,'id'),true);$result=[];
 foreach(sql('SELECT m.channel_id,count(*) AS total,min(m.id) AS first_id,max(m.id) AS last_id,max(CASE WHEN (CASE n.kind WHEN \'role\' THEN COALESCE(np.suppress_roles,0)=0 WHEN \'everyone\' THEN COALESCE(np.suppress_everyone,0)=0 WHEN \'role_everyone\' THEN (COALESCE(np.suppress_roles,0)=0 OR COALESCE(np.suppress_everyone,0)=0) ELSE 1 END) AND m.created>=max(COALESCE(nm.muted_until,0),COALESCE(sm.muted_until,0),COALESCE(cm.muted_until,0)) AND m.id>max(COALESCE(nm.silent_through,0),COALESCE(sm.silent_through,0),COALESCE(cm.silent_through,0)) THEN m.id END) AS alert_id FROM notifications n JOIN messages m ON m.id=n.message_id LEFT JOIN notification_mutes nm ON nm.user_id=n.user_id AND nm.channel_id=m.channel_id LEFT JOIN channels mc ON mc.id=m.channel_id LEFT JOIN channels mp ON mp.id=mc.parent_id LEFT JOIN category_notification_mutes cm ON cm.user_id=n.user_id AND cm.server_id=mc.server_id AND cm.category=COALESCE(mp.category,mc.category) LEFT JOIN server_notification_mutes sm ON sm.user_id=n.user_id AND sm.server_id=mc.server_id LEFT JOIN server_notification_preferences np ON np.user_id=n.user_id AND np.server_id=mc.server_id WHERE n.user_id=? AND n.seen=0 AND m.deleted=0 AND NOT EXISTS(SELECT 1 FROM blocks b WHERE (b.user_id=? AND b.target=m.user_id) OR (b.target=? AND b.user_id=m.user_id)) GROUP BY m.channel_id',[uid(),uid(),uid()])->fetchAll() as $r)if(isset($visible[$r['channel_id']]))$result[$r['channel_id']]=['total'=>(int)$r['total'],'first_id'=>(int)$r['first_id'],'last_id'=>(int)$r['last_id'],'alert_id'=>(int)($r['alert_id']??0)];return $result;
}
function reaction_channel(int $id): array {
 $msg=one('SELECT * FROM messages WHERE id=? AND deleted=0',[$id]);if(!$msg)fail(404,'Message not found.');$c=channel($msg['channel_id']);
  if($c['server_id']){if(member($c['server_id'])['muted_until']>time())fail(403,'You cannot change reactions while timed out.');if($c['parent_id']&&($c['archived']||($c['auto_archive_minutes']>0&&$c['thread_activity']+$c['auto_archive_minutes']*60<=time())))fail(403,'Reopen this thread before changing reactions.');}else channel($c['id'],true);
 return $c;
}
function react_message(int $id,array $c,array $in): void {
 // Re-read message and access after taking the write lock; keep the old signature for staged deployment.
 $emoji=$in['emoji']??'❤️';if(!is_string($emoji)||strlen($emoji)>50)fail(400,'Invalid reaction.');if(array_key_exists('selected',$in)&&!is_bool($in['selected']))fail(400,'Invalid reaction state.');
 transaction(function()use($id,$in,$emoji){
  $c=reaction_channel($id);
  $exists=!!one('SELECT 1 FROM reactions WHERE message_id=? AND user_id=? AND emoji=?',[$id,uid(),$emoji]);$add=$in['selected']??!$exists;if(!$add){sql('DELETE FROM reactions WHERE message_id=? AND user_id=? AND emoji=?',[$id,uid(),$emoji]);return;}if($exists)return;
  if(!one('SELECT 1 FROM reactions WHERE message_id=? AND emoji=?',[$id,$emoji])&&!channel_capabilities($c)['add_reactions'])fail(403,'You need Add Reactions to start a new emoji reaction.');
  if(!in_array($emoji,['😀','😃','😂','😊','😍','🤔','😢','😴','😮','👋','👋🏻','👋🏼','👋🏽','👋🏾','👋🏿','👍','👍🏻','👍🏼','👍🏽','👍🏾','👍🏿','👎','👎🏻','👎🏼','👎🏽','👎🏾','👎🏿','👏','👏🏻','👏🏼','👏🏽','👏🏾','👏🏿','🙌','🙌🏻','🙌🏼','🙌🏽','🙌🏾','🙌🏿','🙏','🙏🏻','🙏🏼','🙏🏽','🙏🏾','🙏🏿','💪','💪🏻','💪🏼','💪🏽','💪🏾','💪🏿','🤝','🤝🏻','🤝🏼','🤝🏽','🤝🏾','🤝🏿','👀','🐶','🐱','🐼','🦊','🌸','🌳','☀️','🌈','🍎','🍕','🍔','🍟','🍜','🍰','☕','🧋','⚽','🏀','🎮','🎲','🎨','🎸','🎬','🏆','💻','📱','💡','📚','🔑','🎁','🚀','📷','❤️','💜','✅','❌','⚠️','⭐','🔥','🎉'],true)){
   if(!preg_match('/^custom:([a-f0-9]{32})$/',$emoji,$match)||!$c['server_id']||!one("SELECT 1 FROM media WHERE id=? AND server_id=? AND slot LIKE 'emoji:%'",[$match[1],$c['server_id']]))fail(400,'Choose a reaction from this server.');
  }
  if(sql('SELECT count(*) FROM reactions WHERE message_id=? AND user_id=?',[$id,uid()])->fetchColumn()>=10)fail(409,'Use up to 10 reactions per message.');if(!one('SELECT 1 FROM reactions WHERE message_id=? AND emoji=?',[$id,$emoji])&&sql('SELECT count(DISTINCT emoji) FROM reactions WHERE message_id=?',[$id])->fetchColumn()>=20)fail(409,'This message already has 20 different reactions.');
  sql('INSERT INTO reactions VALUES(?,?,?)',[$id,uid(),$emoji]);
 });
}
function notification_actions(string $action,array $in): array {
 switch($action){
 case 'category_notification_preference':
  $sid=text_field($in,'server_id',32,32);$category=text_field($in,'category',1,40);$mode=$in['mode']??null;if(!is_string($mode)||!in_array($mode,['all','mentions','muted','inherit'],true))fail(400,'Choose a notification setting.');
  return transaction(function()use($sid,$category,$mode,$in){member($sid);if(array_key_exists('category_id',$in)){$id=text_field($in,'category_id',32,32);if(!one('SELECT 1 FROM explicit_categories WHERE server_id=? AND name=? AND id=?',[$sid,$category,$id]))fail(409,'This category changed. Reopen its notification settings.');}$visible=array_column(server_categories($sid),'name');if(!in_array($category,$visible,true))fail(403,'Choose a category you can view.');
   $mute=save_notification_mute($sid,$in,false,$category);if($mode==='inherit')sql('DELETE FROM category_notification_preferences WHERE user_id=? AND server_id=? AND category=?',[uid(),$sid,$category]);else sql('INSERT INTO category_notification_preferences VALUES(?,?,?,?) ON CONFLICT(user_id,server_id,category) DO UPDATE SET mode=excluded.mode',[uid(),$sid,$category,$mode]);return ['ok'=>true,'mode'=>$mode==='inherit'?null:$mode]+notification_mute_fields($mute);
  });
 case 'server_notification_preference':
  $id=text_field($in,'server_id',32,32);$mode=$in['mode']??null;if(!is_string($mode)||!in_array($mode,['all','mentions','muted'],true))fail(400,'Choose a notification setting.');
  if(array_key_exists('suppress_roles',$in)&&!is_bool($in['suppress_roles']))fail(400,'Choose whether to suppress role alerts.');
  if(array_key_exists('suppress_everyone',$in)&&!is_bool($in['suppress_everyone']))fail(400,'Choose whether to suppress everyone alerts.');
  return transaction(function()use($id,$mode,$in){
   member($id);
   $previous=one('SELECT suppress_roles,suppress_everyone FROM server_notification_preferences WHERE user_id=? AND server_id=?',[uid(),$id]);
   $roles=$in['suppress_roles']??(bool)($previous['suppress_roles']??false);
   $everyone=$in['suppress_everyone']??(bool)($previous['suppress_everyone']??false);
   $mute=save_notification_mute($id,$in,true);
   sql('INSERT INTO server_notification_preferences(user_id,server_id,mode,suppress_roles,suppress_everyone) VALUES(?,?,?,?,?) ON CONFLICT(user_id,server_id) DO UPDATE SET mode=excluded.mode,suppress_roles=excluded.suppress_roles,suppress_everyone=excluded.suppress_everyone',[uid(),$id,$mode,(int)$roles,(int)$everyone]);
   return ['ok'=>true,'mode'=>$mode,'suppress_roles'=>$roles,'suppress_everyone'=>$everyone]+notification_mute_fields($mute);
  });
 case 'notification_preference':
  $id=text_field($in,'channel_id',32,32);$mode=$in['mode']??null;if(!is_string($mode)||!in_array($mode,['all','mentions','muted','inherit','category'],true))fail(400,'Choose a notification setting.');
  return transaction(function()use($id,$mode,$in){$c=channel($id);if(in_array($mode,['inherit','category'],true)&&!$c['server_id'])fail(400,'Direct messages have independent notification settings.');$mute=save_notification_mute($id,$in);
   sql('INSERT INTO notification_preferences(user_id,channel_id,mode) VALUES(?,?,?) ON CONFLICT(user_id,channel_id) DO UPDATE SET mode=excluded.mode',[uid(),$id,$mode]);return ['ok'=>true,'mode'=>$mode]+notification_mute_fields($mute);
  });
 case 'inbox':$rows=inbox_rows(($in['unread']??false)===true);return ['items'=>array_slice($rows,0,100),'unread'=>count(array_filter($rows,fn($r)=>!$r['seen'])),'limited'=>count($rows)>100];
 case 'inbox_read':
  if(($in['all']??false)===true)sql('UPDATE notifications SET seen=1 WHERE user_id=?',[uid()]);else{$id=positive_id($in['message_id']??null);sql('UPDATE notifications SET seen=1 WHERE user_id=? AND message_id=?',[uid(),$id]);}return ['ok'=>true];
 case 'mention_members':
  $c=channel(text_field($in,'channel_id',32,32));$people=$c['server_id']?sql('SELECT u.* FROM users u JOIN members m ON m.user_id=u.id WHERE m.server_id=? ORDER BY u.name',[$c['server_id']])->fetchAll():sql('SELECT u.* FROM users u JOIN channel_members m ON m.user_id=u.id WHERE m.channel_id=? ORDER BY u.name',[$c['id']])->fetchAll();
  $permissions=$c['server_id']?permissions_for($c['server_id']):[];
  return ['everyone'=>in_array('mention_everyone',$permissions,true),'roles'=>in_array('mention_roles',$permissions,true)?sql('SELECT id,name FROM roles WHERE server_id=? ORDER BY position DESC,id',[$c['server_id']])->fetchAll():[],'members'=>array_values(array_map('public_user',array_filter($people,fn($p)=>(int)$p['id']!==uid()&&!blocked((int)$p['id'])&&can_view_as($c['id'],(int)$p['id']))))];
 case 'reaction_remove':
  $id=positive_id($in['message_id']??null);$scope=$in['scope']??null;if(!in_array($scope,['user','emoji','all'],true))fail(400,'Choose a reaction removal scope.');
  $emoji=null;$target=null;if($scope!=='all'){$emoji=$in['emoji']??null;if(!is_string($emoji)||$emoji===''||strlen($emoji)>50)fail(400,'Invalid reaction.');}elseif(array_key_exists('emoji',$in))fail(400,'All reactions does not accept an emoji.');
  if($scope==='user')$target=positive_id($in['user_id']??null);elseif(array_key_exists('user_id',$in))fail(400,'Choose the user removal scope.');
  rate('message-action:'.uid(),60,60);
  return transaction(function()use($id,$scope,$emoji,$target){
   $c=reaction_channel($id);if(!$c['server_id'])fail(403,'Reaction moderation is only available in servers.');require_permission($c['server_id'],'manage_messages');
   $query='DELETE FROM reactions WHERE message_id=?';$args=[$id];if($scope!=='all'){$query.=' AND emoji=?';$args[]=$emoji;}if($scope==='user'){$query.=' AND user_id=?';$args[]=$target;}sql($query,$args);return ['ok'=>true];
  });
 case 'pin_list':
  $cid=text_field($in,'channel_id',32,32);channel($cid);$where='m.channel_id=? AND m.pinned=1 AND m.deleted=0 AND NOT EXISTS(SELECT 1 FROM blocks b WHERE b.user_id=? AND b.target=m.user_id)';$args=[$cid,uid()];
  if(array_key_exists('before',$in)){$cursor=$in['before'];if(!is_array($cursor)||count($cursor)!==2||!array_key_exists('at',$cursor)||!array_key_exists('id',$cursor)||!is_int($cursor['at'])||$cursor['at']<0||$cursor['at']>8640000000000000)fail(400,'Invalid pin cursor.');$id=positive_id($cursor['id']);$where.=' AND (m.pinned_at<? OR (m.pinned_at=? AND m.id<?))';array_push($args,$cursor['at'],$cursor['at'],$id);}
  $rows=sql("SELECT m.id,m.user_id,substr(m.body,1,300) AS body,m.created,m.pinned_at,u.name,u.username,(SELECT count(*) FROM attachments a WHERE a.message_id=m.id) AS attachments FROM messages m JOIN users u ON u.id=m.user_id WHERE $where ORDER BY m.pinned_at DESC,m.id DESC LIMIT 51",$args)->fetchAll();$more=count($rows)>50;$rows=array_slice($rows,0,50);$last=end($rows);return ['pins'=>$rows,'next'=>$more?['at'=>(int)$last['pinned_at'],'id'=>(int)$last['id']]:null];
 case 'reaction_members':
  $id=positive_id($in['message_id']??null);$emoji=text_field($in,'emoji',1,50);if(strlen($emoji)>50)fail(400,'Invalid reaction.');$after=$in['after']??0;if(!is_int($after)||$after<0||$after>9007199254740991)fail(400,'Invalid cursor.');
  $m=one('SELECT channel_id,user_id FROM messages WHERE id=? AND deleted=0',[$id]);if(!$m)fail(404,'Message no longer available.');$c=channel($m['channel_id']);if(blocked((int)$m['user_id']))fail(403,'This message is unavailable.');
  $rows=sql("SELECT u.id,u.username,u.name,COALESCE(NULLIF(mb.server_avatar,''),u.avatar) AS avatar,COALESCE(mb.nickname,'') AS nickname FROM reactions r JOIN users u ON u.id=r.user_id LEFT JOIN members mb ON mb.user_id=u.id AND mb.server_id=? WHERE r.message_id=? AND r.emoji=? AND u.id>? AND NOT EXISTS(SELECT 1 FROM blocks b WHERE (b.user_id=? AND b.target=u.id) OR (b.target=? AND b.user_id=u.id)) ORDER BY u.id LIMIT 51",[$c['server_id'],$id,$emoji,$after,uid(),uid()])->fetchAll();
  $more=count($rows)>50;$rows=array_slice($rows,0,50);$last=end($rows);$people=[];foreach($rows as $row)if(can_view_as($c['id'],(int)$row['id'])){$row['id']=(int)$row['id'];$people[]=$row;}
  return ['members'=>$people,'next'=>$more?(int)$last['id']:null];
 case 'message_context':
  $id=positive_id($in['message_id']??null);$m=one('SELECT channel_id FROM messages WHERE id=? AND deleted=0',[$id]);if(!$m)fail(404,'Message no longer available.');$c=channel($m['channel_id']);$after=sql('SELECT id FROM messages WHERE channel_id=? AND deleted=0 AND id>=? ORDER BY id LIMIT 30',[$c['id'],$id])->fetchAll();$last=end($after);return ['channel'=>$c,'messages'=>messages_for($c['id'],(int)$last['id']+1)];
 }fail(404,'Unknown notification action.');
}
