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
function migrate_automatic_presence(): void {
 global $db;if((int)$db->query('PRAGMA user_version')->fetchColumn()>=11)return;
 transaction(function()use($db){if((int)$db->query('PRAGMA user_version')->fetchColumn()>=11)return;
  $db->exec("ALTER TABLE sessions ADD COLUMN seen INTEGER NOT NULL DEFAULT 0; ALTER TABLE sessions ADD COLUMN idle INTEGER NOT NULL DEFAULT 0; ALTER TABLE users ADD COLUMN auto_idle INTEGER NOT NULL DEFAULT 0; CREATE INDEX sessions_presence ON sessions(user_id,expires,seen); PRAGMA user_version=11;");
 });
}
function refresh_presence(int $id): void {
 $recent=one('SELECT MAX(seen) AS seen,MAX(CASE WHEN idle=0 THEN 1 ELSE 0 END) AS active FROM sessions WHERE user_id=? AND expires>? AND seen>=?',[$id,time(),time()-45]);
 $seen=(int)($recent['seen']??0);$idle=$recent['active']?0:1;
 sql('UPDATE users SET last_seen=?,auto_idle=? WHERE id=? AND (last_seen<>? OR auto_idle<>?)',[$seen,$idle,$id,$seen,$idle]);
}
function presence_heartbeat(array $in): void {
 global $user,$tokenHash;$idle=array_key_exists('idle',$in)?$in['idle']:false;if(!is_bool($idle))fail(400,'Idle must be true or false.');
 transaction(function()use($idle,$tokenHash){sql('UPDATE sessions SET seen=?,idle=? WHERE hash=? AND (seen<? OR idle<>?)',[time(),(int)$idle,$tokenHash,time()-15,(int)$idle]);refresh_presence(uid());});
 $user=one('SELECT * FROM users WHERE id=?',[uid()]);
}
function presence(array $u): string {return $u['status']==='invisible'||(int)$u['last_seen']<time()-45?'offline':($u['status']==='online'&&!empty($u['auto_idle'])?'idle':$u['status']);}
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
function migrate_moderation_history(): void {
 global $db;if((int)$db->query('PRAGMA user_version')->fetchColumn()>=9)return;
 transaction(function()use($db){if((int)$db->query('PRAGMA user_version')->fetchColumn()>=9)return;$db->exec('CREATE INDEX audit_server_page ON audit(server_id,id DESC); CREATE INDEX audit_ban_lookup ON audit(server_id,target,action,id DESC); PRAGMA user_version=9;');});
}
function moderation_permissions(): array {return ['timeout_members','kick_members','ban_members'];}
function expand_moderation(array $permissions): array {
 if(in_array('moderate_members',$permissions,true))$permissions=array_merge(array_diff($permissions,['moderate_members']),moderation_permissions());return array_values(array_unique($permissions));
}
function legacy_moderation(array $permissions): array {return array_diff(moderation_permissions(),$permissions)?$permissions:array_merge($permissions,['moderate_members']);}
function migrate_moderation_permissions(): void {
 global $db;if((int)$db->query('PRAGMA user_version')->fetchColumn()>=10)return;
 transaction(function()use($db){if((int)$db->query('PRAGMA user_version')->fetchColumn()>=10)return;foreach(sql('SELECT id,permissions FROM roles')->fetchAll() as $role){$old=json_decode($role['permissions'],true);$next=expand_moderation($old);if($old!==$next)sql('UPDATE roles SET permissions=? WHERE id=?',[json_encode($next),$role['id']]);}$db->exec('PRAGMA user_version=10;');});
}
function audit_actions(): array {return ['mute','unmute','kick','ban','unban','promote','demote','role-create','role-edit','role-move','role-delete','role-assign','role-remove','ownership-transfer','delete-channel','server-edit','channel-create','channel-edit','channel-access','invite-create','invite-revoke','server-image','emoji-create','emoji-edit','emoji-delete','message-delete','thread-create','thread-archive','thread-reopen','channel-move','category-move'];}
function permission_names(): array {return ['manage_channels','manage_messages','invite','manage_server','manage_roles','timeout_members','kick_members','ban_members','view_audit_log','mention_roles','mention_everyone'];}
function permissions_for(string $sid): array {
 $m=member($sid);if($m['role']==='owner')return legacy_moderation(permission_names());
 $result=$m['role']==='moderator'?['manage_channels','manage_messages','invite','manage_server','timeout_members','kick_members','ban_members','view_audit_log']:[];
 foreach(sql('SELECT r.permissions FROM roles r JOIN member_roles m ON m.role_id=r.id WHERE m.server_id=? AND m.user_id=?',[$sid,uid()])->fetchAll() as $r)$result=array_merge($result,json_decode($r['permissions'],true));return legacy_moderation(array_values(array_unique($result)));
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
function audit_value(mixed $value): string {
 $text=json_encode($value,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR);preg_match_all('/./us',$text,$chars);return count($chars[0])>240?implode('',array_slice($chars[0],0,240)).'…':$text;
}
function audit_changes(string $sid,string $action,string $label,array $before,array $after,array $fields): bool {
 $changes=[];foreach($fields as $key=>$name)if(($before[$key]??null)!==($after[$key]??null))$changes[]=$name.': '.audit_value($before[$key]??null).' → '.audit_value($after[$key]??null);
 if(!$changes)return false;hierarchy_audit($sid,$action,0,$label."\n".implode("\n",$changes));return true;
}
function channel_audit_snapshot(array $c): array {
 return ['name'=>$c['name'],'topic'=>$c['topic'],'category'=>$c['category'],'slowmode'=>(int)$c['slowmode'],'private'=>(bool)$c['private'],'readonly'=>(bool)$c['readonly'],'members'=>array_map('intval',array_column(sql('SELECT user_id FROM channel_members WHERE channel_id=? ORDER BY user_id',[$c['id']])->fetchAll(),'user_id')),'roles'=>array_column(sql('SELECT role_id FROM channel_roles WHERE channel_id=? ORDER BY role_id',[$c['id']])->fetchAll(),'role_id')];
}
function channel_audit_fields(): array {return ['name'=>'Name','topic'=>'Topic','category'=>'Category','slowmode'=>'Slow mode (seconds)','private'=>'Private','readonly'=>'Read only','members'=>'Allowed member IDs','roles'=>'Allowed role IDs'];}
function channel_audit_label(array $c): string {return '#'.$c['name'].' ['.$c['id'].']';}
function channel_order_snapshot(array $c): array {
 $ids=array_column(sql('SELECT id FROM channels WHERE server_id=? AND parent_id IS NULL AND kind=? AND category=? ORDER BY position,created,id',[$c['server_id'],$c['kind'],$c['category']])->fetchAll(),'id');
 return ['category'=>$c['category'],'position'=>array_search($c['id'],$ids,true)+1];
}
function thread_audit_label(array $c): string {return 'Thread '.$c['name'].' ['.$c['id'].'] · Parent ['.$c['parent_id'].'] · Root message ['.$c['root_message'].']';}
function role_audit_snapshot(array $role): array {
 $permissions=array_values(array_unique(json_decode($role['permissions'],true)));sort($permissions,SORT_STRING);
 return ['name'=>$role['name'],'color'=>$role['color'],'permissions'=>$permissions,'position'=>(int)$role['position']];
}
function role_audit_fields(): array {return ['name'=>'Name','color'=>'Color','permissions'=>'Permissions','position'=>'Position (higher is above)'];}
function role_audit_label(array $role): string {return 'Role '.$role['name'].' ['.$role['id'].']';}
function disconnect_role(string $sid,string $role): void {sql('DELETE FROM calls WHERE channel_id IN(SELECT id FROM channels WHERE server_id=?) AND user_id IN(SELECT user_id FROM member_roles WHERE server_id=? AND role_id=?)',[$sid,$sid,$role]);}
function relationship_state(): array {
 $result=[];foreach(sql('SELECT u.*,f.sender,f.accepted FROM friendships f JOIN users u ON u.id=CASE WHEN f.a=? THEN f.b ELSE f.a END WHERE f.a=? OR f.b=?',[uid(),uid(),uid()])->fetchAll() as $u)$result[]=public_user($u)+['relationship'=>$u['accepted']?'friend':((int)$u['sender']===uid()?'outgoing':'incoming'),'online'=>presence($u)!=='offline'];
 foreach(sql('SELECT u.* FROM blocks b JOIN users u ON u.id=b.target WHERE b.user_id=?',[uid()])->fetchAll() as $u)$result[]=public_user($u)+['relationship'=>'blocked','online'=>false];return $result;
}
function community(string $action,array $in): array {
 switch($action){
 case 'audit_list':case 'ban_list':
  $sid=text_field($in,'server_id',32,32);require_permission($sid,$action==='audit_list'?'view_audit_log':'ban_members');$before=$in['before']??0;if($before!==0)positive_id($before);
  if($action==='audit_list'){$filter=$in['filter']??'';if(!is_string($filter)||($filter!==''&&!in_array($filter,audit_actions(),true)))fail(400,'Choose a valid audit action.');$where='a.server_id=?';$args=[$sid];if($before){$where.=' AND a.id<?';$args[]=$before;}if($filter!==''){$where.=' AND a.action=?';$args[]=$filter;}
   $rows=sql('SELECT a.id,a.action,a.target,a.created,a.detail,a.actor,u.name AS actor_name,u.username AS actor_username,t.name AS target_name,t.username AS target_username FROM audit a LEFT JOIN users u ON u.id=a.actor LEFT JOIN users t ON t.id=a.target WHERE '.$where.' ORDER BY a.id DESC LIMIT 51',$args)->fetchAll();$more=count($rows)>50;$rows=array_slice($rows,0,50);return ['entries'=>$rows,'next'=>$more?$rows[49]['id']:null];
  }
  $rows=sql("SELECT b.user_id,u.name,u.username,a.detail AS reason,a.created AS banned_at,actor.name AS actor_name,actor.username AS actor_username FROM bans b JOIN users u ON u.id=b.user_id LEFT JOIN audit a ON a.id=(SELECT id FROM audit WHERE server_id=b.server_id AND target=b.user_id AND action='ban' ORDER BY id DESC LIMIT 1) LEFT JOIN users actor ON actor.id=a.actor WHERE b.server_id=?".($before?' AND b.user_id<?':'').' ORDER BY b.user_id DESC LIMIT 51',$before?[$sid,$before]:[$sid])->fetchAll();$more=count($rows)>50;$rows=array_slice($rows,0,50);return ['bans'=>$rows,'next'=>$more?$rows[49]['user_id']:null];
 case 'unban':
  $sid=text_field($in,'server_id',32,32);$target=positive_id($in['user_id']??null);$reason=array_key_exists('reason',$in)?text_field($in,'reason',0,300):'';
  $changed=transaction(function()use($sid,$target,$reason){require_permission($sid,'ban_members');$changed=sql('DELETE FROM bans WHERE server_id=? AND user_id=?',[$sid,$target])->rowCount()>0;if($changed)hierarchy_audit($sid,'unban',$target,$reason);return $changed;});return ['ok'=>true,'changed'=>$changed];
 case 'category_order':
  $sid=text_field($in,'server_id',32,32);require_permission($sid,'manage_channels');$name=text_field($in,'category',1,40);$before=($in['before']??null)===null?null:text_field($in,'before',1,40);
  transaction(function()use($sid,$name,$before){require_permission($sid,'manage_channels');$rows=server_channel_rows($sid);$visible=array_column(visible_channel_rows($rows),'category');if(!in_array($name,$visible,true)||($before!==null&&!in_array($before,$visible,true)))fail(403,'Choose categories you can view in this server.');if($before===$name)return;
   $names=array_values(array_unique(array_filter(array_column($rows,'category'),fn($value)=>$value!=='')));$oldIndex=array_search($name,$names,true);$names=array_values(array_filter($names,fn($value)=>$value!==$name));$index=$before===null?count($names):array_search($before,$names,true);if($index===$oldIndex)return;array_splice($names,$index,0,[$name]);
   sql('DELETE FROM category_order WHERE server_id=?',[$sid]);foreach($names as $position=>$category)sql('INSERT INTO category_order VALUES(?,?,?)',[$sid,$category,$position]);audit_changes($sid,'category-move','Category '.$name.' · Server ['.$sid.']',['position'=>$oldIndex+1],['position'=>$index+1],['position'=>'Category position (1 is first)']);
  });break;
 case 'channel_reorder':
  $id=text_field($in,'channel_id',32,32);$category=text_field($in,'category',0,40);$before=($in['before_id']??null)===null?null:text_field($in,'before_id',32,32);
  transaction(function()use($id,$category,$before){$c=channel($id);if(!$c['server_id']||$c['parent_id'])fail(400,'Choose a server channel.');require_permission($c['server_id'],'manage_channels');
   if($before!==null){$target=channel($before);if($target['server_id']!==$c['server_id']||$target['parent_id']||$target['category']!==$category||$target['kind']!==$c['kind'])fail(400,'Choose a channel of the same type in the destination category.');if($before===$id)return;}
   $oldOrder=channel_order_snapshot($c);$ids=array_column(sql('SELECT id FROM channels WHERE server_id=? AND parent_id IS NULL AND kind=? AND category=? AND id<>? ORDER BY position,created,id',[$c['server_id'],$c['kind'],$category,$id])->fetchAll(),'id');
   $index=$before===null?count($ids):array_search($before,$ids,true);if($category===$oldOrder['category']&&$index+1===$oldOrder['position'])return;array_splice($ids,$index,0,[$id]);sql('UPDATE channels SET category=? WHERE id=?',[$category,$id]);foreach($ids as $position=>$channelId)sql('UPDATE channels SET position=? WHERE id=?',[$position+1,$channelId]);audit_changes($c['server_id'],'channel-move',channel_audit_label($c),$oldOrder,['category'=>$category,'position'=>$index+1],['category'=>'Category','position'=>'Position within type/category (1 is first)']);
  });break;
 case 'status':
  $status=$in['status']??null;if(!in_array($status,['online','idle','dnd','invisible'],true))fail(400,'Choose a valid status.');
  $text=text_field($in,'custom_status',0,100);
  $duration=array_key_exists('clear_after',$in)?$in['clear_after']:'keep';
  if(!in_array($duration,['keep',0,1800,3600,14400,86400],true))fail(400,'Choose a valid status duration.');
  if(array_key_exists('status_revision',$in)&&(!is_int($in['status_revision'])||$in['status_revision']<0))fail(400,'Invalid status revision.');
  transaction(function()use($in,$status,$text,$duration){
   $current=one('SELECT custom_status,custom_status_expires_at,status_revision FROM users WHERE id=?',[uid()]);
   if(array_key_exists('status_revision',$in)&&$in['status_revision']!==(int)$current['status_revision'])fail(409,'Your status changed elsewhere. Close and reopen Set Status before saving.');
   $expires=$duration==='keep'?(int)$current['custom_status_expires_at']:($duration?time()+$duration:0);
   if($text===''||(!array_key_exists('clear_after',$in)&&$text!==$current['custom_status']))$expires=0;
   sql('UPDATE users SET status=?,custom_status=?,custom_status_expires_at=?,status_revision=status_revision+1 WHERE id=?',[$status,$text,$expires,uid()]);
  });break;
 case 'channel_move':
  $id=text_field($in,'channel_id',32,32);$direction=$in['direction']??null;if(!in_array($direction,[-1,1],true))fail(400,'Choose up or down.');
  transaction(function()use($id,$direction){$c=channel($id);if(!$c['server_id']||$c['parent_id'])fail(400,'Choose a server channel.');require_permission($c['server_id'],'manage_channels');
   $rows=sql('SELECT id FROM channels WHERE server_id=? AND parent_id IS NULL AND kind=? AND category=? ORDER BY position,created,id',[$c['server_id'],$c['kind'],$c['category']])->fetchAll();
   $ids=[];foreach($rows as $row){try{channel($row['id']);$ids[]=$row['id'];}catch(ApiError $e){if($e->status!==403)throw $e;}}
   $index=array_search($id,$ids,true);$next=$index+$direction;if($next<0||$next>=count($ids))return;
   $oldOrder=channel_order_snapshot($c);[$ids[$index],$ids[$next]]=[$ids[$next],$ids[$index]];foreach($ids as $position=>$channelId)sql('UPDATE channels SET position=? WHERE id=?',[$position+1,$channelId]);audit_changes($c['server_id'],'channel-move',channel_audit_label($c),$oldOrder,channel_order_snapshot($c),['category'=>'Category','position'=>'Position within type/category (1 is first)']);
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
  $sid=text_field($in,'server_id',32,32);$m=member($sid);return ['roles'=>array_map(function($r){$r['permissions']=json_encode(legacy_moderation(json_decode($r['permissions'],true)));return $r;},sql('SELECT * FROM roles WHERE server_id=? ORDER BY position DESC,id',[$sid])->fetchAll()),'assignments'=>sql('SELECT user_id,role_id FROM member_roles WHERE server_id=?',[$sid])->fetchAll(),'actor'=>['id'=>uid(),'owner'=>$m['role']==='owner','rank'=>role_rank($sid,uid()),'permissions'=>permissions_for($sid)]];
 case 'role_save':
  $sid=text_field($in,'server_id',32,32);require_permission($sid,'manage_roles');$name=text_field($in,'name',1,32);$color=color_field($in,'color');$permissions=$in['permissions']??[];if(!is_array($permissions)||count($permissions)>count(permission_names())+1)fail(400,'Invalid permissions.');foreach($permissions as $permission)if(!is_string($permission)||!in_array($permission,[...permission_names(),'moderate_members'],true))fail(400,'Invalid permissions.');$permissions=expand_moderation($permissions);if(in_array('mention_everyone',$permissions,true)&&($in['permission_version']??0)!==4)fail(400,'Update Gather to grant everyone mentions.');if(isset($in['permission_version'])&&!in_array($in['permission_version'],[2,3,4],true))fail(400,'Invalid permission version.');$id=isset($in['id'])?text_field($in,'id',32,32):random_id();
  transaction(function()use($sid,$name,$color,$permissions,$id,$in){require_permission($sid,'manage_roles');grantable_permissions($sid,$permissions);
   if(isset($in['id'])){$role=managed_role($sid,$id);if(in_array('mention_everyone',json_decode($role['permissions'],true),true)&&($in['permission_version']??0)!==4)fail(409,'Update Gather before editing a role with everyone mention permission.');if(in_array('mention_roles',json_decode($role['permissions'],true),true)&&($in['permission_version']??0)<3)fail(409,'Update Gather before editing a role with role mention permission.');$oldModeration=array_intersect(json_decode($role['permissions'],true),moderation_permissions());if(!isset($in['permission_version'])&&count($oldModeration)>0&&count($oldModeration)<3)fail(409,'Update Gather before editing a role with separate moderation permissions.');$position=$role['position'];if(array_diff(json_decode($role['permissions'],true),$permissions))disconnect_role($sid,$id);}
   else{if(sql('SELECT count(*) FROM roles WHERE server_id=?',[$sid])->fetchColumn()>=30)fail(409,'Role limit reached.');sql('UPDATE roles SET position=position+1 WHERE server_id=?',[$sid]);$position=1;}
   sql('INSERT INTO roles(id,server_id,name,color,permissions,position) VALUES(?,?,?,?,?,?) ON CONFLICT(id) DO UPDATE SET name=excluded.name,color=excluded.color,permissions=excluded.permissions',[$id,$sid,$name,$color,json_encode(array_values(array_unique($permissions))),$position]);$after=one('SELECT * FROM roles WHERE id=? AND server_id=?',[$id,$sid]);audit_changes($sid,isset($in['id'])?'role-edit':'role-create',role_audit_label($after),isset($in['id'])?role_audit_snapshot($role):[],role_audit_snapshot($after),role_audit_fields());
  });return ['id'=>$id];
 case 'role_move':
  $sid=text_field($in,'server_id',32,32);$id=text_field($in,'id',32,32);$target=array_key_exists('target_id',$in)?text_field($in,'target_id',32,32):null;$direction=$in['direction']??null;if($target===null&&!in_array($direction,[-1,1],true))fail(400,'Choose up or down.');
  $expected=$in['expected_order']??null;if(($target!==null||array_key_exists('expected_order',$in))&&(!is_array($expected)||!array_is_list($expected)||count($expected)<1||count($expected)>30))fail(400,'Invalid role order.');
  if(is_array($expected))foreach($expected as $item)if(!is_string($item)||!preg_match('/^[a-f0-9]{32}$/D',$item))fail(400,'Invalid role order.');
  transaction(function()use($sid,$id,$direction,$target,$expected){$role=managed_role($sid,$id);$roles=sql('SELECT id,position FROM roles WHERE server_id=? ORDER BY position DESC,id',[$sid])->fetchAll();$ids=array_column($roles,'id');
   if($expected!==null&&$expected!==$ids)fail(409,'Role order changed. Close and reopen Roles & permissions before moving roles.');
   if($target!==null){managed_role($sid,$target);$from=array_search($id,$ids,true);$to=array_search($target,$ids,true);if($from===$to)return;
    for($i=min($from,$to);$i<=max($from,$to);$i++)managed_role($sid,$ids[$i]);
    array_splice($ids,$from,1);array_splice($ids,$to,0,[$id]);foreach($ids as $i=>$roleId)sql('UPDATE roles SET position=? WHERE id=? AND server_id=?',[$roles[$i]['position'],$roleId,$sid]);
   }else{$next=one('SELECT * FROM roles WHERE server_id=? AND position'.($direction===1?'>':'<').'? ORDER BY position '.($direction===1?'ASC':'DESC').' LIMIT 1',[$sid,$role['position']]);if(!$next)return;managed_role($sid,$next['id']);sql('UPDATE roles SET position=? WHERE id=?',[$next['position'],$id]);sql('UPDATE roles SET position=? WHERE id=?',[$role['position'],$next['id']]);}
   audit_changes($sid,'role-move',role_audit_label($role),role_audit_snapshot($role),role_audit_snapshot(one('SELECT * FROM roles WHERE id=? AND server_id=?',[$id,$sid])),role_audit_fields());});break;
 case 'role_delete':
  $sid=text_field($in,'server_id',32,32);$id=text_field($in,'id',32,32);transaction(function()use($sid,$id){$role=managed_role($sid,$id);disconnect_role($sid,$id);sql('DELETE FROM roles WHERE id=? AND server_id=?',[$id,$sid]);sql('UPDATE roles SET position=position-1 WHERE server_id=? AND position>?',[$sid,$role['position']]);sql('DELETE FROM channel_overrides WHERE kind=? AND subject=? AND channel_id IN(SELECT id FROM channels WHERE server_id=?)',['role',$id,$sid]);audit_changes($sid,'role-delete',role_audit_label($role),role_audit_snapshot($role),[],role_audit_fields());});break;
 case 'role_assign':
  if(array_key_exists('role_ids',$in)){
   $sid=text_field($in,'server_id',32,32);$who=positive_id($in['user_id']??null);$sets=[];
   foreach(['role_ids','expected_roles'] as $key){$values=$in[$key]??null;if(!is_array($values)||!array_is_list($values)||count($values)>30)fail(400,'Invalid role selection.');foreach($values as $value)if(!is_string($value)||!preg_match('/^[a-f0-9]{32}$/D',$value))fail(400,'Invalid role selection.');if(count(array_unique($values))!==count($values))fail(400,'Duplicate role selection.');sort($values,SORT_STRING);$sets[$key]=$values;}
   transaction(function()use($sid,$who,$sets){require_permission($sid,'manage_roles');lower_member($sid,$who);$current=sql('SELECT role_id FROM member_roles WHERE server_id=? AND user_id=? ORDER BY role_id',[$sid,$who])->fetchAll(PDO::FETCH_COLUMN);
    if($current!==$sets['expected_roles'])fail(409,'This member’s roles changed. Close and reopen Assign Roles before saving.');
    $added=array_diff($sets['role_ids'],$current);$removed=array_diff($current,$sets['role_ids']);$changes=[];
    foreach(array_merge($added,$removed) as $id){$role=managed_role($sid,$id);$assigned=in_array($id,$added,true);if($assigned)grantable_permissions($sid,json_decode($role['permissions'],true));$changes[]=[$role,$assigned];}
    foreach($changes as [$role,$assigned]){if($assigned)sql('INSERT INTO member_roles VALUES(?,?,?)',[$sid,$who,$role['id']]);else sql('DELETE FROM member_roles WHERE server_id=? AND user_id=? AND role_id=?',[$sid,$who,$role['id']]);hierarchy_audit($sid,$assigned?'role-assign':'role-remove',$who,$role['name']);}
    if($removed)sql('DELETE FROM calls WHERE user_id=? AND channel_id IN(SELECT id FROM channels WHERE server_id=?)',[$who,$sid]);
   });break;
  }
  $sid=text_field($in,'server_id',32,32);$who=positive_id($in['user_id']??null);$id=text_field($in,'role_id',32,32);$assigned=$in['assigned']??null;if(!is_bool($assigned))fail(400,'Invalid assignment.');
  transaction(function()use($sid,$who,$id,$assigned){$role=managed_role($sid,$id);lower_member($sid,$who);if($assigned){grantable_permissions($sid,json_decode($role['permissions'],true));sql('INSERT OR IGNORE INTO member_roles VALUES(?,?,?)',[$sid,$who,$id]);}else{sql('DELETE FROM member_roles WHERE server_id=? AND user_id=? AND role_id=?',[$sid,$who,$id]);sql('DELETE FROM calls WHERE user_id=? AND channel_id IN(SELECT id FROM channels WHERE server_id=?)',[$who,$sid]);}hierarchy_audit($sid,$assigned?'role-assign':'role-remove',$who,$role['name']);});break;
 case 'transfer_owner':
  $sid=text_field($in,'server_id',32,32);if(member($sid)['role']!=='owner')fail(403,'Only the current owner can transfer ownership.');rate('transfer:'.uid(),10,3600);$who=positive_id($in['user_id']??null);$password=$in['password']??null;if(!is_string($password)||strlen($password)>128)fail(400,'Enter your current password.');$confirmation=text_field($in,'confirmation',3,24);
  transaction(function()use($sid,$who,$password,$confirmation){$actor=member($sid);if($actor['role']!=='owner'||(int)$actor['owner_id']!==uid())fail(403,'Ownership has changed.');if($who===uid())fail(400,'Choose another member.');$target=one('SELECT u.username FROM users u JOIN members m ON m.user_id=u.id WHERE m.server_id=? AND u.id=?',[$sid,$who]);if(!$target)fail(400,'Choose an existing server member.');if($confirmation!==$target['username'])fail(400,'Type the new owner username exactly.');$current=one('SELECT password FROM users WHERE id=?',[uid()]);if(!password_verify($password,$current['password']))fail(403,'Your current password is incorrect.');
   sql('UPDATE members SET role=? WHERE server_id=? AND user_id=?',['member',$sid,uid()]);sql('UPDATE members SET role=?,muted_until=0 WHERE server_id=? AND user_id=?',['owner',$sid,$who]);sql('UPDATE servers SET owner_id=? WHERE id=?',[$who,$sid]);sql('DELETE FROM calls WHERE channel_id IN(SELECT id FROM channels WHERE server_id=?)',[$sid]);hierarchy_audit($sid,'ownership-transfer',$who);
  });break;
 case 'moderate':
  $sid=text_field($in,'server_id',32,32);$target=positive_id($in['user_id']??null);$op=$in['operation']??'';if(!is_string($op)||!in_array($op,['mute','unmute','kick','ban','promote','demote'],true))fail(400,'Invalid moderation action.');$duration=array_key_exists('duration',$in)?$in['duration']:3600;if($op==='mute'&&(!is_int($duration)||!in_array($duration,[60,300,600,3600,86400,604800],true)))fail(400,'Choose a valid timeout duration.');$reason=array_key_exists('reason',$in)?text_field($in,'reason',0,300):'';
  transaction(function()use($sid,$target,$op,$reason,$duration){$actor=member($sid);if(in_array($op,['promote','demote'],true)){if($actor['role']!=='owner')fail(403,'Only the owner can change legacy moderators.');}else require_permission($sid,in_array($op,['mute','unmute'],true)?'timeout_members':($op==='kick'?'kick_members':'ban_members'));if($target===uid())fail(400,'You cannot moderate yourself.');$victim=one('SELECT * FROM members WHERE server_id=? AND user_id=?',[$sid,$target]);if(!$victim)fail(404,'Member not found.');if($victim['role']==='owner')fail(403,'You cannot moderate the owner.');lower_member($sid,$target);
   if(in_array($op,['kick','ban'],true)){if($op==='ban')sql('INSERT OR IGNORE INTO bans VALUES(?,?)',[$sid,$target]);sql('DELETE FROM channel_members WHERE user_id=? AND channel_id IN (SELECT id FROM channels WHERE server_id=?)',[$target,$sid]);sql('DELETE FROM calls WHERE user_id=? AND channel_id IN (SELECT id FROM channels WHERE server_id=?)',[$target,$sid]);sql('DELETE FROM members WHERE server_id=? AND user_id=?',[$sid,$target]);}
   elseif(in_array($op,['mute','unmute'],true)){sql('UPDATE members SET muted_until=? WHERE server_id=? AND user_id=?',[$op==='mute'?time()+$duration:0,$sid,$target]);if($op==='mute')sql('DELETE FROM calls WHERE user_id=? AND channel_id IN (SELECT id FROM channels WHERE server_id=?)',[$target,$sid]);}
   elseif(in_array($op,['promote','demote'],true)&&$actor['role']==='owner')sql('UPDATE members SET role=? WHERE server_id=? AND user_id=?',[$op==='promote'?'moderator':'member',$sid,$target]);else fail(400,'Invalid moderation action.');hierarchy_audit($sid,$op,$target,$op==='mute'?'Timeout: '.$duration.' seconds'.($reason!==''?' · '.$reason:''):$reason);
  });break;
 case 'channel_access':
  $cid=text_field($in,'channel_id',32,32);$c=channel($cid);if(!$c['server_id'])fail(400,'Choose a server channel.');require_permission($c['server_id'],'manage_channels');return ['members'=>array_column(sql('SELECT user_id FROM channel_members WHERE channel_id=?',[$cid])->fetchAll(),'user_id'),'roles'=>array_column(sql('SELECT role_id FROM channel_roles WHERE channel_id=?',[$cid])->fetchAll(),'role_id')];
 case 'channel_delete':
  $cid=text_field($in,'channel_id',32,32);transaction(function()use($cid){$c=channel($cid);if(!$c['server_id'])fail(400,'Choose a server channel.');require_permission($c['server_id'],'manage_channels');sql('DELETE FROM signals WHERE channel_id=?',[$cid]);sql('DELETE FROM channels WHERE id=?',[$cid]);hierarchy_audit($c['server_id'],'delete-channel',0,$c['parent_id']?thread_audit_label($c):channel_audit_label($c));});break;
 default:fail(404,'Unknown community action.');
 }return ['ok'=>true];
}
