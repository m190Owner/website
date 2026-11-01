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
function permissions_for(string $sid): array {
 $m=member($sid);if(in_array($m['role'],['owner','moderator'],true))return ['manage_channels','manage_messages','invite','manage_server'];
 $result=[];foreach(sql('SELECT r.permissions FROM roles r JOIN member_roles m ON m.role_id=r.id WHERE m.server_id=? AND m.user_id=?',[$sid,uid()])->fetchAll() as $r)$result=array_merge($result,json_decode($r['permissions'],true));return array_values(array_unique($result));
}
function require_permission(string $sid,string $permission): void {if(!in_array($permission,permissions_for($sid),true))fail(403,'You do not have permission for this action.');}
function role_owner(string $sid): void {if(member($sid)['role']!=='owner')fail(403,'Only the server owner can manage roles.');}
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
  $sid=text_field($in,'server_id',32,32);member($sid);return ['roles'=>sql('SELECT * FROM roles WHERE server_id=? ORDER BY name',[$sid])->fetchAll(),'assignments'=>sql('SELECT user_id,role_id FROM member_roles WHERE server_id=?',[$sid])->fetchAll()];
 case 'role_save':
  $sid=text_field($in,'server_id',32,32);role_owner($sid);$name=text_field($in,'name',1,32);$color=color_field($in,'color');$permissions=$in['permissions']??[];if(!is_array($permissions)||count($permissions)>4)fail(400,'Invalid permissions.');foreach($permissions as $permission)if(!is_string($permission)||!in_array($permission,['manage_channels','manage_messages','invite','manage_server'],true))fail(400,'Invalid permissions.');$id=isset($in['id'])?text_field($in,'id',32,32):random_id();
  transaction(function()use($sid,$name,$color,$permissions,$id,$in){if(isset($in['id'])&&!one('SELECT 1 FROM roles WHERE id=? AND server_id=?',[$id,$sid]))fail(404,'Role not found.');if(!isset($in['id'])&&sql('SELECT count(*) FROM roles WHERE server_id=?',[$sid])->fetchColumn()>=30)fail(409,'Role limit reached.');sql('INSERT INTO roles VALUES(?,?,?,?,?) ON CONFLICT(id) DO UPDATE SET name=excluded.name,color=excluded.color,permissions=excluded.permissions',[$id,$sid,$name,$color,json_encode(array_values(array_unique($permissions)))]);});return ['id'=>$id];
 case 'role_delete':
  $sid=text_field($in,'server_id',32,32);role_owner($sid);sql('DELETE FROM roles WHERE id=? AND server_id=?',[text_field($in,'id',32,32),$sid]);break;
 case 'role_assign':
  $sid=text_field($in,'server_id',32,32);role_owner($sid);$who=positive_id($in['user_id']??null);$role=text_field($in,'role_id',32,32);if(!one('SELECT 1 FROM members WHERE server_id=? AND user_id=?',[$sid,$who])||!one('SELECT 1 FROM roles WHERE id=? AND server_id=?',[$role,$sid]))fail(400,'Choose a member and role from this server.');if(($in['assigned']??null)===true)sql('INSERT OR IGNORE INTO member_roles VALUES(?,?,?)',[$sid,$who,$role]);elseif(($in['assigned']??null)===false)sql('DELETE FROM member_roles WHERE server_id=? AND user_id=? AND role_id=?',[$sid,$who,$role]);else fail(400,'Invalid assignment.');break;
 case 'channel_access':
  $cid=text_field($in,'channel_id',32,32);$c=channel($cid);if(!$c['server_id'])fail(400,'Choose a server channel.');require_permission($c['server_id'],'manage_channels');return ['members'=>array_column(sql('SELECT user_id FROM channel_members WHERE channel_id=?',[$cid])->fetchAll(),'user_id'),'roles'=>array_column(sql('SELECT role_id FROM channel_roles WHERE channel_id=?',[$cid])->fetchAll(),'role_id')];
 case 'channel_delete':
  $cid=text_field($in,'channel_id',32,32);$c=channel($cid);if(!$c['server_id'])fail(400,'Choose a server channel.');require_permission($c['server_id'],'manage_channels');transaction(function()use($cid,$c){sql('DELETE FROM signals WHERE channel_id=?',[$cid]);sql('DELETE FROM channels WHERE id=?',[$cid]);sql('INSERT INTO audit(actor,server_id,action,target,created) VALUES(?,?,?,?,?)',[uid(),$c['server_id'],'delete-channel',0,time()]);});break;
 default:fail(404,'Unknown community action.');
 }return ['ok'=>true];
}
