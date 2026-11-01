<?php
declare(strict_types=1);
function migrate_community(): void {
 global $db;if((int)$db->query('PRAGMA user_version')->fetchColumn()>=3)return;
 transaction(function()use($db){
  if((int)$db->query('PRAGMA user_version')->fetchColumn()>=3)return;
  $db->exec("CREATE TABLE friendships(a INTEGER NOT NULL REFERENCES users(id),b INTEGER NOT NULL REFERENCES users(id),sender INTEGER NOT NULL REFERENCES users(id),accepted INTEGER NOT NULL DEFAULT 0,PRIMARY KEY(a,b),CHECK(a<b));
   CREATE TABLE blocks(user_id INTEGER NOT NULL REFERENCES users(id),target INTEGER NOT NULL REFERENCES users(id),PRIMARY KEY(user_id,target));
   CREATE TABLE read_states(user_id INTEGER NOT NULL REFERENCES users(id),channel_id TEXT NOT NULL REFERENCES channels(id) ON DELETE CASCADE,last_id INTEGER NOT NULL DEFAULT 0,PRIMARY KEY(user_id,channel_id));
   CREATE TABLE typing(user_id INTEGER NOT NULL REFERENCES users(id),channel_id TEXT NOT NULL REFERENCES channels(id) ON DELETE CASCADE,seen INTEGER NOT NULL,PRIMARY KEY(user_id,channel_id));
   CREATE TABLE roles(id TEXT PRIMARY KEY,server_id TEXT NOT NULL REFERENCES servers(id) ON DELETE CASCADE,name TEXT NOT NULL,color TEXT NOT NULL,permissions TEXT NOT NULL);
   CREATE TABLE member_roles(server_id TEXT NOT NULL REFERENCES servers(id) ON DELETE CASCADE,user_id INTEGER NOT NULL REFERENCES users(id),role_id TEXT NOT NULL REFERENCES roles(id) ON DELETE CASCADE,PRIMARY KEY(user_id,role_id),FOREIGN KEY(server_id,user_id) REFERENCES members(server_id,user_id) ON DELETE CASCADE);
   CREATE TABLE channel_roles(channel_id TEXT NOT NULL REFERENCES channels(id) ON DELETE CASCADE,role_id TEXT NOT NULL REFERENCES roles(id) ON DELETE CASCADE,PRIMARY KEY(channel_id,role_id));
   ALTER TABLE channels ADD COLUMN category TEXT NOT NULL DEFAULT '';
   ALTER TABLE channels ADD COLUMN slowmode INTEGER NOT NULL DEFAULT 0;
   ALTER TABLE channels ADD COLUMN readonly INTEGER NOT NULL DEFAULT 0;
   PRAGMA user_version=3;");
 });
}
function migrate_presence(): void {
 global $db;if((int)$db->query('PRAGMA user_version')->fetchColumn()>=6)return;
 transaction(function()use($db){if((int)$db->query('PRAGMA user_version')->fetchColumn()>=6)return;
  $db->exec("ALTER TABLE users ADD COLUMN status TEXT NOT NULL DEFAULT 'online';
   ALTER TABLE users ADD COLUMN custom_status TEXT NOT NULL DEFAULT '';
   ALTER TABLE channels ADD COLUMN position INTEGER NOT NULL DEFAULT 0;
   PRAGMA user_version=6;");
 });
}
function migrate_navigation(): void {
 global $db;if((int)$db->query('PRAGMA user_version')->fetchColumn()>=7)return;
 transaction(function()use($db){if((int)$db->query('PRAGMA user_version')->fetchColumn()>=7)return;
  $db->exec('CREATE TABLE category_order(server_id TEXT NOT NULL REFERENCES servers(id) ON DELETE CASCADE,name TEXT NOT NULL,position INTEGER NOT NULL,PRIMARY KEY(server_id,name)); PRAGMA user_version=7;');
 });
}
function server_channel_rows(string $sid): array {
 return sql('SELECT c.*,COALESCE(o.position,2147483647) AS category_position FROM channels c LEFT JOIN category_order o ON o.server_id=c.server_id AND o.name=c.category WHERE c.server_id=? AND c.parent_id IS NULL ORDER BY category_position,c.category,c.position,c.created,c.id',[$sid])->fetchAll();
}
function visible_channel_rows(array $rows): array {
 return array_values(array_filter($rows,function($row){try{channel($row['id']);return true;}catch(ApiError $e){if($e->status!==403)throw $e;return false;}}));
}
function presence(array $u): string {return $u['status']==='invisible'||(int)$u['last_seen']<time()-45?'offline':$u['status'];}
function blocked(int $other): bool {return !!one('SELECT 1 FROM blocks WHERE (user_id=? AND target=?) OR (user_id=? AND target=?)',[uid(),$other,$other,uid()]);}
function friends(int $other): bool {return !!one('SELECT 1 FROM friendships WHERE a=? AND b=? AND accepted=1',[min(uid(),$other),max(uid(),$other)]);}
function migrate_hierarchy(): void {
 global $db;if((int)$db->query('PRAGMA user_version')->fetchColumn()>=8)return;
 transaction(function()use($db){if((int)$db->query('PRAGMA user_version')->fetchColumn()>=8)return;
  $db->exec("ALTER TABLE roles ADD COLUMN position INTEGER NOT NULL DEFAULT 0; ALTER TABLE audit ADD COLUMN detail TEXT NOT NULL DEFAULT '';");
  foreach(sql('SELECT DISTINCT server_id FROM roles')->fetchAll() as $server){$position=0;foreach(sql('SELECT id FROM roles WHERE server_id=? ORDER BY name DESC,id DESC',[$server['server_id']])->fetchAll() as $role)sql('UPDATE roles SET position=? WHERE id=?',[++$position,$role['id']]);}
  $db->exec('PRAGMA user_version=8;');
 });
}
function permission_names(): array {return ['manage_channels','manage_messages','invite','manage_server','manage_roles','moderate_members'];}
function permissions_for(string $sid): array {
 $m=member($sid);if($m['role']==='owner')return permission_names();
 $result=$m['role']==='moderator'?['manage_channels','manage_messages','invite','manage_server','moderate_members']:[];
 foreach(sql('SELECT r.permissions FROM roles r JOIN member_roles m ON m.role_id=r.id WHERE m.server_id=? AND m.user_id=?',[$sid,uid()])->fetchAll() as $r)$result=array_merge($result,json_decode($r['permissions'],true));return array_values(array_unique($result));
}
function require_permission(string $sid,string $permission): void {if(!in_array($permission,permissions_for($sid),true))fail(403,'You do not have permission for this action.');}
function role_rank(string $sid,int $who): int {
 $m=one('SELECT role FROM members WHERE server_id=? AND user_id=?',[$sid,$who]);if(!$m)fail(404,'Member not found.');
 // shortcut: legacy moderators stay above custom roles until a dedicated conversion migration.
 if($m['role']==='owner')return 2000000;if($m['role']==='moderator')return 1000000;
 return (int)sql('SELECT COALESCE(max(r.position),0) FROM roles r JOIN member_roles mr ON mr.role_id=r.id WHERE mr.server_id=? AND mr.user_id=?',[$sid,$who])->fetchColumn();
}
function lower_member(string $sid,int $who): void {
 if(!one('SELECT 1 FROM members WHERE server_id=? AND user_id=?',[$sid,$who]))fail(400,'Choose a member from this server.');
 if(member($sid)['role']!=='owner'&&($who===uid()||role_rank($sid,$who)>=role_rank($sid,uid())))fail(403,'You can only manage members below your highest role.');
}
function managed_role(string $sid,string $id): array {
 require_permission($sid,'manage_roles');$r=one('SELECT * FROM roles WHERE id=? AND server_id=?',[$id,$sid]);if(!$r)fail(404,'Role not found.');
 if((int)$r['position']>=role_rank($sid,uid()))fail(403,'You can only manage roles below your highest role.');return $r;
}
function grantable_permissions(string $sid,array $permissions): void {if(array_diff($permissions,permissions_for($sid)))fail(403,'You cannot grant permissions you do not have.');}
function hierarchy_audit(string $sid,string $action,int $target=0,string $detail=''): void {sql('INSERT INTO audit(actor,server_id,action,target,created,detail) VALUES(?,?,?,?,?,?)',[uid(),$sid,$action,$target,time(),$detail]);}
function disconnect_role(string $sid,string $role): void {sql('DELETE FROM calls WHERE channel_id IN(SELECT id FROM channels WHERE server_id=?) AND user_id IN(SELECT user_id FROM member_roles WHERE server_id=? AND role_id=?)',[$sid,$sid,$role]);}
function relationship_state(): array {
 $result=[];foreach(sql('SELECT u.*,f.sender,f.accepted FROM friendships f JOIN users u ON u.id=CASE WHEN f.a=? THEN f.b ELSE f.a END WHERE f.a=? OR f.b=?',[uid(),uid(),uid()])->fetchAll() as $u)$result[]=public_user($u)+['relationship'=>$u['accepted']?'friend':((int)$u['sender']===uid()?'outgoing':'incoming'),'online'=>presence($u)!=='offline'];
 foreach(sql('SELECT u.* FROM blocks b JOIN users u ON u.id=b.target WHERE b.user_id=?',[uid()])->fetchAll() as $u)$result[]=public_user($u)+['relationship'=>'blocked','online'=>false];return $result;
}
function community(string $action,array $in): array {
 switch($action){
 case 'category_order':
  $sid=text_field($in,'server_id',32,32);require_permission($sid,'manage_channels');$name=text_field($in,'category',1,40);$before=($in['before']??null)===null?null:text_field($in,'before',1,40);
  transaction(function()use($sid,$name,$before){$rows=server_channel_rows($sid);$visible=array_column(visible_channel_rows($rows),'category');if(!in_array($name,$visible,true)||($before!==null&&!in_array($before,$visible,true)))fail(403,'Choose categories you can view in this server.');if($before===$name)return;
   $names=array_values(array_unique(array_filter(array_column($rows,'category'),fn($value)=>$value!=='')));$names=array_values(array_filter($names,fn($value)=>$value!==$name));$index=$before===null?count($names):array_search($before,$names,true);array_splice($names,$index,0,[$name]);
   sql('DELETE FROM category_order WHERE server_id=?',[$sid]);foreach($names as $position=>$category)sql('INSERT INTO category_order VALUES(?,?,?)',[$sid,$category,$position]);
  });break;
 case 'channel_reorder':
  $id=text_field($in,'channel_id',32,32);$category=text_field($in,'category',0,40);$before=($in['before_id']??null)===null?null:text_field($in,'before_id',32,32);
  transaction(function()use($id,$category,$before){$c=channel($id);if(!$c['server_id']||$c['parent_id'])fail(400,'Choose a server channel.');require_permission($c['server_id'],'manage_channels');
   if($before!==null){$target=channel($before);if($target['server_id']!==$c['server_id']||$target['parent_id']||$target['category']!==$category||$target['kind']!==$c['kind'])fail(400,'Choose a channel of the same type in the destination category.');if($before===$id)return;}
   $ids=array_column(sql('SELECT id FROM channels WHERE server_id=? AND parent_id IS NULL AND kind=? AND category=? AND id<>? ORDER BY position,created,id',[$c['server_id'],$c['kind'],$category,$id])->fetchAll(),'id');
   $index=$before===null?count($ids):array_search($before,$ids,true);array_splice($ids,$index,0,[$id]);sql('UPDATE channels SET category=? WHERE id=?',[$category,$id]);foreach($ids as $position=>$channelId)sql('UPDATE channels SET position=? WHERE id=?',[$position+1,$channelId]);
  });break;
 case 'status':
  $status=$in['status']??null;if(!in_array($status,['online','idle','dnd','invisible'],true))fail(400,'Choose a valid status.');
  sql('UPDATE users SET status=?,custom_status=? WHERE id=?',[$status,text_field($in,'custom_status',0,100),uid()]);break;
 case 'channel_move':
  $id=text_field($in,'channel_id',32,32);$direction=$in['direction']??null;if(!in_array($direction,[-1,1],true))fail(400,'Choose up or down.');
  transaction(function()use($id,$direction){$c=channel($id);if(!$c['server_id']||$c['parent_id'])fail(400,'Choose a server channel.');require_permission($c['server_id'],'manage_channels');
   $rows=sql('SELECT id FROM channels WHERE server_id=? AND parent_id IS NULL AND kind=? AND category=? ORDER BY position,created,id',[$c['server_id'],$c['kind'],$c['category']])->fetchAll();
   $ids=[];foreach($rows as $row){try{channel($row['id']);$ids[]=$row['id'];}catch(ApiError $e){if($e->status!==403)throw $e;}}
   $index=array_search($id,$ids,true);$next=$index+$direction;if($next<0||$next>=count($ids))return;
   [$ids[$index],$ids[$next]]=[$ids[$next],$ids[$index]];foreach($ids as $position=>$channelId)sql('UPDATE channels SET position=? WHERE id=?',[$position+1,$channelId]);
  });break;
 case 'friend_request':
  rate('friend-request:'.uid(),20,3600);$name=strtolower(text_field($in,'username',3,24));$u=one('SELECT id FROM users WHERE username=?',[$name]);if(!$u||(int)$u['id']===uid()||blocked((int)$u['id']))fail(400,'Unable to send that friend request. Check the username.');$other=(int)$u['id'];
  transaction(function()use($other){$args=[min(uid(),$other),max(uid(),$other)];$f=one('SELECT * FROM friendships WHERE a=? AND b=?',$args);if($f){if((int)$f['sender']!==uid())sql('UPDATE friendships SET accepted=1 WHERE a=? AND b=?',$args);return;}if(sql('SELECT count(*) FROM friendships WHERE a=? OR b=?',[uid(),uid()])->fetchColumn()>=200||sql('SELECT count(*) FROM friendships WHERE a=? OR b=?',[$other,$other])->fetchColumn()>=200)fail(409,'Friend list or request limit reached.');sql('INSERT INTO friendships(a,b,sender) VALUES(?,?,?)',[...$args,uid()]);});break;
 case 'relationship':
  $other=positive_id($in['user_id']??null);$op=$in['operation']??'';if($other===uid()||!one('SELECT 1 FROM users WHERE id=?',[$other]))fail(400,'Choose another user.');rate('relationship:'.uid(),40,60);
  transaction(function()use($other,$op){$args=[min(uid(),$other),max(uid(),$other)];if($op==='accept'){if(blocked($other)||!one('SELECT 1 FROM friendships WHERE a=? AND b=? AND sender<>?',[...$args,uid()]))fail(403,'No incoming request.');sql('UPDATE friendships SET accepted=1 WHERE a=? AND b=?',$args);}
   elseif(in_array($op,['remove','block'],true)){sql('DELETE FROM friendships WHERE a=? AND b=?',$args);if($op==='block'){sql('INSERT OR IGNORE INTO blocks VALUES(?,?)',[uid(),$other]);sql('DELETE FROM calls WHERE channel_id IN (SELECT id FROM channels WHERE pair_key=?)',[implode(':',$args)]);}}
   elseif($op==='unblock')sql('DELETE FROM blocks WHERE user_id=? AND target=?',[uid(),$other]);else fail(400,'Invalid relationship action.');});break;
 case 'read':
  $cid=text_field($in,'channel_id',32,32);channel($cid);$id=$in['last_id']??null;if(!is_int($id)||$id<0)fail(400,'Invalid read position.');if($id&&!one('SELECT 1 FROM messages WHERE channel_id=? AND id=?',[$cid,$id]))fail(400,'Read position must belong to this channel.');sql('INSERT INTO read_states VALUES(?,?,?) ON CONFLICT(user_id,channel_id) DO UPDATE SET last_id=max(last_id,excluded.last_id)',[uid(),$cid,$id]);sql('UPDATE notifications SET seen=1 WHERE user_id=? AND message_id IN(SELECT id FROM messages WHERE channel_id=? AND id<=?)',[uid(),$cid,$id]);break;
 case 'typing':
  $cid=text_field($in,'channel_id',32,32);$c=channel($cid,true);if($c['kind']!=='text')fail(400,'Choose a text channel.');rate('typing:'.uid(),30,60);sql('INSERT INTO typing VALUES(?,?,?) ON CONFLICT(user_id,channel_id) DO UPDATE SET seen=excluded.seen',[uid(),$cid,time()]);break;
 case 'call_decline':
  $cid=text_field($in,'channel_id',32,32);$c=channel($cid);if($c['server_id'])fail(400,'Choose a direct call.');if(!$c['group_owner'])sql('DELETE FROM calls WHERE channel_id=?',[$cid]);else sql('DELETE FROM calls WHERE channel_id=? AND user_id=?',[$cid,uid()]);break;
 case 'role_list':
  $sid=text_field($in,'server_id',32,32);$m=member($sid);return ['roles'=>sql('SELECT * FROM roles WHERE server_id=? ORDER BY position DESC,id',[$sid])->fetchAll(),'assignments'=>sql('SELECT user_id,role_id FROM member_roles WHERE server_id=?',[$sid])->fetchAll(),'actor'=>['id'=>uid(),'owner'=>$m['role']==='owner','rank'=>role_rank($sid,uid()),'permissions'=>permissions_for($sid)]];
 case 'role_save':
  $sid=text_field($in,'server_id',32,32);require_permission($sid,'manage_roles');$name=text_field($in,'name',1,32);$color=color_field($in,'color');$permissions=$in['permissions']??[];if(!is_array($permissions)||count($permissions)>count(permission_names()))fail(400,'Invalid permissions.');foreach($permissions as $permission)if(!is_string($permission)||!in_array($permission,permission_names(),true))fail(400,'Invalid permissions.');$id=isset($in['id'])?text_field($in,'id',32,32):random_id();
  transaction(function()use($sid,$name,$color,$permissions,$id,$in){require_permission($sid,'manage_roles');grantable_permissions($sid,$permissions);
   if(isset($in['id'])){$role=managed_role($sid,$id);$position=$role['position'];if(array_diff(json_decode($role['permissions'],true),$permissions))disconnect_role($sid,$id);}
   else{if(sql('SELECT count(*) FROM roles WHERE server_id=?',[$sid])->fetchColumn()>=30)fail(409,'Role limit reached.');sql('UPDATE roles SET position=position+1 WHERE server_id=?',[$sid]);$position=1;}
   sql('INSERT INTO roles(id,server_id,name,color,permissions,position) VALUES(?,?,?,?,?,?) ON CONFLICT(id) DO UPDATE SET name=excluded.name,color=excluded.color,permissions=excluded.permissions',[$id,$sid,$name,$color,json_encode(array_values(array_unique($permissions))),$position]);hierarchy_audit($sid,isset($in['id'])?'role-edit':'role-create',0,$name);
  });return ['id'=>$id];
 case 'role_move':
  $sid=text_field($in,'server_id',32,32);$id=text_field($in,'id',32,32);$direction=$in['direction']??null;if(!in_array($direction,[-1,1],true))fail(400,'Choose up or down.');
  transaction(function()use($sid,$id,$direction){$role=managed_role($sid,$id);$next=one('SELECT * FROM roles WHERE server_id=? AND position'.($direction===1?'>':'<').'? ORDER BY position '.($direction===1?'ASC':'DESC').' LIMIT 1',[$sid,$role['position']]);if(!$next)return;managed_role($sid,$next['id']);sql('UPDATE roles SET position=? WHERE id=?',[$next['position'],$id]);sql('UPDATE roles SET position=? WHERE id=?',[$role['position'],$next['id']]);hierarchy_audit($sid,'role-move',0,$role['name']);});break;
 case 'role_delete':
  $sid=text_field($in,'server_id',32,32);$id=text_field($in,'id',32,32);transaction(function()use($sid,$id){$role=managed_role($sid,$id);disconnect_role($sid,$id);sql('DELETE FROM roles WHERE id=? AND server_id=?',[$id,$sid]);sql('UPDATE roles SET position=position-1 WHERE server_id=? AND position>?',[$sid,$role['position']]);sql('DELETE FROM channel_overrides WHERE kind=? AND subject=? AND channel_id IN(SELECT id FROM channels WHERE server_id=?)',['role',$id,$sid]);hierarchy_audit($sid,'role-delete',0,$role['name']);});break;
 case 'role_assign':
  $sid=text_field($in,'server_id',32,32);$who=positive_id($in['user_id']??null);$id=text_field($in,'role_id',32,32);$assigned=$in['assigned']??null;if(!is_bool($assigned))fail(400,'Invalid assignment.');
  transaction(function()use($sid,$who,$id,$assigned){$role=managed_role($sid,$id);lower_member($sid,$who);if($assigned){grantable_permissions($sid,json_decode($role['permissions'],true));sql('INSERT OR IGNORE INTO member_roles VALUES(?,?,?)',[$sid,$who,$id]);}else{sql('DELETE FROM member_roles WHERE server_id=? AND user_id=? AND role_id=?',[$sid,$who,$id]);sql('DELETE FROM calls WHERE user_id=? AND channel_id IN(SELECT id FROM channels WHERE server_id=?)',[$who,$sid]);}hierarchy_audit($sid,$assigned?'role-assign':'role-remove',$who,$role['name']);});break;
 case 'transfer_owner':
  $sid=text_field($in,'server_id',32,32);if(member($sid)['role']!=='owner')fail(403,'Only the current owner can transfer ownership.');rate('transfer:'.uid(),10,3600);$who=positive_id($in['user_id']??null);$password=$in['password']??null;if(!is_string($password)||strlen($password)>128)fail(400,'Enter your current password.');$confirmation=text_field($in,'confirmation',3,24);
  transaction(function()use($sid,$who,$password,$confirmation){$actor=member($sid);if($actor['role']!=='owner'||(int)$actor['owner_id']!==uid())fail(403,'Ownership has changed.');if($who===uid())fail(400,'Choose another member.');$target=one('SELECT u.username FROM users u JOIN members m ON m.user_id=u.id WHERE m.server_id=? AND u.id=?',[$sid,$who]);if(!$target)fail(400,'Choose an existing server member.');if($confirmation!==$target['username'])fail(400,'Type the new owner username exactly.');$current=one('SELECT password FROM users WHERE id=?',[uid()]);if(!password_verify($password,$current['password']))fail(403,'Your current password is incorrect.');
   sql('UPDATE members SET role=? WHERE server_id=? AND user_id=?',['member',$sid,uid()]);sql('UPDATE members SET role=?,muted_until=0 WHERE server_id=? AND user_id=?',['owner',$sid,$who]);sql('UPDATE servers SET owner_id=? WHERE id=?',[$who,$sid]);sql('DELETE FROM calls WHERE channel_id IN(SELECT id FROM channels WHERE server_id=?)',[$sid]);hierarchy_audit($sid,'ownership-transfer',$who);
  });break;
 case 'moderate':
  $sid=text_field($in,'server_id',32,32);$target=positive_id($in['user_id']??null);$op=$in['operation']??'';
  transaction(function()use($sid,$target,$op){require_permission($sid,'moderate_members');$actor=member($sid);if($target===uid())fail(400,'You cannot moderate yourself.');$victim=one('SELECT * FROM members WHERE server_id=? AND user_id=?',[$sid,$target]);if(!$victim)fail(404,'Member not found.');if($victim['role']==='owner')fail(403,'You cannot moderate the owner.');lower_member($sid,$target);
   if(in_array($op,['kick','ban'],true)){if($op==='ban')sql('INSERT OR IGNORE INTO bans VALUES(?,?)',[$sid,$target]);sql('DELETE FROM channel_members WHERE user_id=? AND channel_id IN (SELECT id FROM channels WHERE server_id=?)',[$target,$sid]);sql('DELETE FROM calls WHERE user_id=? AND channel_id IN (SELECT id FROM channels WHERE server_id=?)',[$target,$sid]);sql('DELETE FROM members WHERE server_id=? AND user_id=?',[$sid,$target]);}
   elseif(in_array($op,['mute','unmute'],true)){sql('UPDATE members SET muted_until=? WHERE server_id=? AND user_id=?',[$op==='mute'?time()+3600:0,$sid,$target]);if($op==='mute')sql('DELETE FROM calls WHERE user_id=? AND channel_id IN (SELECT id FROM channels WHERE server_id=?)',[$target,$sid]);}
   elseif(in_array($op,['promote','demote'],true)&&$actor['role']==='owner')sql('UPDATE members SET role=? WHERE server_id=? AND user_id=?',[$op==='promote'?'moderator':'member',$sid,$target]);else fail(400,'Invalid moderation action.');hierarchy_audit($sid,$op,$target);
  });break;
 case 'channel_access':
  $cid=text_field($in,'channel_id',32,32);$c=channel($cid);if(!$c['server_id'])fail(400,'Choose a server channel.');require_permission($c['server_id'],'manage_channels');return ['members'=>array_column(sql('SELECT user_id FROM channel_members WHERE channel_id=?',[$cid])->fetchAll(),'user_id'),'roles'=>array_column(sql('SELECT role_id FROM channel_roles WHERE channel_id=?',[$cid])->fetchAll(),'role_id')];
 case 'channel_delete':
  $cid=text_field($in,'channel_id',32,32);$c=channel($cid);if(!$c['server_id'])fail(400,'Choose a server channel.');require_permission($c['server_id'],'manage_channels');transaction(function()use($cid,$c){sql('DELETE FROM signals WHERE channel_id=?',[$cid]);sql('DELETE FROM channels WHERE id=?',[$cid]);sql('INSERT INTO audit(actor,server_id,action,target,created) VALUES(?,?,?,?,?)',[uid(),$c['server_id'],'delete-channel',0,time()]);});break;
 default:fail(404,'Unknown community action.');
 }return ['ok'=>true];
}
