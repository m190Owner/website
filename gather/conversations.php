<?php
declare(strict_types=1);
function migrate_thread_archive_default(): void {
 global $db;if((int)$db->query('PRAGMA user_version')->fetchColumn()>=38)return;
 transaction(function()use($db){if((int)$db->query('PRAGMA user_version')->fetchColumn()>=38)return;$db->exec('ALTER TABLE channels ADD COLUMN default_thread_auto_archive_minutes INTEGER NOT NULL DEFAULT 0; PRAGMA user_version=38;');});
}
function migrate_thread_auto_archive(): void {
 global $db;if((int)$db->query('PRAGMA user_version')->fetchColumn()>=37)return;
 transaction(function()use($db){if((int)$db->query('PRAGMA user_version')->fetchColumn()>=37)return;
  $db->exec("ALTER TABLE channels ADD COLUMN auto_archive_minutes INTEGER NOT NULL DEFAULT 0; ALTER TABLE channels ADD COLUMN thread_activity INTEGER NOT NULL DEFAULT 0;
   CREATE INDEX thread_expiration ON channels(thread_activity+auto_archive_minutes*60) WHERE parent_id IS NOT NULL AND archived=0 AND auto_archive_minutes>0;");
  sql('UPDATE channels SET thread_activity=? WHERE parent_id IS NOT NULL',[time()]);$db->exec('PRAGMA user_version=37;');
 });
}
function archive_due_threads(?string $id=null): void {
 $now=time();$where='parent_id IS NOT NULL AND archived=0 AND auto_archive_minutes>0 AND thread_activity+auto_archive_minutes*60<=CAST(? AS INTEGER)';
 if(!one('SELECT id FROM channels WHERE '.$where.' LIMIT 1',[$now]))return;
 transaction(function()use($now,$where,$id){
  // shortcut: request-driven batches bound shared-host work; use a worker if exact-time background archiving is needed.
  $rows=sql('SELECT * FROM channels WHERE '.$where.' ORDER BY thread_activity+auto_archive_minutes*60,id LIMIT 50',[$now])->fetchAll();
  if($id&&!in_array($id,array_column($rows,'id'),true)){$target=one('SELECT * FROM channels WHERE id=? AND '.$where,[$id,$now]);if($target)$rows[]=$target;}
  foreach($rows as $c){sql('UPDATE channels SET archived=1,thread_revision=thread_revision+1 WHERE id=?',[$c['id']]);sql('INSERT INTO audit(actor,server_id,action,target,created,detail) VALUES(0,?,?,0,?,?)',[$c['server_id'],'thread-auto-archive',$now,thread_audit_label($c)."\nAutomatically archived after ".$c['auto_archive_minutes'].' minutes without a new message.']);}
 });
}
function migrate_thread_lifecycle(): void {
 global $db;if((int)$db->query('PRAGMA user_version')->fetchColumn()>=36)return;
 transaction(function()use($db){if((int)$db->query('PRAGMA user_version')->fetchColumn()>=36)return;
  $db->exec("ALTER TABLE channels ADD COLUMN locked INTEGER NOT NULL DEFAULT 0; ALTER TABLE channels ADD COLUMN thread_revision INTEGER NOT NULL DEFAULT 0;
   UPDATE channels SET locked=1 WHERE parent_id IS NOT NULL AND archived=1; PRAGMA user_version=36;");
 });
}
function check_thread_revision(array $c,array $in): bool {
 if(!array_key_exists('thread_version',$in)){if(array_key_exists('locked',$in)||array_key_exists('auto_archive_minutes',$in)||array_key_exists('expected_revision',$in))fail(400,'Use a supported thread settings version.');return false;}
 if($in['thread_version']!==1||!is_int($in['expected_revision']??null)||$in['expected_revision']<0)fail(400,'Invalid thread settings version or revision.');
 if($in['expected_revision']!==(int)$c['thread_revision'])fail(409,'This thread changed. Refresh the conversation and reopen its settings before trying again.');return true;
}
function migrate_thread_slowmode(): void {
 global $db;if((int)$db->query('PRAGMA user_version')->fetchColumn()>=34)return;
 transaction(function()use($db){if((int)$db->query('PRAGMA user_version')->fetchColumn()>=34)return;
  $db->exec("ALTER TABLE channels ADD COLUMN default_thread_slowmode INTEGER NOT NULL DEFAULT 0;
   UPDATE channels SET default_thread_slowmode=slowmode WHERE server_id IS NOT NULL AND parent_id IS NULL AND kind='text';
   UPDATE channels SET slowmode=COALESCE((SELECT p.slowmode FROM channels p WHERE p.id=channels.parent_id),slowmode) WHERE parent_id IS NOT NULL;
   PRAGMA user_version=34;");
 });
}
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
 if(!$c['server_id'])return ['view'=>true,'send'=>true,'connect'=>true,'attach'=>true,'pin_messages'=>true,'bypass_slowmode'=>true,'manage_threads'=>false,'create_public_threads'=>false];
 $m=member($c['server_id']);if($m['role']==='owner')return ['view'=>true,'send'=>true,'connect'=>true,'attach'=>true,'pin_messages'=>true,'bypass_slowmode'=>true,'manage_threads'=>true,'create_public_threads'=>true];
 $roles=array_column(sql('SELECT role_id FROM member_roles WHERE server_id=? AND user_id=?',[$c['server_id'],uid()])->fetchAll(),'role_id');
 $visible=!$c['private']||one('SELECT 1 FROM channel_members WHERE channel_id=? AND user_id=?',[$c['id'],uid()])||one('SELECT 1 FROM channel_roles cr JOIN member_roles mr ON mr.role_id=cr.role_id WHERE cr.channel_id=? AND mr.user_id=?',[$c['id'],uid()]);
 $p=['view'=>(bool)$visible,'send'=>!$c['readonly']||in_array('manage_messages',permissions_for($c['server_id']),true),'connect'=>true,'attach'=>true,'pin_messages'=>in_array('pin_messages',permissions_for($c['server_id']),true),'bypass_slowmode'=>in_array('bypass_slowmode',permissions_for($c['server_id']),true),'manage_threads'=>in_array('manage_threads',permissions_for($c['server_id']),true),'create_public_threads'=>true];
 $rows=sql('SELECT * FROM channel_overrides WHERE channel_id=?',[$c['id']])->fetchAll();
 // Discord-style precedence: everyone, combined roles (allow wins), then member.
 foreach(['everyone','role','member'] as $kind){$values=[];foreach($rows as $r){if($r['kind']!==$kind||($kind==='role'&&!in_array($r['subject'],$roles,true))||($kind==='member'&&$r['subject']!==(string)uid()))continue;$key=$r['permission'];$values[$key]=max($values[$key]??-1,(int)$r['value']);}foreach($values as $key=>$v)$p[$key]=$v===1;}
 return $p;
}
function migrate_category_overrides(): void {
 global $db;if((int)$db->query('PRAGMA user_version')->fetchColumn()>=27)return;
 transaction(function()use($db){if((int)$db->query('PRAGMA user_version')->fetchColumn()>=27)return;
  $db->exec("CREATE TABLE category_overrides(category_id TEXT NOT NULL REFERENCES explicit_categories(id) ON DELETE CASCADE,kind TEXT NOT NULL,subject TEXT NOT NULL,permission TEXT NOT NULL,value INTEGER NOT NULL,PRIMARY KEY(category_id,kind,subject,permission));
   CREATE TRIGGER category_override_role_delete AFTER DELETE ON roles BEGIN DELETE FROM category_overrides WHERE kind='role' AND subject=OLD.id; END;
   CREATE TRIGGER category_override_member_delete AFTER DELETE ON members BEGIN DELETE FROM category_overrides WHERE kind='member' AND subject=CAST(OLD.user_id AS TEXT) AND category_id IN (SELECT id FROM explicit_categories WHERE server_id=OLD.server_id); END;
   PRAGMA user_version=27;");
 });
}
function migrate_category_sync(): void {
 global $db;if((int)$db->query('PRAGMA user_version')->fetchColumn()>=28)return;
 transaction(function()use($db){if((int)$db->query('PRAGMA user_version')->fetchColumn()>=28)return;
  $db->exec('CREATE TABLE channel_category_sync(channel_id TEXT PRIMARY KEY REFERENCES channels(id) ON DELETE CASCADE,category_id TEXT NOT NULL REFERENCES explicit_categories(id) ON DELETE CASCADE); CREATE INDEX category_synced_channels ON channel_category_sync(category_id); CREATE TRIGGER synced_member_override_delete AFTER DELETE ON members BEGIN DELETE FROM channel_overrides WHERE kind=\'member\' AND subject=CAST(OLD.user_id AS TEXT) AND channel_id IN (SELECT s.channel_id FROM channel_category_sync s JOIN explicit_categories e ON e.id=s.category_id WHERE e.server_id=OLD.server_id); END; PRAGMA user_version=28;');
 });
}
function set_channel_sync(array $c,?string $categoryId): void {
 $old=one('SELECT category_id FROM channel_category_sync WHERE channel_id=?',[$c['id']])['category_id']??null;
 if($old===$categoryId)return;
 sql('DELETE FROM channel_category_sync WHERE channel_id=?',[$c['id']]);
 if($categoryId!==null)sql('INSERT INTO channel_category_sync VALUES(?,?)',[$c['id'],$categoryId]);
 audit_changes($c['server_id'],'channel-sync',channel_audit_label($c),['category'=>$old??'Independent'],['category'=>$categoryId??'Independent'],['category'=>'Synced category ID']);
}
function channel_sync_preview(array $c): array {
 $category=one('SELECT id,name FROM explicit_categories WHERE server_id=? AND name=?',[$c['server_id'],$c['category']]);
 $current=sql('SELECT kind,subject,permission,value FROM channel_overrides WHERE channel_id=? ORDER BY kind,subject,permission',[$c['id']])->fetchAll();
 $target=$category?sql('SELECT kind,subject,permission,value FROM category_overrides WHERE category_id=? ORDER BY kind,subject,permission',[$category['id']])->fetchAll():[];
 $synced=one('SELECT category_id FROM channel_category_sync WHERE channel_id=?',[$c['id']])['category_id']??null;
 return ['category'=>$category,'current'=>$current,'target'=>$target,'synced'=>$synced,'snapshot'=>hash('sha256',json_encode([uid(),$c['id'],channel_audit_snapshot($c),$category,$current,$target,$synced]))];
}
function check_override_version(array $in,array ...$sets): void {
 $version=$in['override_version']??1;if(!in_array($version,[1,2,3,4,5],true))fail(400,'Invalid override version.');
 foreach($sets as $rows)foreach($rows as $row){if($version<2&&$row['permission']==='pin_messages')fail(409,'Update Gather before changing overrides that include Pin Messages.');if($version<3&&$row['permission']==='bypass_slowmode')fail(409,'Update Gather before changing overrides that include Bypass Slowmode.');if($version<4&&$row['permission']==='manage_threads')fail(409,'Update Gather before changing overrides that include Manage Threads.');if($version<5&&$row['permission']==='create_public_threads')fail(409,'Update Gather before changing thread creation overrides.');}
}
function override_input(string $sid,array $in): array {
 check_override_version($in);$keys=['view','send','connect','attach'];if(($in['override_version']??1)>=2)$keys[]='pin_messages';if(($in['override_version']??1)>=3)$keys[]='bypass_slowmode';if(($in['override_version']??1)>=4)$keys[]='manage_threads';if(($in['override_version']??1)>=5)$keys[]='create_public_threads';
 $kind=$in['kind']??'';$subject=$in['subject']??'';$values=$in['values']??null;
 if(!is_string($subject)||!is_array($values)||count($values)!==count($keys))fail(400,'Invalid override.');
 if($kind==='everyone'){if($subject!=='*')fail(400,'Invalid everyone override.');}
 elseif($kind==='role'){if(!one('SELECT 1 FROM roles WHERE id=? AND server_id=?',[$subject,$sid]))fail(400,'Choose a server role.');}
 elseif($kind==='member'){if(!ctype_digit($subject)||!one('SELECT 1 FROM members WHERE user_id=? AND server_id=?',[$subject,$sid]))fail(400,'Choose a server member.');}
 else fail(400,'Invalid subject.');
 foreach($keys as $p)if(!isset($values[$p])||!in_array($values[$p],[-1,0,1],true))fail(400,'Invalid permission.');
 return [$kind,$subject,$values+['pin_messages'=>0,'bypass_slowmode'=>0,'manage_threads'=>0,'create_public_threads'=>0]];
}
function save_channel_override(array $c,string $kind,string $subject,array $values): void {
 $before=['view'=>0,'send'=>0,'connect'=>0,'attach'=>0,'pin_messages'=>0,'bypass_slowmode'=>0,'manage_threads'=>0,'create_public_threads'=>0];
 foreach(sql('SELECT permission,value FROM channel_overrides WHERE channel_id=? AND kind=? AND subject=?',[$c['id'],$kind,$subject])->fetchAll() as $row)$before[$row['permission']]=(int)$row['value'];
 foreach(['pin_messages','bypass_slowmode','manage_threads','create_public_threads'] as $permission)if($values[$permission]>$before[$permission]&&!channel_capabilities($c)[$permission])fail(403,'You cannot broaden a permission you do not have.');
 sql('DELETE FROM channel_overrides WHERE channel_id=? AND kind=? AND subject=?',[$c['id'],$kind,$subject]);
 foreach($values as $p=>$v)if($v!==0)sql('INSERT INTO channel_overrides VALUES(?,?,?,?,?)',[$c['id'],$kind,$subject,$p,$v]);
 if(audit_changes($c['server_id'],'channel-access',channel_audit_label($c).' · '.$kind.' ['.$subject.'] · -1 deny / 0 inherit / 1 allow',$before,$values,['view'=>'View','send'=>'Send','connect'=>'Connect','attach'=>'Attach','pin_messages'=>'Pin messages','bypass_slowmode'=>'Bypass slowmode','manage_threads'=>'Manage threads','create_public_threads'=>'Create public threads']))sql('DELETE FROM calls WHERE channel_id=?',[$c['id']]);
}
function category_permission_snapshot(string $sid,string $name,string $id): array {
 require_permission($sid,'manage_channels');
 if(!one('SELECT 1 FROM explicit_categories WHERE server_id=? AND name=? AND id=?',[$sid,$name,$id]))fail(409,'This category changed. Reopen its permissions.');
 $channels=sql('SELECT * FROM channels WHERE server_id=? AND category=? AND parent_id IS NULL ORDER BY id',[$sid,$name])->fetchAll();$data=[];
 foreach($channels as &$c){channel($c['id']);$c['synced']=(bool)one('SELECT 1 FROM channel_category_sync WHERE channel_id=? AND category_id=?',[$c['id'],$id]);$data[]=['id'=>$c['id'],'kind'=>$c['kind'],'synced'=>$c['synced']]+channel_audit_snapshot($c)+['overrides'=>sql('SELECT kind,subject,permission,value FROM channel_overrides WHERE channel_id=? ORDER BY kind,subject,permission',[$c['id']])->fetchAll()];}unset($c);
 $overrides=sql('SELECT kind,subject,permission,value FROM category_overrides WHERE category_id=? ORDER BY kind,subject,permission',[$id])->fetchAll();
 return ['channels'=>$channels,'overrides'=>$overrides,'snapshot'=>hash('sha256',json_encode([uid(),$sid,$name,$id,$data,$overrides]))];
}
function conversation_members(string $id): array {return array_map('public_user',sql('SELECT u.* FROM users u JOIN channel_members cm ON cm.user_id=u.id WHERE cm.channel_id=? ORDER BY u.name',[$id])->fetchAll());}
function group_people(array $ids): array {
 if(count($ids)<1||count($ids)>9||count(array_unique($ids,SORT_REGULAR))!==count($ids))fail(400,'Choose 1–9 different friends.');
 foreach($ids as $id){positive_id($id);if($id===uid()||!friends($id)||blocked($id))fail(403,'Group invitations require an accepted, unblocked friendship.');}
 $all=[uid(),...$ids];foreach($all as $a)foreach($all as $b)if(one('SELECT 1 FROM blocks WHERE user_id=? AND target=?',[$a,$b]))fail(403,'These members cannot share a group.');return $all;
}
function channel_move_preview(array $c,string $name,string $id): array {
 $destination=category_permission_snapshot($c['server_id'],$name,$id);$source=channel_sync_preview($c);
 return ['category'=>['id'=>$id,'name'=>$name],'current'=>$source['current'],'target'=>$destination['overrides'],'snapshot'=>hash('sha256',json_encode([$source['snapshot'],channel_order_snapshot($c),$destination['snapshot']]))];
}
function apply_channel_sync(array $c,array $preview,array $in): void {
 check_override_version($in,$preview['current'],$preview['target']);
 if(!$preview['category'])fail(409,'Create or rename an explicit category first.');
 $subjects=[];foreach($preview['current'] as $row)$subjects[$row['kind'].':'.$row['subject']]=['view'=>0,'send'=>0,'connect'=>0,'attach'=>0,'pin_messages'=>0,'bypass_slowmode'=>0,'manage_threads'=>0,'create_public_threads'=>0];
 foreach($preview['target'] as $row){$key=$row['kind'].':'.$row['subject'];$subjects[$key]??=['view'=>0,'send'=>0,'connect'=>0,'attach'=>0,'pin_messages'=>0,'bypass_slowmode'=>0,'manage_threads'=>0,'create_public_threads'=>0];$subjects[$key][$row['permission']]=(int)$row['value'];}
 $caps=channel_capabilities($c);
 foreach($subjects as $key=>$values){[$kind,$subject]=explode(':',$key,2);foreach($values as $permission=>$value){$before=0;foreach($preview['current'] as $row)if($row['kind']===$kind&&$row['subject']===$subject&&$row['permission']===$permission)$before=(int)$row['value'];if(!$caps[$permission]&&$value>$before)fail(403,'You cannot broaden a permission you do not have.');}}
 foreach($subjects as $key=>$values){[$kind,$subject]=explode(':',$key,2);save_channel_override($c,$kind,$subject,$values);}
 set_channel_sync($c,$preview['category']['id']);
}
function conversation_actions(string $action,array $in): array {
 switch($action){
 case 'channel_move_preview':case 'channel_sync_preview':case 'channel_sync':
  return transaction(function()use($action,$in){
   $c=channel(text_field($in,'channel_id',32,32));if(!$c['server_id']||$c['parent_id'])fail(400,'Choose a server channel.');require_permission($c['server_id'],'manage_channels');
   if($action==='channel_move_preview')return channel_move_preview($c,text_field($in,'category',1,40),text_field($in,'category_id',32,32));
   $preview=channel_sync_preview($c);
   if($action==='channel_sync_preview')return $preview;
   if(!hash_equals($preview['snapshot'],text_field($in,'snapshot',64,64)))fail(409,'Channel or category permissions changed. Reopen the preview.');
   check_override_version($in);$enabled=$in['enabled']??null;if(!is_bool($enabled))fail(400,'Choose whether to sync.');
   if(!$enabled){set_channel_sync($c,null);return ['ok'=>true];}
   apply_channel_sync($c,$preview,$in);return ['ok'=>true];
  });
 case 'category_permissions':case 'category_permissions_apply':
  $sid=text_field($in,'server_id',32,32);$name=text_field($in,'name',1,40);$id=text_field($in,'id',32,32);
  return transaction(function()use($sid,$name,$id,$action,$in){
   $current=category_permission_snapshot($sid,$name,$id);
   if($action==='category_permissions_apply'){
    $snapshot=text_field($in,'snapshot',64,64);if(!hash_equals($current['snapshot'],$snapshot))fail(409,'Category channels or permissions changed. Reopen the preview.');
    [$kind,$subject,$values]=override_input($sid,$in);
    $syncedOnly=$in['synced_only']??false;if(!is_bool($syncedOnly))fail(400,'Invalid apply scope.');
    $affected=array_filter($current['channels'],fn($c)=>!$syncedOnly||$c['synced']);
    foreach($affected as $c){$caps=channel_capabilities($c);foreach($values as $p=>$v)if($v===1&&!$caps[$p])fail(403,'You cannot allow a permission you do not have in every affected channel.');}
    check_override_version($in,$current['overrides']);
    foreach($affected as $c)check_override_version($in,sql('SELECT permission FROM channel_overrides WHERE channel_id=?',[$c['id']])->fetchAll());
    $before=['view'=>0,'send'=>0,'connect'=>0,'attach'=>0,'pin_messages'=>0,'bypass_slowmode'=>0,'manage_threads'=>0,'create_public_threads'=>0];foreach($current['overrides'] as $row)if($row['kind']===$kind&&$row['subject']===$subject)$before[$row['permission']]=(int)$row['value'];
    foreach(['pin_messages','bypass_slowmode','manage_threads'] as $permission)if($values[$permission]>$before[$permission]&&!in_array($permission,permissions_for($sid),true))fail(403,'You need the server permission to broaden it in a category template.');
    sql('DELETE FROM category_overrides WHERE category_id=? AND kind=? AND subject=?',[$id,$kind,$subject]);foreach($values as $p=>$v)if($v!==0)sql('INSERT INTO category_overrides VALUES(?,?,?,?,?)',[$id,$kind,$subject,$p,$v]);
    foreach($affected as $c)save_channel_override($c,$kind,$subject,$values);
    audit_changes($sid,'category-permissions','Category '.$name.' ['.$id.'] · '.$kind.' ['.$subject.'] · -1 deny / 0 inherit / 1 allow',$before,$values,['view'=>'View','send'=>'Send','connect'=>'Connect','attach'=>'Attach','pin_messages'=>'Pin messages','bypass_slowmode'=>'Bypass slowmode','manage_threads'=>'Manage threads','create_public_threads'=>'Create public threads']);
    return ['ok'=>true];
   }
   return ['snapshot'=>$current['snapshot'],'overrides'=>$current['overrides'],'channels'=>array_map(fn($c)=>array_intersect_key($c,array_flip(['id','name','kind','private','synced'])),$current['channels'])];
  });
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
  return transaction(function()use($cid,$root,$name){$parent=channel($cid,true);if(!$parent['server_id']||$parent['kind']!=='text'||$parent['parent_id'])fail(400,'Start a thread in a server text channel.');if(!one('SELECT 1 FROM messages WHERE id=? AND channel_id=? AND deleted=0',[$root,$parent['id']]))fail(404,'Message not found.');$old=one('SELECT id FROM channels WHERE root_message=?',[$root]);if($old)return ['channel_id'=>$old['id']];if(!channel_capabilities($parent)['create_public_threads'])fail(403,'You cannot create threads in this channel.');
   // Thread creation has its own sliding interval; only successful creates advance it.
   $key=hash('sha256','thread-create:'.$cid.':'.uid());$now=time();
   if($parent['slowmode']>0&&!in_array('manage_messages',permissions_for($parent['server_id']),true)&&!channel_capabilities($parent)['bypass_slowmode']&&one('SELECT 1 FROM limits WHERE key=? AND window>?',[$key,$now-$parent['slowmode']]))fail(429,'Slow mode is active. Please wait before creating another thread in this channel.');
   if(sql('SELECT count(*) FROM channels WHERE parent_id=?',[$parent['id']])->fetchColumn()>=20)fail(409,'This channel has reached its 20-thread limit.');$id=random_id();sql('INSERT INTO channels(id,server_id,name,kind,created,parent_id,root_message,slowmode,thread_activity,auto_archive_minutes) VALUES(?,?,?,?,?,?,?,?,?,?)',[$id,$parent['server_id'],$name,'text',$now,$parent['id'],$root,$parent['default_thread_slowmode'],$now,$parent['default_thread_auto_archive_minutes']]);sql('INSERT INTO limits(key,window,hits) VALUES(?,?,1) ON CONFLICT(key) DO UPDATE SET window=excluded.window,hits=1',[$key,$now]);hierarchy_audit($parent['server_id'],'thread-create',0,thread_audit_label(one('SELECT * FROM channels WHERE id=?',[$id])));return ['channel_id'=>$id];});
 case 'thread_settings':
  $cid=text_field($in,'channel_id',32,32);
  transaction(function()use($cid,$in){$c=channel($cid);if(!$c['parent_id'])fail(400,'Choose a thread.');if(!channel_capabilities($c)['manage_threads'])fail(403,'You do not have permission to manage threads in this channel.');$name=text_field($in,'name',1,32);$slow=$in['slowmode']??null;if(!is_int($slow)||$slow<0||$slow>21600)fail(400,'Slow mode must be 0–21600 seconds.');
   check_thread_revision($c,$in);$locked=array_key_exists('locked',$in)?$in['locked']:(bool)$c['locked'];if(!is_bool($locked))fail(400,'Invalid thread lock state.');$auto=array_key_exists('auto_archive_minutes',$in)?$in['auto_archive_minutes']:$c['auto_archive_minutes'];if(!is_int($auto)||!in_array($auto,[0,60,1440,4320,10080],true))fail(400,'Choose a valid automatic archive interval.');$before=['name'=>$c['name'],'slowmode'=>(int)$c['slowmode'],'locked'=>(bool)$c['locked'],'auto_archive_minutes'=>(int)$c['auto_archive_minutes']];$after=['name'=>$name,'slowmode'=>$slow,'locked'=>$locked,'auto_archive_minutes'=>$auto];if($before===$after)return;sql('UPDATE channels SET name=?,slowmode=?,locked=?,auto_archive_minutes=?,thread_activity=?,thread_revision=thread_revision+1 WHERE id=?',[$name,$slow,(int)$locked,$auto,$auto!==(int)$c['auto_archive_minutes']?time():$c['thread_activity'],$cid]);audit_changes($c['server_id'],'thread-edit',thread_audit_label($c),$before,$after,['name'=>'Name','slowmode'=>'Slow mode (seconds)','locked'=>'Locked','auto_archive_minutes'=>'Auto archive (minutes; 0 is off)']);
  });return ['ok'=>true];
 case 'thread_archive':
  $cid=text_field($in,'channel_id',32,32);if(!is_bool($in['archived']??null))fail(400,'Invalid archive state.');$archived=$in['archived'];
  transaction(function()use($cid,$archived,$in){$c=channel($cid);if(!$c['parent_id'])fail(400,'Choose a thread.');$manager=channel_capabilities($c)['manage_threads'];if(!$manager&&($archived||$c['locked']||($in['thread_version']??null)!==1))fail(403,'Only a thread manager can perform this action.');if(!$manager)channel($c['parent_id'],true);
   $modern=check_thread_revision($c,$in);if(array_key_exists('locked',$in))fail(400,'Change the lock in Thread settings.');
   // Old Archive buttons meant manager-only reopening; retain that protection.
   $locked=(bool)$c['locked']||(!$modern&&$archived);$before=['archived'=>(bool)$c['archived'],'locked'=>(bool)$c['locked']];$after=['archived'=>$archived,'locked'=>$locked];if($before===$after)return;sql('UPDATE channels SET archived=?,locked=?,thread_activity=?,thread_revision=thread_revision+1 WHERE id=?',[(int)$archived,(int)$locked,!$archived&&$c['archived']?time():$c['thread_activity'],$cid]);audit_changes($c['server_id'],$archived?'thread-archive':'thread-reopen',thread_audit_label($c),$before,$after,['archived'=>'Archived','locked'=>'Locked']);});return ['ok'=>true];
 case 'override_list':case 'override_save':
  $c=channel(text_field($in,'channel_id',32,32));if(!$c['server_id']||$c['parent_id'])fail(400,'Threads inherit their parent channel permissions.');require_permission($c['server_id'],'manage_channels');
  if($action==='override_save'){
   transaction(function()use($c,$in){$c=channel($c['id']);require_permission($c['server_id'],'manage_channels');[$kind,$subject,$values]=override_input($c['server_id'],$in);check_override_version($in,sql('SELECT permission FROM channel_overrides WHERE channel_id=? AND kind=? AND subject=?',[$c['id'],$kind,$subject])->fetchAll());save_channel_override($c,$kind,$subject,$values);set_channel_sync($c,null);});
  }return ['overrides'=>sql('SELECT * FROM channel_overrides WHERE channel_id=?',[$c['id']])->fetchAll()];
 case 'attachment_upload':
  $cid=text_field($in,'channel_id',32,32);$c=send_channel($cid,$in,'attach');if($c['kind']!=='text')fail(400,'Choose a text channel.');send_channel($cid,$in);rate('attachment:'.uid(),20,3600);$name=text_field($in,'name',1,120);
  if(preg_match('/[\\\\\/:*?"<>|\x00-\x1F]/',$name)||str_ends_with($name,'.')||str_ends_with($name,' ')||!preg_match('/\.(png|jpe?g|webp|gif|pdf|txt|csv|md|json|zip|docx|xlsx|pptx|mp3|wav|ogg|mp4|webm)$/i',$name))fail(400,'Unsupported file name or type. Use images, documents, ZIP, audio or video.');
  $encoded=$in['data']??null;if(!is_string($encoded)||strlen($encoded)>5592408)fail(413,'Files must be at most 4 MB.');$bytes=base64_decode($encoded,true);if($bytes===false||base64_encode($bytes)!==$encoded||strlen($bytes)<1||strlen($bytes)>4194304)fail(400,'Invalid file or size.');
  return transaction(function()use($cid,$in,$name,$bytes,$encoded){send_channel($cid,$in,'attach');send_channel($cid,$in);sql('DELETE FROM attachments WHERE message_id IS NULL AND created<?',[time()-86400]);$size=strlen($bytes);if((int)sql('SELECT COALESCE(sum(size),0) FROM attachments WHERE user_id=?',[uid()])->fetchColumn()+$size>104857600||(int)sql('SELECT COALESCE(sum(size),0) FROM attachments')->fetchColumn()+$size>536870912)fail(409,'Attachment storage is full. Delete old attachments to free space.');$id=random_id();$hash=hash('sha256',$bytes);sql('INSERT INTO attachments VALUES(?,?,?,?,?,?,?,?,?)',[$id,$cid,uid(),null,$name,$size,$hash,$encoded,time()]);return ['id'=>$id,'name'=>$name,'size'=>$size,'sha256'=>$hash];});
 case 'attachment_get':case 'attachment_discard':
  $id=text_field($in,'id',32,32);$a=one('SELECT * FROM attachments WHERE id=?',[$id]);if(!$a)fail(404,'Attachment not found.');channel($a['channel_id']);if($a['message_id']){if(!one('SELECT 1 FROM messages WHERE id=? AND deleted=0',[$a['message_id']]))fail(404,'Attachment no longer available.');}elseif((int)$a['user_id']!==uid())fail(403,'This attachment has not been shared.');
  if($action==='attachment_discard'){if((int)$a['user_id']!==uid()||$a['message_id'])fail(403,'Only your unsent attachments can be discarded.');sql('DELETE FROM attachments WHERE id=?',[$id]);return ['ok'=>true];}return array_intersect_key($a,array_flip(['name','size','sha256','data']));
 }fail(404,'Unknown conversation action.');
}
function send_channel(string $cid,array $in,string $permission='send'): array {
 $reopen=array_key_exists('reopen_thread',$in)?$in['reopen_thread']:false;if(!is_bool($reopen))fail(400,'Invalid thread reopening choice.');
 if(!$reopen)return channel($cid,true,$permission);
 $c=channel($cid);if(!$c['parent_id'])fail(400,'Only threads can be reopened while sending.');
 channel($c['parent_id'],true);if($permission!=='send')channel($c['parent_id'],true,$permission);
 if(($c['archived']||($c['auto_archive_minutes']>0&&$c['thread_activity']+$c['auto_archive_minutes']*60<=time()))&&$c['locked']&&!channel_capabilities($c)['manage_threads'])fail(403,'Only a thread manager can reopen this locked thread.');return $c;
}
function send_message(array $in): array {
 $cid=text_field($in,'channel_id',32,32);$c=send_channel($cid,$in);if($c['kind']!=='text')fail(400,'Use a text channel.');rate('send:'.uid(),30,60);$ids=$in['attachments']??[];if(!is_array($ids)||count($ids)>3||count(array_unique($ids,SORT_REGULAR))!==count($ids))fail(400,'Attach up to 3 files.');foreach($ids as $id)if(!is_string($id)||!preg_match('/^[a-f0-9]{32}$/',$id))fail(400,'Invalid attachment.');$body=text_field($in,'body',$ids?0:1,4000);$client=text_field($in,'client_id',16,64);$reply=isset($in['reply_to'])?positive_id($in['reply_to']):null;
 if(array_key_exists('notify_everyone',$in)&&!is_bool($in['notify_everyone']))fail(400,'Invalid everyone mention choice.');
 if(array_key_exists('notify_here',$in)&&!is_bool($in['notify_here']))fail(400,'Invalid here mention choice.');
 if(!empty($in['notify_here'])&&!empty($in['notify_everyone']))fail(400,'Choose one notification audience.');
 $broadcast=!empty($in['notify_everyone'])?'everyone':(!empty($in['notify_here'])?'here':null);
 return transaction(function()use($cid,$in,$ids,$body,$client,$reply,$broadcast){$c=send_channel($cid,$in);$old=one('SELECT id,channel_id FROM messages WHERE user_id=? AND client_id=?',[uid(),$client]);if($old){if($old['channel_id']!==$cid)fail(409,'Message retry belongs to another channel.');return ['id'=>(int)$old['id']];}
  if($reply&&!one('SELECT 1 FROM messages WHERE id=? AND channel_id=? AND deleted=0',[$reply,$cid]))fail(400,'Reply must reference a message in this conversation.');
  if($c['server_id']&&$c['slowmode']>0&&!in_array('manage_messages',permissions_for($c['server_id']),true)&&!channel_capabilities($c)['bypass_slowmode']&&one('SELECT 1 FROM messages WHERE channel_id=? AND user_id=? AND created>?',[$cid,uid(),time()-$c['slowmode']]))fail(429,'Slow mode is active. Please wait before sending another message.');
  if($ids)send_channel($cid,$in,'attach');foreach($ids as $id)if(!one('SELECT 1 FROM attachments WHERE id=? AND user_id=? AND channel_id=? AND message_id IS NULL',[$id,uid(),$cid]))fail(403,'Choose your own unsent attachment from this conversation.');
  $reopened=(bool)($c['parent_id']&&$c['archived']);if($reopened){sql('UPDATE channels SET archived=0,thread_revision=thread_revision+1 WHERE id=?',[$cid]);audit_changes($c['server_id'],'thread-reopen',thread_audit_label($c),['archived'=>true],['archived'=>false],['archived'=>'Archived']);}
  sql('INSERT INTO messages(channel_id,user_id,body,created,client_id,reply_to) VALUES(?,?,?,?,?,?)',[$cid,uid(),$body,time(),$client,$reply]);global $db;$id=(int)$db->lastInsertId();if($c['parent_id'])sql('UPDATE channels SET thread_activity=? WHERE id=?',[time(),$cid]);foreach($ids as $a)sql('UPDATE attachments SET message_id=? WHERE id=?',[$id,$a]);sql('DELETE FROM typing WHERE user_id=? AND channel_id=?',[uid(),$cid]);notify_message($id,$c,$body,$reply,$broadcast);return ['id'=>$id,'thread_reopened'=>$reopened];
 });
}
