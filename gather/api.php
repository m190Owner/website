<?php
declare(strict_types=1);
ini_set('display_errors','0');
require __DIR__.'/lib.php';
require __DIR__.'/customization.php';
header('Content-Type: application/json; charset=utf-8');header('Cache-Control: no-store');header('X-Content-Type-Options: nosniff');header('X-Robots-Tag: noindex, nofollow, noarchive');
$db=null;
try {
 $test=getenv('GATHER_TEST')==='1';
 if(!$test&&strtolower((string)($_SERVER['HTTPS']??''))!=='on'&&(int)($_SERVER['SERVER_PORT']??0)!==443)fail(400,'HTTPS is required.');
 if($_SERVER['REQUEST_METHOD']!=='POST')fail(405,'Use POST.');
 if(stripos($_SERVER['CONTENT_TYPE']??'','application/json')!==0)fail(415,'JSON is required.');
 $raw=file_get_contents('php://input',false,null,0,750001);if(strlen($raw)>750000)fail(413,'Request too large.');
 try{$in=json_decode($raw,true,32,JSON_THROW_ON_ERROR);}catch(Throwable){fail(400,'Invalid JSON.');}if(!is_array($in))fail(400,'Invalid request.');
 $action=$in['action']??'';if(!is_string($action))fail(400,'Invalid action.');if($action!=='upload_image'&&strlen($raw)>70000)fail(413,'Request too large.');
 $privateDir=getenv('GATHER_DATA_DIR')?:dirname(__DIR__,2).'/gather-private';
 if(!is_dir($privateDir)&&!mkdir($privateDir,0700,true))fail(503,'Storage is unavailable.');
 $root=realpath($_SERVER['DOCUMENT_ROOT']??__DIR__);$resolved=realpath($privateDir);
 if(!$test&&$root&&str_starts_with(str_replace('\\','/',strtolower($resolved)).'/',rtrim(str_replace('\\','/',strtolower($root)),'/').'/'))fail(503,'Private storage must be outside the website root.');
 $db=new PDO('sqlite:'.$privateDir.'/gather.sqlite',null,null,[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC]);
 $db->exec('PRAGMA foreign_keys=ON; PRAGMA busy_timeout=5000; PRAGMA journal_mode=WAL;');
 if((int)$db->query('PRAGMA user_version')->fetchColumn()===0)$db->exec(file_get_contents(__DIR__.'/schema.sql'));
 migrate_customization();
 $ip=$_SERVER['REMOTE_ADDR']??'unknown';
 if(random_int(1,100)===1){sql('DELETE FROM sessions WHERE expires<?',[time()]);sql('DELETE FROM signals WHERE created<?',[time()-180]);sql('DELETE FROM calls WHERE seen<?',[time()-45]);sql('DELETE FROM limits WHERE window<?',[time()-172800]);}
 if($action==='health'){echo json_encode(['ok'=>true,'version'=>'0.3.0']);exit;}
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
 rate('requests:'.uid(),240,60);$result=['ok'=>true];
 switch($action){
 case 'logout':sql('DELETE FROM sessions WHERE hash=?',[$tokenHash]);sql('DELETE FROM calls WHERE user_id=?',[uid()]);break;
 case 'profile':case 'nickname':case 'server_settings':case 'channel_settings':case 'emoji':case 'media':case 'upload_image':$result=customization($action,$in);break;
 case 'password':$old=$in['old']??'';$new=$in['password']??'';rate('password:'.uid(),5,600);if(!is_string($old)||!password_verify($old,$user['password']))fail(403,'Current password is incorrect.');if(!is_string($new)||strlen($new)<12||strlen($new)>128)fail(400,'Use a password of 12–128 bytes.');transaction(function()use($new,$tokenHash){sql('UPDATE users SET password=? WHERE id=?',[password_hash($new,PASSWORD_DEFAULT),uid()]);sql('DELETE FROM sessions WHERE user_id=? AND hash<>?',[uid(),$tokenHash]);});break;
 case 'state':
  if((int)$user['last_seen']<time()-15)sql('UPDATE users SET last_seen=? WHERE id=?',[time(),uid()]);
  $servers=sql('SELECT s.*,m.role,m.muted_until,m.nickname FROM servers s JOIN members m ON m.server_id=s.id WHERE m.user_id=? ORDER BY s.created',[uid()])->fetchAll();
  $channels=sql("SELECT c.* FROM channels c WHERE (c.server_id IN (SELECT server_id FROM members WHERE user_id=?) AND (c.private=0 OR c.server_id IN (SELECT id FROM servers WHERE owner_id=?) OR c.id IN (SELECT channel_id FROM channel_members WHERE user_id=?))) OR (c.server_id IS NULL AND c.id IN (SELECT channel_id FROM channel_members WHERE user_id=?)) ORDER BY c.created",[uid(),uid(),uid(),uid()])->fetchAll();
  foreach($channels as &$c){if(!$c['server_id']){$peer=one('SELECT u.* FROM users u JOIN channel_members m ON m.user_id=u.id WHERE m.channel_id=? AND u.id<>?',[$c['id'],uid()]);$c['peer']=$peer?public_user($peer):null;$c['name']=$peer['name']??'Conversation';}}unset($c);
  $result=['user'=>public_user($user),'servers'=>$servers,'channels'=>$channels];break;
 case 'create_server':
  rate('create-server:'.uid(),5,3600);$name=text_field($in,'name',1,40);if(sql('SELECT count(*) FROM members WHERE user_id=?',[uid()])->fetchColumn()>=30)fail(409,'Server limit reached.');
  $result=transaction(function()use($name){$id=random_id();$text=random_id();sql('INSERT INTO servers(id,name,owner_id,created) VALUES(?,?,?,?)',[$id,$name,uid(),time()]);sql('INSERT INTO members(server_id,user_id,role) VALUES(?,?,?)',[$id,uid(),'owner']);sql('INSERT INTO channels(id,server_id,name,kind,created) VALUES(?,?,?,?,?)',[$text,$id,'general','text',time()]);sql('INSERT INTO channels(id,server_id,name,kind,created) VALUES(?,?,?,?,?)',[random_id(),$id,'The living room','voice',time()]);return ['server_id'=>$id,'channel_id'=>$text];});break;
 case 'create_channel':
  $sid=text_field($in,'server_id',32,32);member($sid,true);$name=text_field($in,'name',1,32);$kind=$in['kind']??'text';if(!in_array($kind,['text','voice'],true))fail(400,'Invalid channel type.');
  if(sql('SELECT count(*) FROM channels WHERE server_id=?',[$sid])->fetchColumn()>=50)fail(409,'Channel limit reached.');$private=($in['private']??false)===true;$allowed=$in['members']??[];if(!is_array($allowed)||count($allowed)>100)fail(400,'Invalid member list.');
  $result=transaction(function()use($sid,$name,$kind,$private,$allowed){$id=random_id();sql('INSERT INTO channels(id,server_id,name,kind,private,created) VALUES(?,?,?,?,?,?)',[$id,$sid,$name,$kind,$private?1:0,time()]);foreach(array_unique(array_merge($allowed,[uid()])) as $who){positive_id($who);if(!one('SELECT 1 FROM members WHERE server_id=? AND user_id=?',[$sid,$who]))fail(400,'Choose members from this server.');sql('INSERT INTO channel_members VALUES(?,?)',[$id,$who]);}return ['channel_id'=>$id];});break;
 case 'invite':$sid=text_field($in,'server_id',32,32);member($sid,true);rate('invite:'.uid(),20,3600);$code=bin2hex(random_bytes(16));sql('INSERT INTO invites VALUES(?,?,?,?,?)',[hash('sha256',$code),$sid,uid(),time()+7*86400,10]);$result=['code'=>$code,'expires'=>time()+7*86400,'uses'=>10];break;
 case 'revoke_invites':$sid=text_field($in,'server_id',32,32);member($sid,true);sql('DELETE FROM invites WHERE server_id=?',[$sid]);break;
 case 'join':
  rate('join:'.uid(),15,600);$code=text_field($in,'code',32,32);
  $result=transaction(function()use($code){$hash=hash('sha256',$code);$i=one('SELECT * FROM invites WHERE hash=? AND expires>? AND remaining>0',[$hash,time()]);if(!$i)fail(404,'Invite is invalid, used up or expired.');$sid=$i['server_id'];if(one('SELECT 1 FROM bans WHERE server_id=? AND user_id=?',[$sid,uid()]))fail(403,'You cannot join this server.');if(!one('SELECT 1 FROM members WHERE server_id=? AND user_id=?',[$sid,uid()])){if(sql('SELECT count(*) FROM members WHERE server_id=?',[$sid])->fetchColumn()>=100)fail(409,'This server is full.');if(sql('SELECT count(*) FROM members WHERE user_id=?',[uid()])->fetchColumn()>=30)fail(409,'Server limit reached.');sql('INSERT INTO members(server_id,user_id) VALUES(?,?)',[$sid,uid()]);sql('UPDATE invites SET remaining=remaining-1 WHERE hash=?',[$hash]);}return ['server_id'=>$sid];});break;
 case 'members':$sid=text_field($in,'server_id',32,32);member($sid);$result=['members'=>sql('SELECT u.id,u.username,u.name,u.avatar,u.banner,u.bio,u.pronouns,u.last_seen,m.role,m.muted_until,m.nickname FROM members m JOIN users u ON u.id=m.user_id WHERE m.server_id=? ORDER BY u.name',[$sid])->fetchAll(),'emoji'=>sql("SELECT id,substr(slot,7) AS name FROM media WHERE server_id=? AND slot LIKE 'emoji:%' ORDER BY slot",[$sid])->fetchAll()];break;
 case 'moderate':
  $sid=text_field($in,'server_id',32,32);$actor=member($sid,true);$target=positive_id($in['user_id']??null);$op=$in['operation']??'';if($target===uid())fail(400,'You cannot moderate yourself.');$victim=one('SELECT * FROM members WHERE server_id=? AND user_id=?',[$sid,$target]);if(!$victim)fail(404,'Member not found.');if($victim['role']==='owner'||($victim['role']==='moderator'&&$actor['role']!=='owner'))fail(403,'You cannot moderate that member.');
  transaction(function()use($sid,$actor,$target,$op){if(in_array($op,['kick','ban'],true)){if($op==='ban')sql('INSERT OR IGNORE INTO bans VALUES(?,?)',[$sid,$target]);sql('DELETE FROM channel_members WHERE user_id=? AND channel_id IN (SELECT id FROM channels WHERE server_id=?)',[$target,$sid]);sql('DELETE FROM calls WHERE user_id=? AND channel_id IN (SELECT id FROM channels WHERE server_id=?)',[$target,$sid]);sql('DELETE FROM members WHERE server_id=? AND user_id=?',[$sid,$target]);}elseif(in_array($op,['mute','unmute'],true)){sql('UPDATE members SET muted_until=? WHERE server_id=? AND user_id=?',[$op==='mute'?time()+3600:0,$sid,$target]);if($op==='mute')sql('DELETE FROM calls WHERE user_id=? AND channel_id IN (SELECT id FROM channels WHERE server_id=?)',[$target,$sid]);}elseif(in_array($op,['promote','demote'],true)&&$actor['role']==='owner'){sql('UPDATE members SET role=? WHERE server_id=? AND user_id=?',[$op==='promote'?'moderator':'member',$sid,$target]);}else fail(400,'Invalid moderation action.');sql('INSERT INTO audit(actor,server_id,action,target,created) VALUES(?,?,?,?,?)',[uid(),$sid,$op,$target,time()]);});break;
 case 'leave_server':$sid=text_field($in,'server_id',32,32);$m=member($sid);if($m['role']==='owner')fail(409,'The owner cannot leave their server.');transaction(function()use($sid){sql('DELETE FROM channel_members WHERE user_id=? AND channel_id IN(SELECT id FROM channels WHERE server_id=?)',[uid(),$sid]);sql('DELETE FROM calls WHERE user_id=? AND channel_id IN(SELECT id FROM channels WHERE server_id=?)',[uid(),$sid]);sql('DELETE FROM members WHERE server_id=? AND user_id=?',[$sid,uid()]);});break;
 case 'dm':
  $other=positive_id($in['user_id']??null);if($other===uid())fail(400,'Choose another member.');if(!one('SELECT 1 FROM members a JOIN members b ON a.server_id=b.server_id WHERE a.user_id=? AND b.user_id=?',[uid(),$other]))fail(403,'You must share a server to start a conversation.');
  $result=transaction(function()use($other){$ids=[uid(),$other];sort($ids);$key=implode(':',$ids);$c=one('SELECT id FROM channels WHERE pair_key=?',[$key]);if($c)return ['channel_id'=>$c['id']];$id=random_id();sql('INSERT INTO channels(id,name,kind,private,pair_key,created) VALUES(?,?,?,?,?,?)',[$id,'Direct message','text',1,$key,time()]);foreach($ids as $u)sql('INSERT INTO channel_members VALUES(?,?)',[$id,$u]);return ['channel_id'=>$id];});break;
 case 'messages':$cid=text_field($in,'channel_id',32,32);channel($cid);$before=isset($in['before'])?positive_id($in['before']):0;$search=isset($in['search'])?text_field($in,'search',0,100):'';$result=['messages'=>messages_for($cid,$before,$search,($in['pins']??false)===true)];break;
 case 'send':
  $cid=text_field($in,'channel_id',32,32);$c=channel($cid,true);if($c['kind']!=='text')fail(400,'Use a text channel.');rate('send:'.uid(),30,60);$body=text_field($in,'body',1,4000);$client=text_field($in,'client_id',16,64);
  sql('INSERT OR IGNORE INTO messages(channel_id,user_id,body,created,client_id) VALUES(?,?,?,?,?)',[$cid,uid(),$body,time(),$client]);$result=['id'=>(int)sql('SELECT id FROM messages WHERE user_id=? AND client_id=?',[uid(),$client])->fetchColumn()];break;
 case 'edit':case 'delete':case 'pin':case 'react':
  $id=positive_id($in['id']??null);$msg=one('SELECT * FROM messages WHERE id=? AND deleted=0',[$id]);if(!$msg)fail(404,'Message not found.');$c=channel($msg['channel_id'],true);rate('message-action:'.uid(),60,60);
  if($action==='react'){transaction(function()use($id){if(one('SELECT 1 FROM reactions WHERE message_id=? AND user_id=?',[$id,uid()]))sql('DELETE FROM reactions WHERE message_id=? AND user_id=?',[$id,uid()]);else sql('INSERT INTO reactions VALUES(?,?)',[$id,uid()]);});}
  elseif($action==='edit'){if((int)$msg['user_id']!==uid())fail(403,'You can only edit your own message.');sql('UPDATE messages SET body=?,edited=? WHERE id=?',[text_field($in,'body',1,4000),time(),$id]);}
  else{if((int)$msg['user_id']!==uid()){if(!$c['server_id'])fail(403,'You can only change your own message.');member($c['server_id'],true);}if($action==='delete')sql("UPDATE messages SET deleted=1,body='' WHERE id=?",[$id]);else sql('UPDATE messages SET pinned=1-pinned WHERE id=?',[$id]);}break;
 case 'rtc':$cid=text_field($in,'channel_id',32,32);$c=channel($cid,true);if($c['kind']!=='voice')fail(400,'Choose a voice room.');relay_allowed($c);$result=rtc_config();break;
 case 'call_join':
  $cid=text_field($in,'channel_id',32,32);$c=channel($cid,true);if($c['kind']!=='voice')fail(400,'Choose a voice room.');relay_allowed($c);if(!rtc_ready()&&!$test)fail(503,'Calls are waiting for the server relay configuration.');rate('call-join:'.uid(),15,60);
  $result=transaction(function()use($cid){sql('DELETE FROM calls WHERE seen<?',[time()-45]);sql('DELETE FROM calls WHERE user_id=?',[uid()]);if(sql('SELECT count(*) FROM calls WHERE channel_id=?',[$cid])->fetchColumn()>=6)fail(409,'This room is full (6 people).');$id=random_id();sql('INSERT INTO calls VALUES(?,?,?,?)',[$id,$cid,uid(),time()]);return ['call_id'=>$id];});break;
 case 'call_leave':$id=text_field($in,'call_id',32,32);sql('DELETE FROM calls WHERE id=? AND user_id=?',[$id,uid()]);break;
 case 'call_poll':
  $id=text_field($in,'call_id',32,32);$call=call_record($id);sql('UPDATE calls SET seen=? WHERE id=?',[time(),$id]);$cursor=$in['cursor']??0;if(!is_int($cursor)||$cursor<0)fail(400,'Invalid cursor.');
  $result=['peers'=>sql('SELECT c.id,c.user_id,u.name FROM calls c JOIN users u ON u.id=c.user_id WHERE c.channel_id=? AND c.id<>? AND c.seen>?',[$call['channel_id'],$id,time()-45])->fetchAll(),'signals'=>sql('SELECT s.id,s.from_call,s.payload FROM signals s JOIN calls c ON c.id=s.from_call WHERE s.to_call=? AND s.id>? AND s.created>? AND c.seen>? ORDER BY s.id LIMIT 100',[$id,$cursor,time()-180,time()-45])->fetchAll()];break;
 case 'signal':
  $id=text_field($in,'call_id',32,32);$call=call_record($id);$to=text_field($in,'to',32,32);if($to===$id||!one('SELECT 1 FROM calls WHERE id=? AND channel_id=? AND seen>?',[$to,$call['channel_id'],time()-45]))fail(404,'Peer has left.');
  $payload=$in['payload']??null;if(!is_array($payload)||!in_array($payload['type']??'',['offer','answer','candidate'],true))fail(400,'Invalid signal.');$json=json_encode($payload,JSON_THROW_ON_ERROR);if(strlen($json)>60000)fail(413,'Signal too large.');sql('INSERT INTO signals(channel_id,from_call,to_call,payload,created) VALUES(?,?,?,?,?)',[$call['channel_id'],$id,$to,$json,time()]);break;
 default:fail(404,'Unknown action.');
 }
 echo json_encode($result,JSON_THROW_ON_ERROR|JSON_INVALID_UTF8_SUBSTITUTE);
}catch(ApiError $e){http_response_code($e->status);echo json_encode(['error'=>$e->getMessage()]);}
catch(Throwable $e){error_log('Gather API error: '.get_class($e));http_response_code(503);echo json_encode(['error'=>'The server is temporarily unavailable. Please retry.']);}
