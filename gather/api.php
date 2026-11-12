<?php
declare(strict_types=1);
ini_set('display_errors','0');
require __DIR__.'/lib.php';
require __DIR__.'/customization.php';
require __DIR__.'/community.php';
require __DIR__.'/conversations.php';
require __DIR__.'/notifications.php';
header('Content-Type: application/json; charset=utf-8');header('Cache-Control: no-store');header('X-Content-Type-Options: nosniff');header('X-Robots-Tag: noindex, nofollow, noarchive');
$db=null;
try {
 $test=getenv('GATHER_TEST')==='1';
 if(!$test&&strtolower((string)($_SERVER['HTTPS']??''))!=='on'&&(int)($_SERVER['SERVER_PORT']??0)!==443)fail(400,'HTTPS is required.');
 if($_SERVER['REQUEST_METHOD']!=='POST')fail(405,'Use POST.');
 if(stripos($_SERVER['CONTENT_TYPE']??'','application/json')!==0)fail(415,'JSON is required.');
 $raw=file_get_contents('php://input',false,null,0,5650001);if(strlen($raw)>5650000)fail(413,'Request too large.');
 try{$in=json_decode($raw,true,32,JSON_THROW_ON_ERROR);}catch(Throwable){fail(400,'Invalid JSON.');}if(!is_array($in))fail(400,'Invalid request.');
 $action=$in['action']??'';if(!is_string($action))fail(400,'Invalid action.');if(!in_array($action,['upload_image','attachment_upload'],true)&&strlen($raw)>($action==='nickname'?1450000:70000))fail(413,'Request too large.');
 $privateDir=getenv('GATHER_DATA_DIR')?:dirname(__DIR__,2).'/gather-private';
 if(!is_dir($privateDir)&&!mkdir($privateDir,0700,true))fail(503,'Storage is unavailable.');
 $root=realpath($_SERVER['DOCUMENT_ROOT']??__DIR__);$resolved=realpath($privateDir);
 if(!$test&&$root&&str_starts_with(str_replace('\\','/',strtolower($resolved)).'/',rtrim(str_replace('\\','/',strtolower($root)),'/').'/'))fail(503,'Private storage must be outside the website root.');
 $db=new PDO('sqlite:'.$privateDir.'/gather.sqlite',null,null,[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC]);
 $db->exec('PRAGMA foreign_keys=ON; PRAGMA busy_timeout=5000; PRAGMA journal_mode=WAL;');
 if((int)$db->query('PRAGMA user_version')->fetchColumn()===0)$db->exec(file_get_contents(__DIR__.'/schema.sql'));
 migrate_customization();migrate_community();migrate_conversations();migrate_notifications();migrate_presence();migrate_navigation();migrate_hierarchy();migrate_moderation_history();migrate_moderation_permissions();migrate_automatic_presence();migrate_sessions();migrate_status_expiry();migrate_server_profiles();migrate_server_avatars();migrate_server_profile_details();migrate_notification_preferences();migrate_server_notifications();migrate_notification_mutes();migrate_server_notification_mutes();migrate_category_notifications();migrate_role_alerts();migrate_category_notification_mutes();migrate_everyone_alerts();migrate_empty_categories();migrate_category_identity();migrate_category_overrides();migrate_category_sync();migrate_category_collapse();migrate_server_layout();migrate_profile_notes();migrate_call_audio_state();migrate_pin_permission();migrate_thread_slowmode();migrate_thread_permission();migrate_thread_lifecycle();migrate_thread_auto_archive();migrate_thread_archive_default();migrate_thread_defaults();migrate_thread_creator();migrate_thread_membership();migrate_private_threads();migrate_thread_member_revision();migrate_private_thread_permission();
 $ip=$_SERVER['REMOTE_ADDR']??'unknown';
 if(random_int(1,100)===1){sql('DELETE FROM sessions WHERE expires<?',[time()]);sql('DELETE FROM signals WHERE created<?',[time()-180]);sql('DELETE FROM calls WHERE seen<?',[time()-45]);sql('DELETE FROM limits WHERE window<?',[time()-172800]);sql('DELETE FROM typing WHERE seen<?',[time()-30]);}
 if($action==='health'){echo json_encode(['ok'=>true,'version'=>'0.138.0']);exit;}
 if(in_array($action,['register','login','recover'],true)){
  rate('auth-ip:'.$ip,30,600);$username=strtolower(text_field($in,'username',3,24));if(!preg_match('/^[a-z0-9_]+$/',$username))fail(400,'Username may contain letters, numbers and underscores.');rate('auth-name:'.$username,15,600);
  $password=$in['password']??'';if(!is_string($password)||strlen($password)<12||strlen($password)>128)fail(400,'Use a password of 12–128 bytes.');
  if($action==='register'){
   rate('signup:'.$ip,5,3600);$name=text_field($in,'name',1,32);$recovery=bin2hex(random_bytes(20));
   $result=transaction(function()use($username,$password,$name,$recovery){if(one('SELECT 1 FROM users WHERE username=?',[$username]))fail(409,'Username unavailable.');sql('INSERT INTO users(username,name,password,recovery,created) VALUES(?,?,?,?,?)',[$username,$name,password_hash($password,PASSWORD_DEFAULT),hash('sha256',$recovery),time()]);global $db;$id=(int)$db->lastInsertId();return ['token'=>session_for($id),'recovery'=>$recovery,'user'=>['id'=>$id,'username'=>$username,'name'=>$name]];});
  }else{
   $u=one('SELECT * FROM users WHERE username=?',[$username]);
   if($action==='recover'){$code=$in['recovery']??'';if(!$u||!is_string($code)||!hash_equals($u['recovery'],hash('sha256',$code)))fail(401,'Invalid username or recovery code.');$newRecovery=bin2hex(random_bytes(20));transaction(function()use($password,$newRecovery,$u){sql('UPDATE users SET password=?,recovery=? WHERE id=?',[password_hash($password,PASSWORD_DEFAULT),hash('sha256',$newRecovery),$u['id']]);sql('DELETE FROM sessions WHERE user_id=?',[$u['id']]);});$result=['token'=>session_for((int)$u['id']),'recovery'=>$newRecovery,'user'=>public_user($u)];}
   else{if(!$u||!password_verify($password,$u['password']))fail(401,'Invalid username or password.');$result=['token'=>session_for((int)$u['id']),'user'=>public_user($u)];}
  }echo json_encode($result,JSON_THROW_ON_ERROR);exit;
 }
 $authorization=$_SERVER['HTTP_AUTHORIZATION']??$_SERVER['REDIRECT_HTTP_AUTHORIZATION']??'';
 if(!preg_match('/^Bearer ([a-f0-9]{64})$/',$authorization,$match))fail(401,'Please sign in.');$tokenHash=hash('sha256',$match[1]);
 $user=one('SELECT u.* FROM users u JOIN sessions s ON s.user_id=u.id WHERE s.hash=? AND s.expires>?',[$tokenHash,time()]);if(!$user)fail(401,'Session expired. Please sign in.');
 rate('requests:'.uid(),240,60);archive_due_threads(is_string($in['channel_id']??null)?$in['channel_id']:null);$result=['ok'=>true];
 switch($action){
 case 'session_list':case 'session_revoke':$result=session_actions($action,$in);break;
 case 'category_notification_preference':case 'server_notification_preference':case 'notification_preference':case 'inbox':case 'inbox_read':case 'mention_members':case 'reaction_members':case 'message_context':$result=notification_actions($action,$in);break;
 case 'group_create':case 'group_members':case 'group_update':case 'group_leave':case 'thread_member_profile':case 'thread_members':case 'thread_membership':case 'private_thread_members':case 'private_thread_members_save':case 'private_thread_create':case 'thread_create':case 'thread_archive':case 'thread_settings':case 'channel_move_preview':case 'channel_sync_preview':case 'channel_sync':case 'category_permissions':case 'category_permissions_apply':case 'override_list':case 'override_save':case 'attachment_upload':case 'attachment_get':case 'attachment_discard':$result=conversation_actions($action,$in);break;
 case 'call_disconnect_preview':case 'call_disconnect':case 'audit_list':case 'ban_list':case 'unban':case 'role_move':case 'transfer_owner':case 'moderate':case 'profile_note':case 'profile_note_save':case 'server_layout':case 'category_collapse':case 'category_rename':case 'category_create':case 'category_delete':case 'category_order':case 'channel_reorder':case 'status':case 'channel_move':case 'friend_request':case 'relationship':case 'read':case 'typing':case 'server_thread_defaults':case 'role_list':case 'role_save':case 'role_delete':case 'role_assign':case 'channel_access':case 'channel_delete':case 'call_decline':$result=community($action,$in);break;
 case 'logout':transaction(function()use($tokenHash){sql('DELETE FROM sessions WHERE hash=?',[$tokenHash]);refresh_presence(uid());sql('DELETE FROM calls WHERE user_id=?',[uid()]);});break;
 case 'profile':case 'nickname':case 'server_settings':case 'channel_settings':case 'emoji':case 'media':case 'upload_image':$result=customization($action,$in);break;
 case 'password':$old=$in['old']??'';$new=$in['password']??'';rate('password:'.uid(),5,600);if(!is_string($old)||!password_verify($old,$user['password']))fail(403,'Current password is incorrect.');if(!is_string($new)||strlen($new)<12||strlen($new)>128)fail(400,'Use a password of 12–128 bytes.');transaction(function()use($new,$tokenHash){sql('UPDATE users SET password=? WHERE id=?',[password_hash($new,PASSWORD_DEFAULT),uid()]);sql('DELETE FROM sessions WHERE user_id=? AND hash<>?',[uid(),$tokenHash]);});break;
 case 'state':
  presence_heartbeat($in);
  $servers=sql('SELECT s.*,m.role,m.muted_until,m.nickname,m.server_bio,m.profile_revision,m.server_avatar,m.server_banner,m.server_pronouns FROM servers s JOIN members m ON m.server_id=s.id WHERE m.user_id=? ORDER BY s.created',[uid()])->fetchAll();
  $channels=sql("SELECT c.*,COALESCE((SELECT position FROM category_order o WHERE o.server_id=c.server_id AND o.name=c.category),2147483647) AS category_position FROM channels c WHERE c.server_id IN (SELECT server_id FROM members WHERE user_id=?) OR (c.server_id IS NULL AND c.id IN (SELECT channel_id FROM channel_members WHERE user_id=?)) ORDER BY c.position,c.created,c.id",[uid(),uid()])->fetchAll();
  $channels=array_values(array_filter($channels,function($c){try{channel($c['id']);return true;}catch(ApiError $e){if($e->status===403)return false;throw $e;}}));
  $threadCreators=[];$threadMembership=[];$threadIds=array_column(array_filter($channels,fn($c)=>$c['parent_id']),'id');
  foreach(array_chunk($threadIds,500) as $ids){$placeholders=implode(',',array_fill(0,count($ids),'?'));
   foreach(sql('SELECT channel_id,count(*) AS total,max(user_id=?) AS joined FROM thread_members WHERE channel_id IN ('.$placeholders.') GROUP BY channel_id',array_merge([uid()],$ids))->fetchAll() as $membership)$threadMembership[$membership['channel_id']]=$membership;
   foreach(sql('SELECT c.id AS channel_id,u.id,u.username,u.name,m.nickname FROM channels c JOIN members m ON m.server_id=c.server_id AND m.user_id=c.thread_creator_id JOIN users u ON u.id=m.user_id WHERE c.id IN ('.$placeholders.')',$ids)->fetchAll() as $creator)$threadCreators[$creator['channel_id']]=array_intersect_key($creator,array_flip(['id','username','name','nickname']));
  }

  $serverNotificationMutes=array_column(sql('SELECT * FROM server_notification_mutes WHERE user_id=?',[uid()])->fetchAll(),null,'server_id');
  $categoryNotificationMutes=[];foreach(sql('SELECT * FROM category_notification_mutes WHERE user_id=?',[uid()])->fetchAll() as $row)$categoryNotificationMutes[$row['server_id']][$row['category']]=$row;
  $categoryCollapse=[];foreach(sql('SELECT * FROM category_collapse_preferences WHERE user_id=?',[uid()])->fetchAll() as $row)$categoryCollapse[$row['server_id']][$row['category']]=$row;
  $categoryNotificationModes=[];foreach(sql('SELECT server_id,category,mode FROM category_notification_preferences WHERE user_id=?',[uid()])->fetchAll() as $row)$categoryNotificationModes[$row['server_id']][$row['category']]=$row['mode'];
  $serverNotificationPreferences=array_column(sql('SELECT * FROM server_notification_preferences WHERE user_id=?',[uid()])->fetchAll(),null,'server_id');$serverNotificationModes=array_column($serverNotificationPreferences,'mode','server_id');
  foreach($servers as &$s){$s+=notification_mute_fields($serverNotificationMutes[$s['id']]??null);$s['notification_mode']=$serverNotificationModes[$s['id']]??null;$s['suppress_everyone']=(bool)($serverNotificationPreferences[$s['id']]['suppress_everyone']??false);$s['suppress_roles']=(bool)($serverNotificationPreferences[$s['id']]['suppress_roles']??false);$s['categories']=server_categories($s['id']);foreach($s['categories'] as &$category){$name=$category['name'];$collapse=$categoryCollapse[$s['id']][$name]??null;$category['collapsed']=$collapse?(bool)$collapse['collapsed']:null;$category['collapse_revision']=(int)($collapse['revision']??0);$category['category_notification_mode']=$categoryNotificationModes[$s['id']][$name]??null;foreach(notification_mute_fields($categoryNotificationMutes[$s['id']][$name]??null) as $key=>$value)$category['category_'.$key]=$value;}unset($category);$s['permissions']=permissions_for($s['id']);$s['role_rank']=role_rank($s['id'],uid());}unset($s);
  $notificationSummary=inbox_summary($channels);
  $notificationMutes=array_column(sql('SELECT * FROM notification_mutes WHERE user_id=?',[uid()])->fetchAll(),null,'channel_id');
  $notificationModes=array_column(sql('SELECT channel_id,mode FROM notification_preferences WHERE user_id=?',[uid()])->fetchAll(),'mode','channel_id');
  foreach($channels as &$c){
   $serverMute=$serverNotificationMutes[$c['server_id']]??null;$c['server_notification_muted']=(int)($serverMute['muted_until']??0)>time();$c['server_notification_muted_until']=(int)($serverMute['muted_until']??0);
   $mute=$notificationMutes[$c['id']]??null;$c+=notification_mute_fields($mute);$c['notification_mention_id']=$notificationSummary[$c['id']]['alert_id']??0;
   $c['server_suppress_everyone']=(bool)($serverNotificationPreferences[$c['server_id']]['suppress_everyone']??false);$c['server_suppress_roles']=(bool)($serverNotificationPreferences[$c['server_id']]['suppress_roles']??false);$c['server_notification_mode']=$c['server_id']?($serverNotificationModes[$c['server_id']]??null):null;$c['notification_mode']=$notificationModes[$c['id']]??null;$c['last_mention_id']=$notificationSummary[$c['id']]['last_id']??0;
   $c['mention_count']=$notificationSummary[$c['id']]['total']??0;$c['first_mention_id']=$notificationSummary[$c['id']]['first_id']??null;
   if(!$c['server_id']&&!$c['group_owner']){$peer=one('SELECT u.* FROM users u JOIN channel_members m ON m.user_id=u.id WHERE m.channel_id=? AND u.id<>?',[$c['id'],uid()]);$c['peer']=$peer?public_user($peer):null;$c['name']=$peer['name']??'Conversation';$c['blocked']=$peer?blocked((int)$peer['id']):false;}
   if($c['parent_id']){$parent=channel($c['parent_id']);$c['category']=$parent['category'];}
   $categoryMute=$categoryNotificationMutes[$c['server_id']][$c['category']]??null;foreach(notification_mute_fields($categoryMute) as $key=>$value)$c['category_'.$key]=$value;
   $c['category_notification_mode']=$c['server_id']?($categoryNotificationModes[$c['server_id']][$c['category']]??null):null;
   if($c['parent_id']){$c['thread_creator']=$threadCreators[$c['id']]??null;$c['thread_joined']=(bool)($threadMembership[$c['id']]['joined']??false);$c['thread_member_count']=(int)($threadMembership[$c['id']]['total']??0);$c['can_change_membership']=(bool)(!$c['archived']&&member($c['server_id'])['muted_until']<=time());}
   $c['capabilities']=channel_capabilities($c);$c['can_manage_thread_members']=(bool)(!empty($c['thread_private'])&&!$c['archived']&&$c['capabilities']['manage_threads']&&member($c['server_id'])['muted_until']<=time());$c['can_create_private_thread']=(bool)($c['server_id']&&!$c['parent_id']&&$c['kind']==='text'&&$c['capabilities']['create_private_threads']&&member($c['server_id'])['muted_until']<=time());if($c['parent_id'])$c['can_edit_thread']=(bool)($c['capabilities']['manage_threads']||(!$c['archived']&&thread_creator_controls($c)));$c['can_reopen']=(bool)($c['parent_id']&&$c['archived']&&($c['capabilities']['manage_threads']||(!$c['locked']&&$c['capabilities']['send']&&member($c['server_id'])['muted_until']<=time())));$c['can_reopen_send']=(bool)($c['can_reopen']&&$c['capabilities']['send']&&member($c['server_id'])['muted_until']<=time());if($c['archived']||thread_message_locked($c))$c['capabilities']['send']=false;
   $c['last_id']=(int)sql('SELECT COALESCE(max(id),0) FROM messages WHERE channel_id=?',[$c['id']])->fetchColumn();
   $unread=one('SELECT count(*) AS total,min(m.id) AS first_id,max(m.id) AS last_id,max(CASE WHEN m.created>=? AND m.id>? THEN m.id END) AS alert_id FROM messages m WHERE m.channel_id=? AND m.deleted=0 AND m.user_id<>? AND m.id>COALESCE((SELECT last_id FROM read_states WHERE user_id=? AND channel_id=?),0) AND NOT EXISTS(SELECT 1 FROM blocks b WHERE (b.user_id=? AND b.target=m.user_id) OR (b.target=? AND b.user_id=m.user_id))',[max((int)($mute['muted_until']??0),(int)($serverMute['muted_until']??0),(int)($categoryMute['muted_until']??0)),max((int)($mute['silent_through']??0),(int)($serverMute['silent_through']??0),(int)($categoryMute['silent_through']??0)),$c['id'],uid(),uid(),$c['id'],uid(),uid()]);
   $c['notification_alert_id']=(int)($unread['alert_id']??0);$c['last_unread_id']=(int)($unread['last_id']??0);$c['unread']=(int)$unread['total'];$c['first_unread_id']=$unread['first_id']===null?null:(int)$unread['first_id'];
   $c['participants']=sql('SELECT c.user_id,u.name,COALESCE(NULLIF(m.server_avatar,\'\'),u.avatar) AS avatar FROM calls c JOIN users u ON u.id=c.user_id JOIN channels ch ON ch.id=c.channel_id LEFT JOIN members m ON m.user_id=u.id AND m.server_id=ch.server_id WHERE c.channel_id=? AND c.seen>?',[$c['id'],time()-45])->fetchAll();
  }unset($c);
  $result=['user'=>public_user($user)+['status'=>$user['status'],'custom_status_expires_at'=>custom_status($user)!==''?(int)$user['custom_status_expires_at']:0,'status_revision'=>(int)$user['status_revision']],'servers'=>$servers,'channels'=>$channels,'relationships'=>relationship_state(),'inbox_unread'=>array_sum(array_column($notificationSummary,'total'))]+server_layout_state();break;
 case 'create_server':
  rate('create-server:'.uid(),5,3600);$name=text_field($in,'name',1,40);if(sql('SELECT count(*) FROM members WHERE user_id=?',[uid()])->fetchColumn()>=30)fail(409,'Server limit reached.');
  $result=transaction(function()use($name){$id=random_id();$text=random_id();sql('INSERT INTO servers(id,name,owner_id,created) VALUES(?,?,?,?)',[$id,$name,uid(),time()]);sql('INSERT INTO members(server_id,user_id,role) VALUES(?,?,?)',[$id,uid(),'owner']);sql('INSERT INTO channels(id,server_id,name,kind,created) VALUES(?,?,?,?,?)',[$text,$id,'general','text',time()]);sql('INSERT INTO channels(id,server_id,name,kind,created) VALUES(?,?,?,?,?)',[random_id(),$id,'The living room','voice',time()]);return ['server_id'=>$id,'channel_id'=>$text];});break;
 case 'create_channel':
  $sid=text_field($in,'server_id',32,32);require_permission($sid,'manage_channels');$name=text_field($in,'name',1,32);$kind=$in['kind']??'text';if(!in_array($kind,['text','voice'],true))fail(400,'Invalid channel type.');
  $private=($in['private']??false)===true;$allowed=$in['members']??[];if(!is_array($allowed)||count($allowed)>100)fail(400,'Invalid member list.');
  $result=transaction(function()use($sid,$name,$kind,$private,$allowed,$in){
   require_permission($sid,'manage_channels');if(sql('SELECT count(*) FROM channels WHERE server_id=?',[$sid])->fetchColumn()>=50)fail(409,'Channel limit reached.');
   $sync=$in['sync_category']??false;if(!is_bool($sync))fail(400,'Choose whether to sync category overrides.');$category='';
   if(array_key_exists('category_id',$in)){
    $category=text_field($in,'category_name',1,40);$categoryId=text_field($in,'category_id',32,32);$preview=category_permission_snapshot($sid,$category,$categoryId);
    if(!hash_equals($preview['snapshot'],text_field($in,'category_snapshot',64,64)))fail(409,'Category changed. Select it again to refresh the preview.');
   }elseif($sync||array_key_exists('category_name',$in)||array_key_exists('category_snapshot',$in))fail(400,'Choose a category first.');
   $id=random_id();sql('INSERT INTO channels(id,server_id,name,kind,private,created,position,category) VALUES(?,?,?,?,?,?,?,?)',[$id,$sid,$name,$kind,$private?1:0,time(),(int)sql('SELECT COALESCE(max(position),0)+1 FROM channels WHERE server_id=?',[$sid])->fetchColumn(),$category]);
   foreach(array_unique(array_merge($allowed,[uid()])) as $who){positive_id($who);if(!one('SELECT 1 FROM members WHERE server_id=? AND user_id=?',[$sid,$who]))fail(400,'Choose members from this server.');sql('INSERT INTO channel_members VALUES(?,?)',[$id,$who]);}
   $created=one('SELECT * FROM channels WHERE id=?',[$id]);audit_changes($sid,'channel-create',channel_audit_label($created),[],channel_audit_snapshot($created)+['kind'=>$kind],channel_audit_fields()+['kind'=>'Type']);
   if($sync)apply_channel_sync($created,channel_sync_preview($created),$in);return ['channel_id'=>$id];
  });break;
 case 'invite':$sid=text_field($in,'server_id',32,32);require_permission($sid,'invite');rate('invite:'.uid(),20,3600);$result=transaction(function()use($sid){require_permission($sid,'invite');$code=bin2hex(random_bytes(16));$expires=time()+7*86400;sql('INSERT INTO invites VALUES(?,?,?,?,?)',[hash('sha256',$code),$sid,uid(),$expires,10]);hierarchy_audit($sid,'invite-create',0,'Server ['.$sid.'] · Created invite: 10 uses, expires '.gmdate('Y-m-d H:i:s',$expires).' UTC.');return ['code'=>$code,'expires'=>$expires,'uses'=>10];});break;
 case 'revoke_invites':$sid=text_field($in,'server_id',32,32);transaction(function()use($sid){require_permission($sid,'invite');$count=sql('DELETE FROM invites WHERE server_id=?',[$sid])->rowCount();if($count)hierarchy_audit($sid,'invite-revoke',0,'Server ['.$sid.'] · Revoked '.$count.' invite(s).');});break;
 case 'join':
  rate('join:'.uid(),15,600);$code=text_field($in,'code',32,32);
  $result=transaction(function()use($code){$hash=hash('sha256',$code);$i=one('SELECT * FROM invites WHERE hash=? AND expires>? AND remaining>0',[$hash,time()]);if(!$i)fail(404,'Invite is invalid, used up or expired.');$sid=$i['server_id'];if(one('SELECT 1 FROM bans WHERE server_id=? AND user_id=?',[$sid,uid()]))fail(403,'You cannot join this server.');if(!one('SELECT 1 FROM members WHERE server_id=? AND user_id=?',[$sid,uid()])){if(sql('SELECT count(*) FROM members WHERE server_id=?',[$sid])->fetchColumn()>=100)fail(409,'This server is full.');if(sql('SELECT count(*) FROM members WHERE user_id=?',[uid()])->fetchColumn()>=30)fail(409,'Server limit reached.');sql('INSERT INTO members(server_id,user_id) VALUES(?,?)',[$sid,uid()]);sql('UPDATE invites SET remaining=remaining-1 WHERE hash=?',[$hash]);}return ['server_id'=>$sid];});break;
 case 'members':$sid=text_field($in,'server_id',32,32);member($sid);$result=['members'=>array_map(fn($m)=>public_user(array_replace($m,['bio'=>$m['server_bio']!==''?$m['server_bio']:$m['bio'],'avatar'=>$m['server_avatar']!==''?$m['server_avatar']:$m['avatar'],'banner'=>$m['server_banner']!==''?$m['server_banner']:$m['banner'],'pronouns'=>$m['server_pronouns']!==''?$m['server_pronouns']:$m['pronouns']]))+['role_rank'=>role_rank($sid,(int)$m['id'])]+array_intersect_key($m,array_flip(['role','muted_until','nickname','role_color'])),sql('SELECT u.*,m.role,m.muted_until,m.nickname,m.server_bio,m.server_avatar,m.server_banner,m.server_pronouns,(SELECT r.color FROM roles r JOIN member_roles mr ON mr.role_id=r.id WHERE mr.user_id=u.id AND mr.server_id=m.server_id AND length(r.color)>0 ORDER BY r.position DESC,r.id LIMIT 1) AS role_color FROM members m JOIN users u ON u.id=m.user_id WHERE m.server_id=? ORDER BY u.name',[$sid])->fetchAll()),'emoji'=>sql("SELECT id,substr(slot,7) AS name FROM media WHERE server_id=? AND slot LIKE 'emoji:%' ORDER BY slot",[$sid])->fetchAll()];break;
 case 'leave_server':$sid=text_field($in,'server_id',32,32);$m=member($sid);if($m['role']==='owner')fail(409,'The owner cannot leave their server.');transaction(function()use($sid){sql('DELETE FROM channel_members WHERE user_id=? AND channel_id IN(SELECT id FROM channels WHERE server_id=?)',[uid(),$sid]);sql('DELETE FROM calls WHERE user_id=? AND channel_id IN(SELECT id FROM channels WHERE server_id=?)',[uid(),$sid]);sql('DELETE FROM members WHERE server_id=? AND user_id=?',[$sid,uid()]);});break;
 case 'dm':
  $other=positive_id($in['user_id']??null);if($other===uid())fail(400,'Choose another member.');if(blocked($other))fail(403,'This conversation is unavailable.');if(!friends($other)&&!one('SELECT 1 FROM members a JOIN members b ON a.server_id=b.server_id WHERE a.user_id=? AND b.user_id=?',[uid(),$other]))fail(403,'Add this person as a friend or share a server to message them.');
  $result=transaction(function()use($other){$ids=[uid(),$other];sort($ids);$key=implode(':',$ids);$c=one('SELECT id FROM channels WHERE pair_key=?',[$key]);if($c)return ['channel_id'=>$c['id']];$id=random_id();sql('INSERT INTO channels(id,name,kind,private,pair_key,created) VALUES(?,?,?,?,?,?)',[$id,'Direct message','text',1,$key,time()]);foreach($ids as $u)sql('INSERT INTO channel_members VALUES(?,?)',[$id,$u]);return ['channel_id'=>$id];});break;
 case 'messages':$cid=text_field($in,'channel_id',32,32);channel($cid);$before=isset($in['before'])?positive_id($in['before']):0;$after=isset($in['after'])?positive_id($in['after']):0;if($before&&$after)fail(400,'Choose one history direction.');if($after&&!one('SELECT 1 FROM messages WHERE id=? AND channel_id=?',[$after,$cid]))fail(400,'History cursor must belong to this conversation.');$search=isset($in['search'])?text_field($in,'search',0,100):'';$result=['messages'=>messages_for($cid,$before,$search,($in['pins']??false)===true,$after),'typing'=>sql('SELECT u.id,u.name FROM typing t JOIN users u ON u.id=t.user_id WHERE t.channel_id=? AND t.user_id<>? AND t.seen>? AND t.user_id NOT IN (SELECT target FROM blocks WHERE user_id=?)',[$cid,uid(),time()-7,uid()])->fetchAll()];break;
 case 'send':$result=send_message($in);break;
 case 'delete':
  $id=positive_id($in['id']??null);rate('message-action:'.uid(),60,60);
  transaction(function()use($id){
   $msg=one('SELECT * FROM messages WHERE id=? AND deleted=0',[$id]);if(!$msg)fail(404,'Message not found.');$c=channel($msg['channel_id']);$other=(int)$msg['user_id']!==uid();
   if($c['server_id']){if(member($c['server_id'])['muted_until']>time())fail(403,'You cannot delete messages while timed out.');if($c['parent_id']&&($c['archived']||($c['auto_archive_minutes']>0&&$c['thread_activity']+$c['auto_archive_minutes']*60<=time()))&&thread_message_locked($c))fail(403,'Only thread managers can delete messages in a locked archived thread.');}else channel($c['id'],true);
   if($other){if(!$c['server_id'])fail(403,'You can only change your own message.');require_permission($c['server_id'],'manage_messages');}
   sql("UPDATE messages SET deleted=1,body='' WHERE id=?",[$id]);sql('DELETE FROM attachments WHERE message_id=?',[$id]);sql('DELETE FROM notifications WHERE message_id=?',[$id]);
   if($other)hierarchy_audit($c['server_id'],'message-delete',(int)$msg['user_id'],'Message ['.$id.'] · Channel ['.$c['id'].'] · Server ['.$c['server_id'].']');
  });break;
 case 'edit':
  $id=positive_id($in['id']??null);rate('message-action:'.uid(),60,60);
  transaction(function()use($id,$in){
   $msg=one('SELECT * FROM messages WHERE id=? AND deleted=0',[$id]);if(!$msg)fail(404,'Message not found.');require_thread_message_write(channel($msg['channel_id'],true));
   if((int)$msg['user_id']!==uid())fail(403,'You can only edit your own message.');$body=text_field($in,'body',1,4000);
   // shortcut: older clients omit original_body; require it after retiring legacy releases.
   if(array_key_exists('original_body',$in)){
    $original=$in['original_body'];if(!is_string($original)||!preg_match('//u',$original)||preg_match_all('/./us',$original)>4000||preg_match('/[\x00-\x08\x0B\x0C\x0E-\x1F]/',$original))fail(400,'Invalid original message.');
    if($original!==$msg['body'])fail(409,'This message changed after you opened it. Review the latest message before saving again.');
   }
   sql('UPDATE messages SET body=?,edited=? WHERE id=?',[$body,time(),$id]);
  });break;
 case 'pin':
  $id=positive_id($in['id']??null);if(array_key_exists('pinned',$in)&&!is_bool($in['pinned']))fail(400,'Invalid pin state.');rate('message-action:'.uid(),60,60);
  transaction(function()use($id,$in){
   $msg=one('SELECT * FROM messages WHERE id=? AND deleted=0',[$id]);if(!$msg)fail(404,'Message not found.');$c=channel($msg['channel_id']);
   if($c['server_id']){if(member($c['server_id'])['muted_until']>time())fail(403,'You cannot change pins while timed out.');if(!channel_capabilities($c)['pin_messages'])fail(403,'You do not have permission to pin messages in this channel.');
    if($c['parent_id']){if($c['archived']||($c['auto_archive_minutes']>0&&$c['thread_activity']+$c['auto_archive_minutes']*60<=time()))fail(403,'Reopen this thread before changing pins.');if(thread_message_locked($c))fail(403,'Only thread managers can change pins in a locked thread.');}
   }else{channel($c['id'],true);if((int)$msg['user_id']!==uid())fail(403,'You can only pin your own direct message.');}
   // shortcut: legacy clients omit pinned and toggle; remove after retiring those clients.
   $pinned=array_key_exists('pinned',$in)?(int)$in['pinned']:1-(int)$msg['pinned'];sql('UPDATE messages SET pinned=? WHERE id=?',[$pinned,$id]);
  });break;
 case 'react':
  $id=positive_id($in['id']??null);$msg=one('SELECT * FROM messages WHERE id=? AND deleted=0',[$id]);if(!$msg)fail(404,'Message not found.');$c=channel($msg['channel_id']);rate('message-action:'.uid(),60,60);react_message($id,$c,$in);break;
 case 'rtc':$cid=text_field($in,'channel_id',32,32);$c=channel($cid,true,'connect');if($c['kind']!=='voice'&&$c['server_id'])fail(400,'Choose a voice room or direct conversation.');relay_allowed($c);$result=rtc_config();break;
 case 'call_join':
  $cid=text_field($in,'channel_id',32,32);$c=channel($cid,true,'connect');if($c['kind']!=='voice'&&$c['server_id'])fail(400,'Choose a voice room or direct conversation.');relay_allowed($c);if(!rtc_ready()&&!$test)fail(503,'Calls are waiting for the server relay configuration.');rate('call-join:'.uid(),15,60);
  $result=transaction(function()use($cid,$in){sql('DELETE FROM calls WHERE seen<?',[time()-45]);sql('DELETE FROM calls WHERE user_id=?',[uid()]);if(sql('SELECT count(*) FROM calls WHERE channel_id=?',[$cid])->fetchColumn()>=6)fail(409,'This room is full (6 people).');$id=random_id();sql('INSERT INTO calls(id,channel_id,user_id,seen,media_version) VALUES(?,?,?,?,?)',[$id,$cid,uid(),time(),($in['media_version']??1)===2?2:1]);return ['call_id'=>$id];});break;
 case 'call_leave':$id=text_field($in,'call_id',32,32);sql('DELETE FROM calls WHERE id=? AND user_id=?',[$id,uid()]);break;
 case 'call_poll':
  $id=text_field($in,'call_id',32,32);$audio=call_audio_state($in);$cursor=$in['cursor']??0;if(!is_int($cursor)||$cursor<0)fail(400,'Invalid cursor.');$call=transaction(function()use($id,$audio){$call=call_record($id);if($audio===null)sql('UPDATE calls SET seen=? WHERE id=? AND user_id=?',[time(),$id,uid()]);else sql('UPDATE calls SET seen=?,audio_state=? WHERE id=? AND user_id=?',[time(),$audio,$id,uid()]);return $call;});
  $participants=sql('SELECT c.id,c.user_id,c.media_version,c.audio_state,u.name,COALESCE(NULLIF(m.server_avatar,\'\'),u.avatar) AS avatar FROM calls c JOIN users u ON u.id=c.user_id JOIN channels ch ON ch.id=c.channel_id LEFT JOIN members m ON m.user_id=u.id AND m.server_id=ch.server_id WHERE c.channel_id=? AND c.seen>?',[$call['channel_id'],time()-45])->fetchAll();$room=channel($call['channel_id']);$self=null;$peers=[];foreach($participants as $participant){$participant['audio_state']=$participant['audio_state']===null?null:json_decode($participant['audio_state'],true);$participant['can_disconnect']=$room['server_id']?can_disconnect_member($room['server_id'],(int)$participant['user_id']):false;if($participant['id']===$id)$self=$participant;else $peers[]=$participant;}
  $result=['self'=>$self,'peers'=>$peers,'signals'=>sql('SELECT s.id,s.from_call,s.payload FROM signals s JOIN calls c ON c.id=s.from_call WHERE s.to_call=? AND s.id>? AND s.created>? AND c.seen>? ORDER BY s.id LIMIT 100',[$id,$cursor,time()-180,time()-45])->fetchAll()];break;
 case 'signal':
  $id=text_field($in,'call_id',32,32);$call=call_record($id);$to=text_field($in,'to',32,32);if($to===$id||!one('SELECT 1 FROM calls WHERE id=? AND channel_id=? AND seen>?',[$to,$call['channel_id'],time()-45]))fail(404,'Peer has left.');
  $payload=$in['payload']??null;if(!is_array($payload)||!in_array($payload['type']??'',['offer','answer','candidate'],true))fail(400,'Invalid signal.');$json=json_encode($payload,JSON_THROW_ON_ERROR);if(strlen($json)>60000)fail(413,'Signal too large.');sql('INSERT INTO signals(channel_id,from_call,to_call,payload,created) VALUES(?,?,?,?,?)',[$call['channel_id'],$id,$to,$json,time()]);break;
 default:fail(404,'Unknown action.');
 }
 echo json_encode($result,JSON_THROW_ON_ERROR|JSON_INVALID_UTF8_SUBSTITUTE);
}catch(ApiError $e){http_response_code($e->status);echo json_encode(['error'=>$e->getMessage()]);}
catch(Throwable $e){error_log('Gather API error: '.get_class($e));http_response_code(503);echo json_encode(['error'=>'The server is temporarily unavailable. Please retry.']);}
