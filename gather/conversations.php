<?php
declare(strict_types=1);
function migrate_conversations(): void {
 global $db;if((int)$db->query('PRAGMA user_version')->fetchColumn()>=4)return;
 transaction(function()use($db){if((int)$db->query('PRAGMA user_version')->fetchColumn()>=4)return;
  $db->exec("ALTER TABLE channels ADD COLUMN group_owner INTEGER REFERENCES users(id);
   ALTER TABLE channels ADD COLUMN parent_id TEXT REFERENCES channels(id) ON DELETE CASCADE;
   ALTER TABLE channels ADD COLUMN root_message INTEGER REFERENCES messages(id) ON DELETE SET NULL;
   ALTER TABLE channels ADD COLUMN archived INTEGER NOT NULL DEFAULT 0;
   CREATE UNIQUE INDEX thread_root ON channels(root_message) WHERE root_message IS NOT NULL;
   ALTER TABLE messages ADD COLUMN reply_to INTEGER REFERENCES messages(id) ON DELETE SET NULL;
   ALTER TABLE calls ADD COLUMN media_version INTEGER NOT NULL DEFAULT 1;
   CREATE TABLE attachments(id TEXT PRIMARY KEY,channel_id TEXT NOT NULL REFERENCES channels(id) ON DELETE CASCADE,user_id INTEGER NOT NULL REFERENCES users(id),message_id INTEGER REFERENCES messages(id) ON DELETE CASCADE,name TEXT NOT NULL,size INTEGER NOT NULL,sha256 TEXT NOT NULL,data TEXT NOT NULL,created INTEGER NOT NULL);
   CREATE INDEX attachment_message ON attachments(message_id);
   CREATE TABLE channel_overrides(channel_id TEXT NOT NULL REFERENCES channels(id) ON DELETE CASCADE,kind TEXT NOT NULL,subject TEXT NOT NULL,permission TEXT NOT NULL,value INTEGER NOT NULL,PRIMARY KEY(channel_id,kind,subject,permission));
   PRAGMA user_version=4;");
 });
}
function channel_capabilities(array $c): array {
 if($c['parent_id'])return channel_capabilities(one('SELECT * FROM channels WHERE id=?',[$c['parent_id']]));
 if(!$c['server_id'])return ['view'=>true,'send'=>true,'connect'=>true,'attach'=>true];
 $m=member($c['server_id']);if($m['role']==='owner')return ['view'=>true,'send'=>true,'connect'=>true,'attach'=>true];
 $roles=array_column(sql('SELECT role_id FROM member_roles WHERE server_id=? AND user_id=?',[$c['server_id'],uid()])->fetchAll(),'role_id');
 $visible=!$c['private']||one('SELECT 1 FROM channel_members WHERE channel_id=? AND user_id=?',[$c['id'],uid()])||one('SELECT 1 FROM channel_roles cr JOIN member_roles mr ON mr.role_id=cr.role_id WHERE cr.channel_id=? AND mr.user_id=?',[$c['id'],uid()]);
 $p=['view'=>(bool)$visible,'send'=>!$c['readonly']||in_array('manage_messages',permissions_for($c['server_id']),true),'connect'=>true,'attach'=>true];
 $rows=sql('SELECT * FROM channel_overrides WHERE channel_id=?',[$c['id']])->fetchAll();
 // Discord-style precedence: everyone, combined roles (allow wins), then member.
 foreach(['everyone','role','member'] as $kind){$values=[];foreach($rows as $r){if($r['kind']!==$kind||($kind==='role'&&!in_array($r['subject'],$roles,true))||($kind==='member'&&$r['subject']!==(string)uid()))continue;$key=$r['permission'];$values[$key]=max($values[$key]??-1,(int)$r['value']);}foreach($values as $key=>$v)$p[$key]=$v===1;}
 return $p;
}
function conversation_members(string $id): array {return array_map('public_user',sql('SELECT u.* FROM users u JOIN channel_members cm ON cm.user_id=u.id WHERE cm.channel_id=? ORDER BY u.name',[$id])->fetchAll());}
function group_people(array $ids): array {
 if(count($ids)<1||count($ids)>9||count(array_unique($ids,SORT_REGULAR))!==count($ids))fail(400,'Choose 1–9 different friends.');
 foreach($ids as $id){positive_id($id);if($id===uid()||!friends($id)||blocked($id))fail(403,'Group invitations require an accepted, unblocked friendship.');}
 $all=[uid(),...$ids];foreach($all as $a)foreach($all as $b)if(one('SELECT 1 FROM blocks WHERE user_id=? AND target=?',[$a,$b]))fail(403,'These members cannot share a group.');return $all;
}
function conversation_actions(string $action,array $in): array {
 switch($action){
 case 'group_create':
  rate('group-create:'.uid(),10,3600);$name=text_field($in,'name',1,40);$ids=$in['user_ids']??null;if(!is_array($ids))fail(400,'Choose friends.');$all=group_people($ids);
  return transaction(function()use($all,$name){if(sql('SELECT count(*) FROM channels WHERE group_owner=?',[uid()])->fetchColumn()>=30)fail(409,'Group limit reached.');$id=random_id();sql('INSERT INTO channels(id,name,kind,private,created,group_owner) VALUES(?,?,?,?,?,?)',[$id,$name,'text',1,time(),uid()]);foreach($all as $who)sql('INSERT INTO channel_members VALUES(?,?)',[$id,$who]);return ['channel_id'=>$id];});
 case 'group_members':$c=channel(text_field($in,'channel_id',32,32));if($c['server_id'])fail(400,'Choose a conversation.');return ['members'=>conversation_members($c['id'])];
 case 'group_update':case 'group_leave':
  $c=channel(text_field($in,'channel_id',32,32));if(!$c['group_owner'])fail(400,'Choose a group conversation.');
  return transaction(function()use($action,$in,$c){$id=$c['id'];if($action==='group_leave'){
    sql('DELETE FROM channel_members WHERE channel_id=? AND user_id=?',[$id,uid()]);sql('DELETE FROM calls WHERE channel_id=? AND user_id=?',[$id,uid()]);$next=sql('SELECT min(user_id) FROM channel_members WHERE channel_id=?',[$id])->fetchColumn();if(!$next)sql('DELETE FROM channels WHERE id=?',[$id]);elseif((int)$c['group_owner']===uid())sql('UPDATE channels SET group_owner=? WHERE id=?',[$next,$id]);
   }else{if((int)$c['group_owner']!==uid())fail(403,'Only the group owner can edit membership.');$name=text_field($in,'name',1,40);$ids=$in['user_ids']??null;if(!is_array($ids)||count($ids)>9)fail(400,'Choose up to 9 other members.');$old=array_column(conversation_members($id),'id');$all=[uid()];foreach($ids as $who){positive_id($who);if($who===uid()||in_array($who,$all,true))fail(400,'Choose different members.');if(!in_array($who,$old,true)&&(!friends($who)||blocked($who)))fail(403,'New members must be your friends.');$all[]=$who;}foreach($all as $a)foreach($all as $b)if(one('SELECT 1 FROM blocks WHERE user_id=? AND target=?',[$a,$b]))fail(403,'These members cannot share a group.');foreach($old as $who)if(!in_array($who,$all,true)){sql('DELETE FROM calls WHERE channel_id=? AND user_id=?',[$id,$who]);sql('DELETE FROM channel_members WHERE channel_id=? AND user_id=?',[$id,$who]);}foreach($all as $who)sql('INSERT OR IGNORE INTO channel_members VALUES(?,?)',[$id,$who]);sql('UPDATE channels SET name=? WHERE id=?',[$name,$id]);
   }return ['ok'=>true];});
 case 'thread_create':
  $cid=text_field($in,'channel_id',32,32);$root=positive_id($in['message_id']??null);$name=text_field($in,'name',1,32);
  return transaction(function()use($cid,$root,$name){$parent=channel($cid,true);if(!$parent['server_id']||$parent['kind']!=='text'||$parent['parent_id'])fail(400,'Start a thread in a server text channel.');if(!one('SELECT 1 FROM messages WHERE id=? AND channel_id=? AND deleted=0',[$root,$parent['id']]))fail(404,'Message not found.');$old=one('SELECT id FROM channels WHERE root_message=?',[$root]);if($old)return ['channel_id'=>$old['id']];if(sql('SELECT count(*) FROM channels WHERE parent_id=?',[$parent['id']])->fetchColumn()>=20)fail(409,'This channel has reached its 20-thread limit.');$id=random_id();sql('INSERT INTO channels(id,server_id,name,kind,created,parent_id,root_message) VALUES(?,?,?,?,?,?,?)',[$id,$parent['server_id'],$name,'text',time(),$parent['id'],$root]);hierarchy_audit($parent['server_id'],'thread-create',0,thread_audit_label(one('SELECT * FROM channels WHERE id=?',[$id])));return ['channel_id'=>$id];});
 case 'thread_archive':
  $cid=text_field($in,'channel_id',32,32);if(!is_bool($in['archived']??null))fail(400,'Invalid archive state.');$archived=$in['archived'];
  transaction(function()use($cid,$archived){$c=channel($cid);if(!$c['parent_id'])fail(400,'Choose a thread.');require_permission($c['server_id'],'manage_channels');if((bool)$c['archived']===$archived)return;sql('UPDATE channels SET archived=? WHERE id=?',[$archived?1:0,$cid]);audit_changes($c['server_id'],$archived?'thread-archive':'thread-reopen',thread_audit_label($c),['archived'=>(bool)$c['archived']],['archived'=>$archived],['archived'=>'Archived']);});return ['ok'=>true];
 case 'override_list':case 'override_save':
  $c=channel(text_field($in,'channel_id',32,32));if(!$c['server_id']||$c['parent_id'])fail(400,'Threads inherit their parent channel permissions.');require_permission($c['server_id'],'manage_channels');
  if($action==='override_save'){
   $kind=$in['kind']??'';$subject=$in['subject']??'';$values=$in['values']??null;if(!is_string($subject)||!is_array($values)||count($values)!==4)fail(400,'Invalid override.');
   if($kind==='everyone'){if($subject!=='*')fail(400,'Invalid everyone override.');}elseif($kind==='role'){if(!one('SELECT 1 FROM roles WHERE id=? AND server_id=?',[$subject,$c['server_id']]))fail(400,'Choose a server role.');}elseif($kind==='member'){if(!ctype_digit($subject)||!one('SELECT 1 FROM members WHERE user_id=? AND server_id=?',[$subject,$c['server_id']]))fail(400,'Choose a server member.');}else fail(400,'Invalid subject.');
   foreach(['view','send','connect','attach'] as $p)if(!isset($values[$p])||!in_array($values[$p],[-1,0,1],true))fail(400,'Invalid permission.');
   transaction(function()use($c,$kind,$subject,$values){$c=channel($c['id']);require_permission($c['server_id'],'manage_channels');$before=['view'=>0,'send'=>0,'connect'=>0,'attach'=>0];foreach(sql('SELECT permission,value FROM channel_overrides WHERE channel_id=? AND kind=? AND subject=?',[$c['id'],$kind,$subject])->fetchAll() as $row)$before[$row['permission']]=(int)$row['value'];sql('DELETE FROM channel_overrides WHERE channel_id=? AND kind=? AND subject=?',[$c['id'],$kind,$subject]);foreach($values as $p=>$v)if($v!==0)sql('INSERT INTO channel_overrides VALUES(?,?,?,?,?)',[$c['id'],$kind,$subject,$p,$v]);if(audit_changes($c['server_id'],'channel-access',channel_audit_label($c).' · '.$kind.' ['.$subject.'] · -1 deny / 0 inherit / 1 allow',$before,$values,['view'=>'View','send'=>'Send','connect'=>'Connect','attach'=>'Attach']))sql('DELETE FROM calls WHERE channel_id=?',[$c['id']]);});
  }return ['overrides'=>sql('SELECT * FROM channel_overrides WHERE channel_id=?',[$c['id']])->fetchAll()];
 case 'attachment_upload':
  $cid=text_field($in,'channel_id',32,32);$c=channel($cid,true,'attach');if($c['kind']!=='text')fail(400,'Choose a text channel.');channel($cid,true);rate('attachment:'.uid(),20,3600);$name=text_field($in,'name',1,120);
  if(preg_match('/[\\\\\/:*?"<>|\x00-\x1F]/',$name)||str_ends_with($name,'.')||str_ends_with($name,' ')||!preg_match('/\.(png|jpe?g|webp|gif|pdf|txt|csv|md|json|zip|docx|xlsx|pptx|mp3|wav|ogg|mp4|webm)$/i',$name))fail(400,'Unsupported file name or type. Use images, documents, ZIP, audio or video.');
  $encoded=$in['data']??null;if(!is_string($encoded)||strlen($encoded)>5592408)fail(413,'Files must be at most 4 MB.');$bytes=base64_decode($encoded,true);if($bytes===false||base64_encode($bytes)!==$encoded||strlen($bytes)<1||strlen($bytes)>4194304)fail(400,'Invalid file or size.');
  return transaction(function()use($cid,$name,$bytes,$encoded){sql('DELETE FROM attachments WHERE message_id IS NULL AND created<?',[time()-86400]);$size=strlen($bytes);if((int)sql('SELECT COALESCE(sum(size),0) FROM attachments WHERE user_id=?',[uid()])->fetchColumn()+$size>104857600||(int)sql('SELECT COALESCE(sum(size),0) FROM attachments')->fetchColumn()+$size>536870912)fail(409,'Attachment storage is full. Delete old attachments to free space.');$id=random_id();$hash=hash('sha256',$bytes);sql('INSERT INTO attachments VALUES(?,?,?,?,?,?,?,?,?)',[$id,$cid,uid(),null,$name,$size,$hash,$encoded,time()]);return ['id'=>$id,'name'=>$name,'size'=>$size,'sha256'=>$hash];});
 case 'attachment_get':case 'attachment_discard':
  $id=text_field($in,'id',32,32);$a=one('SELECT * FROM attachments WHERE id=?',[$id]);if(!$a)fail(404,'Attachment not found.');channel($a['channel_id']);if($a['message_id']){if(!one('SELECT 1 FROM messages WHERE id=? AND deleted=0',[$a['message_id']]))fail(404,'Attachment no longer available.');}elseif((int)$a['user_id']!==uid())fail(403,'This attachment has not been shared.');
  if($action==='attachment_discard'){if((int)$a['user_id']!==uid()||$a['message_id'])fail(403,'Only your unsent attachments can be discarded.');sql('DELETE FROM attachments WHERE id=?',[$id]);return ['ok'=>true];}return array_intersect_key($a,array_flip(['name','size','sha256','data']));
 }fail(404,'Unknown conversation action.');
}
function send_message(array $in): array {
 $cid=text_field($in,'channel_id',32,32);$c=channel($cid,true);if($c['kind']!=='text')fail(400,'Use a text channel.');rate('send:'.uid(),30,60);$ids=$in['attachments']??[];if(!is_array($ids)||count($ids)>3||count(array_unique($ids,SORT_REGULAR))!==count($ids))fail(400,'Attach up to 3 files.');foreach($ids as $id)if(!is_string($id)||!preg_match('/^[a-f0-9]{32}$/',$id))fail(400,'Invalid attachment.');$body=text_field($in,'body',$ids?0:1,4000);$client=text_field($in,'client_id',16,64);$reply=isset($in['reply_to'])?positive_id($in['reply_to']):null;
 return transaction(function()use($cid,$c,$ids,$body,$client,$reply){$old=one('SELECT id,channel_id FROM messages WHERE user_id=? AND client_id=?',[uid(),$client]);if($old){if($old['channel_id']!==$cid)fail(409,'Message retry belongs to another channel.');return ['id'=>(int)$old['id']];}
  if($reply&&!one('SELECT 1 FROM messages WHERE id=? AND channel_id=? AND deleted=0',[$reply,$cid]))fail(400,'Reply must reference a message in this conversation.');
  if($c['server_id']&&$c['slowmode']>0&&!in_array('manage_messages',permissions_for($c['server_id']),true)&&one('SELECT 1 FROM messages WHERE channel_id=? AND user_id=? AND created>?',[$cid,uid(),time()-$c['slowmode']]))fail(429,'Slow mode is active. Please wait before sending another message.');
  if($ids)channel($cid,true,'attach');foreach($ids as $id)if(!one('SELECT 1 FROM attachments WHERE id=? AND user_id=? AND channel_id=? AND message_id IS NULL',[$id,uid(),$cid]))fail(403,'Choose your own unsent attachment from this conversation.');
  sql('INSERT INTO messages(channel_id,user_id,body,created,client_id,reply_to) VALUES(?,?,?,?,?,?)',[$cid,uid(),$body,time(),$client,$reply]);global $db;$id=(int)$db->lastInsertId();foreach($ids as $a)sql('UPDATE attachments SET message_id=? WHERE id=?',[$id,$a]);sql('DELETE FROM typing WHERE user_id=? AND channel_id=?',[uid(),$cid]);notify_message($id,$c,$body,$reply);return ['id'=>$id];
 });
}
