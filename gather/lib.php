<?php
declare(strict_types=1);
final class ApiError extends RuntimeException { public function __construct(public int $status,string $message){parent::__construct($message);} }
function fail(int $status,string $message): never {throw new ApiError($status,$message);}
function sql(string $query,array $args=[]): PDOStatement {
 global $db,$authenticated,$writeTransaction;
 // Recheck the caller under the write lock, including routes with a single autocommit write.
 if(!empty($authenticated)&&empty($writeTransaction)&&preg_match('/^\s*(INSERT|UPDATE|DELETE|REPLACE)\b/i',$query))return transaction(fn()=>sql($query,$args));
 $s=$db->prepare($query);$s->execute($args);return $s;
}
function one(string $query,array $args=[]): ?array {return sql($query,$args)->fetch() ?: null;}
function uid(): int {global $user;return (int)$user['id'];}
function random_id(): string {return bin2hex(random_bytes(16));}
function text_field(array $input,string $key,int $min,int $max): string {
 $value=$input[$key]??null;if(!is_string($value)||!preg_match('//u',$value))fail(400,"Invalid $key.");$value=trim($value);
 $length=preg_match_all('/./us',$value);if($length<$min||$length>$max||preg_match('/[\x00-\x08\x0B\x0C\x0E-\x1F]/',$value))fail(400,"$key must be {$min}–{$max} characters.");return $value;
}
function positive_id(mixed $value): int {if(!is_int($value)||$value<1)fail(400,'Invalid ID.');return $value;}
function rate(string $key,int $max,int $seconds): void {
 $key=hash('sha256',$key);$window=(int)floor(time()/$seconds)*$seconds;
 $fetch=fn()=>sql('INSERT INTO limits(key,window,hits) VALUES(?,?,1) ON CONFLICT(key) DO UPDATE SET hits=CASE WHEN window=excluded.window THEN hits+1 ELSE 1 END,window=excluded.window RETURNING hits',[$key,$window])->fetch();
 global $authenticated,$writeTransaction;$r=!empty($authenticated)&&empty($writeTransaction)?transaction($fetch):$fetch();
 if($r['hits']>$max)fail(429,'Too many attempts. Please wait and try again.');
}
function transaction(callable $fn): mixed {
 global $db,$authenticated,$writeTransaction,$tokenHash;
 $db->exec('BEGIN IMMEDIATE');$writeTransaction=true;
 try{if(!empty($authenticated)&&!one('SELECT 1 FROM sessions s JOIN users u ON u.id=s.user_id WHERE s.hash=? AND s.user_id=? AND s.expires>? AND u.disabled_at=0',[$tokenHash,uid(),time()]))fail(401,'Session expired. Please sign in.');$value=$fn();$db->exec('COMMIT');return $value;}
 catch(Throwable $e){$db->exec('ROLLBACK');throw $e;}finally{$writeTransaction=false;}
}
function migrate_account_disable(): void {
 global $db;if((int)$db->query('PRAGMA user_version')->fetchColumn()>=51)return;
 transaction(function()use($db){if((int)$db->query('PRAGMA user_version')->fetchColumn()>=51)return;$db->exec('ALTER TABLE users ADD COLUMN disabled_at INTEGER NOT NULL DEFAULT 0; PRAGMA user_version=51;');});
}
function replace_recovery_code(array $in): array {
 if(array_diff(array_keys($in),['action','password','confirmation'])||($in['confirmation']??null)!=='REPLACE')fail(400,'Confirm replacement of your recovery code.');
 $password=$in['password']??null;if(!is_string($password)||strlen($password)>128)fail(400,'Enter your current password.');rate('recovery-replace:'.uid(),5,600);
 return transaction(function()use($password){
  $current=one('SELECT password FROM users WHERE id=?',[uid()]);if(!password_verify($password,$current['password']))fail(403,'Current password is incorrect.');
  $code=bin2hex(random_bytes(20));sql('UPDATE users SET recovery=? WHERE id=?',[hash('sha256',$code),uid()]);return ['recovery'=>$code];
 });
}
function disable_account(array $in): array {
 if(array_diff(array_keys($in),['action','password','confirmation'])||($in['confirmation']??null)!=='DISABLE')fail(400,'Type DISABLE to confirm.');
 $password=$in['password']??null;if(!is_string($password)||strlen($password)>128)fail(400,'Enter your current password.');rate('account-disable:'.uid(),5,600);
 return transaction(function()use($password){
  $current=one('SELECT password FROM users WHERE id=?',[uid()]);if(!password_verify($password,$current['password']))fail(403,'Current password is incorrect.');
  if(one('SELECT 1 FROM servers WHERE owner_id=?',[uid()]))fail(409,'Transfer ownership of every server you own before disabling your account.');
  sql('UPDATE users SET disabled_at=?,last_seen=0,auto_idle=1 WHERE id=?',[time(),uid()]);
  sql('DELETE FROM sessions WHERE user_id=?',[uid()]);sql('DELETE FROM signals WHERE from_call IN (SELECT id FROM calls WHERE user_id=?) OR to_call IN (SELECT id FROM calls WHERE user_id=?)',[uid(),uid()]);sql('DELETE FROM calls WHERE user_id=?',[uid()]);sql('DELETE FROM typing WHERE user_id=?',[uid()]);
  return ['ok'=>true];
 });
}
function member(string $server,bool $moderate=false): array {
 $m=one('SELECT m.*,s.owner_id FROM members m JOIN servers s ON s.id=m.server_id WHERE m.server_id=? AND m.user_id=?',[$server,uid()]);
 if(!$m)fail(403,'You do not belong to this server.');if($moderate&&!in_array($m['role'],['owner','moderator'],true))fail(403,'Moderator permission required.');return $m;
}
function channel(string $id,bool $write=false,string $permission='send'): array {
 $c=one('SELECT * FROM channels WHERE id=?',[$id]);if(!$c)fail(404,'Channel not found.');
 if($c['parent_id']){$parent=channel($c['parent_id'],$write,$write?'send_in_threads':$permission);if(!empty($c['thread_private'])&&!channel_capabilities($c)['view'])fail(403,'This thread is private.');if($write&&$permission!=='send')channel($c['parent_id'],true,$permission);$c['category']=$parent['category'];if($write&&($c['archived']||($c['auto_archive_minutes']>0&&$c['thread_activity']+$c['auto_archive_minutes']*60<=time())))fail(403,'This thread is archived.');return $c;}
 if($c['server_id']){$m=member($c['server_id']);if($write&&$m['muted_until']>time())fail(403,'You are timed out in this server until '.gmdate('Y-m-d H:i:s',(int)$m['muted_until']).' UTC.');$p=channel_capabilities($c);if(!$p['view']||($write&&!$p[$permission]))fail(403,'You do not have permission in this channel.');
 }else{if(!one('SELECT 1 FROM channel_members WHERE channel_id=? AND user_id=?',[$id,uid()]))fail(403,'This conversation is private.');if($write&&$c['pair_key']){$other=(int)sql('SELECT user_id FROM channel_members WHERE channel_id=? AND user_id<>?',[$id,uid()])->fetchColumn();if(blocked($other))fail(403,'This conversation is unavailable.');}}return $c;
}
function migrate_sessions(): void {
 global $db;if((int)$db->query('PRAGMA user_version')->fetchColumn()>=12)return;
 transaction(function()use($db){if((int)$db->query('PRAGMA user_version')->fetchColumn()>=12)return;
  $db->exec("ALTER TABLE sessions ADD COLUMN public_id TEXT NOT NULL DEFAULT ''; ALTER TABLE sessions ADD COLUMN created INTEGER NOT NULL DEFAULT 0;");
  foreach(sql('SELECT hash FROM sessions')->fetchAll() as $session)sql('UPDATE sessions SET public_id=? WHERE hash=?',[random_id(),$session['hash']]);
  $db->exec('CREATE UNIQUE INDEX sessions_public_id ON sessions(public_id); PRAGMA user_version=12;');
 });
}
function migrate_session_devices(): void {
 global $db;if((int)$db->query('PRAGMA user_version')->fetchColumn()>=48)return;
 transaction(function()use($db){if((int)$db->query('PRAGMA user_version')->fetchColumn()>=48)return;$db->exec("ALTER TABLE sessions ADD COLUMN device_label TEXT NOT NULL DEFAULT ''; PRAGMA user_version=48;");});
}
function session_device_label(array $in): string {
 if(!array_key_exists('device_label',$in))return '';
 $label=text_field($in,'device_label',1,80);if(preg_match('/[\x00-\x1F\x7F-\x9F\x{202A}-\x{202E}\x{2066}-\x{2069}]/u',$in['device_label']))fail(400,'Invalid device label.');return $label;
}
function session_actions(string $action,array $in): array {
 global $tokenHash;
 if($action==='session_list'){
  $rows=sql('SELECT public_id AS id,device_label,created,seen,expires,hash=? AS current FROM sessions WHERE user_id=? AND expires>? ORDER BY current DESC,created DESC,public_id',[$tokenHash,uid(),time()])->fetchAll();
  foreach($rows as &$row)$row['current']=(bool)$row['current'];return ['sessions'=>$rows];
 }
 if($action==='session_label'){
  if(array_diff(array_keys($in),['action','session_id','device_label','expected_label'])||!array_key_exists('device_label',$in))fail(400,'Invalid session label request.');
  $id=$in['session_id']??null;$expected=$in['expected_label']??null;if(!is_string($id)||!preg_match('/^[a-f0-9]{32}$/',$id)||!is_string($expected)||strlen($expected)>320)fail(400,'Choose a session and its current label.');
  $label=$in['device_label']===''?'':session_device_label($in);
  return transaction(function()use($id,$label,$expected,$tokenHash){
   $now=time();if(!one('SELECT 1 FROM sessions WHERE hash=? AND user_id=? AND expires>?',[$tokenHash,uid(),$now]))fail(401,'Session expired. Please sign in.');
   $target=one('SELECT device_label FROM sessions WHERE public_id=? AND user_id=? AND expires>?',[$id,uid(),$now]);if(!$target)fail(404,'Session is no longer available.');if($target['device_label']!==$expected)fail(409,'The device name changed. Refresh sessions before editing it.');
   sql('UPDATE sessions SET device_label=? WHERE public_id=? AND user_id=?',[$label,$id,uid()]);return ['ok'=>true];
  });
 }
 $all=array_key_exists('all_others',$in)?$in['all_others']:false;$id=$in['session_id']??null;
 if(!is_bool($all)||($all&&array_key_exists('session_id',$in))||(!$all&&(!is_string($id)||!preg_match('/^[a-f0-9]{32}$/',$id))))fail(400,'Choose a session or all other sessions.');
 rate('session-revoke:'.uid(),10,60);
 return transaction(function()use($in,$all,$id,$tokenHash){
  $current=one('SELECT u.password FROM users u JOIN sessions s ON s.user_id=u.id WHERE u.id=? AND s.hash=? AND s.expires>?',[uid(),$tokenHash,time()]);if(!$current)fail(401,'Session expired. Please sign in.');
  $password=$in['password']??null;if(!is_string($password)||strlen($password)>128||!password_verify($password,$current['password']))fail(403,'Current password is incorrect.');
  if($all)$deleted=sql('DELETE FROM sessions WHERE user_id=? AND hash<>?',[uid(),$tokenHash])->rowCount();
  else{$target=one('SELECT hash FROM sessions WHERE public_id=? AND user_id=? AND expires>?',[$id,uid(),time()]);if(!$target)fail(404,'Session is no longer available.');if(hash_equals($target['hash'],$tokenHash))fail(400,'Use Log Out to sign out this session.');$deleted=sql('DELETE FROM sessions WHERE public_id=? AND user_id=?',[$id,uid()])->rowCount();}
  refresh_presence(uid());return ['ok'=>true,'revoked'=>$deleted];
 });
}
function session_for(int $id,string $deviceLabel=''): string {
 $token=bin2hex(random_bytes(32));sql('DELETE FROM sessions WHERE user_id=? AND hash NOT IN (SELECT hash FROM sessions WHERE user_id=? ORDER BY expires DESC LIMIT 4)',[$id,$id]);
 sql('INSERT INTO sessions(hash,user_id,expires,public_id,created) VALUES(?,?,?,?,?)',[hash('sha256',$token),$id,time()+30*86400,random_id(),time()]);
 // Omitted labels keep older API callers compatible during the staged migration.
 if($deviceLabel!=='')sql('UPDATE sessions SET device_label=? WHERE hash=?',[$deviceLabel,hash('sha256',$token)]);return $token;
}
function migrate_status_expiry(): void {
 global $db;if((int)$db->query('PRAGMA user_version')->fetchColumn()>=13)return;
 transaction(function()use($db){if((int)$db->query('PRAGMA user_version')->fetchColumn()>=13)return;
  $db->exec('ALTER TABLE users ADD COLUMN custom_status_expires_at INTEGER NOT NULL DEFAULT 0; ALTER TABLE users ADD COLUMN status_revision INTEGER NOT NULL DEFAULT 0; PRAGMA user_version=13;');
 });
}
function custom_status(array $u): string {return $u['custom_status_expires_at']>0&&$u['custom_status_expires_at']<=time()?'':$u['custom_status'];}
function public_user(array $u): array {return ['id'=>(int)$u['id'],'username'=>$u['username'],'name'=>$u['name'],'avatar'=>$u['avatar'],'banner'=>$u['banner'],'accent_color'=>$u['accent_color']??'','bio'=>$u['bio'],'pronouns'=>$u['pronouns'],'presence'=>presence($u),'custom_status'=>custom_status($u),'last_seen'=>$u['status']==='invisible'?0:(int)$u['last_seen']];}
function configuration(): array {global $privateDir;$p=$privateDir.'/config.php';return is_file($p)?require $p:[];}
function relay_allowed(array $channel): void {
 if(getenv('GATHER_TEST')==='1')return;
 $allowed=configuration()['relay_owners']??[];$owners=$channel['server_id']?array_column(sql('SELECT u.username FROM users u JOIN servers s ON s.owner_id=u.id WHERE s.id=?',[$channel['server_id']])->fetchAll(),'username'):array_column(sql('SELECT u.username FROM users u JOIN channel_members m ON m.user_id=u.id WHERE m.channel_id=?',[$channel['id']])->fetchAll(),'username');
 if(!in_array('*',$allowed,true)&&!array_intersect(array_map('strtolower',$owners),$allowed))fail(503,'Calls for this server need approval from the Gather host. Text chat is available.');
}
function rtc_ready(): bool {$c=configuration();return (!empty($c['cloudflare_key_id'])&&!empty($c['cloudflare_token']))||(!empty($c['turn_urls'])&&!empty($c['turn_secret']));}
function rtc_config(): array {
 $c=configuration();
 if(!empty($c['cloudflare_key_id'])&&!empty($c['cloudflare_token'])){
  rate('rtc-issue:'.uid(),12,3600);
  $id=$c['cloudflare_key_id'];if(!preg_match('/^[a-zA-Z0-9_-]{16,128}$/',$id))fail(503,'Invalid relay configuration.');
  $request=curl_init('https://rtc.live.cloudflare.com/v1/turn/keys/'.$id.'/credentials/generate-ice-servers');
  curl_setopt_array($request,[CURLOPT_POST=>true,CURLOPT_POSTFIELDS=>json_encode(['ttl'=>3600]),CURLOPT_HTTPHEADER=>['Content-Type: application/json','Authorization: Bearer '.$c['cloudflare_token']],CURLOPT_RETURNTRANSFER=>true,CURLOPT_TIMEOUT=>12,CURLOPT_PROTOCOLS=>CURLPROTO_HTTPS]);
  $raw=curl_exec($request);$status=curl_getinfo($request,CURLINFO_HTTP_CODE);curl_close($request);
  if($status!==201||!is_string($raw))fail(503,'The call relay is unavailable.');$response=json_decode($raw,true);if(empty($response['iceServers']))fail(503,'The relay returned no credentials.');
  return ['ready'=>true,'iceTransportPolicy'=>'relay','iceServers'=>$response['iceServers']];
 }
 $urls=$c['turn_urls']??[];$secret=$c['turn_secret']??'';
 if(!$urls||!$secret)return ['ready'=>false,'iceServers'=>[]];
 $username=(time()+3600).':'.uid();return ['ready'=>true,'iceTransportPolicy'=>'relay','iceServers'=>[['urls'=>$urls,'username'=>$username,'credential'=>base64_encode(hash_hmac('sha1',$username,$secret,true))]]];
}
function call_record(string $id): array {
 $call=one('SELECT * FROM calls WHERE id=? AND user_id=? AND seen>?',[$id,uid(),time()-45]);if(!$call)fail(410,'Call expired. Join again.');channel($call['channel_id'],true,'connect');return $call;
}
function attachment_type_extensions($type): array {
 $types=['all'=>[],'image'=>['png','jpg','jpeg','webp','gif'],'audio'=>['mp3','wav','ogg'],'video'=>['mp4','webm'],'document'=>['pdf','txt','csv','md','json','zip','docx','xlsx','pptx']];
 if(!is_string($type)||!array_key_exists($type,$types))fail(400,'Invalid attachment type.');return $types[$type];
}
function message_search_dates(array $in): array {
 $filters=[];
 foreach(['after_date','before_date'] as $key)if(array_key_exists($key,$in)){
  $value=$in[$key];if(!is_string($value)||!preg_match('/^[0-9]{4}-[0-9]{2}-[0-9]{2}$/D',$value)||(int)substr($value,0,4)<1970)fail(400,'Use dates from 1970 in YYYY-MM-DD format.');
  $date=DateTimeImmutable::createFromFormat('!Y-m-d',$value,new DateTimeZone('UTC'));if(!$date||$date->format('Y-m-d')!==$value)fail(400,'Choose a valid calendar date.');$filters[$key]=$date->getTimestamp();
 }
 if(isset($filters['after_date'],$filters['before_date'])&&$filters['after_date']>=$filters['before_date'])fail(400,'The before date must follow the on-or-after date.');return $filters;
}
function message_search_filters(string $cid,array $in): array {
 $filters=message_search_dates($in);
 if(array_key_exists('file_type',$in)){attachment_type_extensions($in['file_type']);$filters['file_type']=$in['file_type'];}
 if(array_key_exists('author_id',$in)){$id=positive_id($in['author_id']);if($id>9007199254740991)fail(400,'Invalid author.');if(blocked($id)||!can_view_as($cid,$id))fail(403,'This author is not available in this conversation.');$filters['author_id']=$id;}
 return $filters;
}

function messages_for(string $cid,int $before=0,string $search='',bool $pins=false,int $after=0,array $filters=[]): array {
 $where='m.channel_id=? AND m.deleted=0';$args=[$cid];
 if(isset($filters['author_id'])){$where.=' AND m.user_id=?';$args[]=$filters['author_id'];}
 if(isset($filters['after_date'])){$where.=' AND m.created>=?';$args[]=$filters['after_date'];}
 if(isset($filters['before_date'])){$where.=' AND m.created<?';$args[]=$filters['before_date'];}
 if(isset($filters['file_type'])){
  $extensions=attachment_type_extensions($filters['file_type']);$fileWhere='';if($extensions){$fileWhere=' AND ('.implode(' OR ',array_fill(0,count($extensions),'lower(a.name) LIKE ?')).')';foreach($extensions as $extension)$args[]='%.'.$extension;}
  $where.=' AND EXISTS(SELECT 1 FROM attachments a WHERE a.message_id=m.id AND a.channel_id=m.channel_id'.$fileWhere.')';
 }
 if($filters){$where.=' AND NOT EXISTS(SELECT 1 FROM blocks b WHERE (b.user_id=? AND b.target=m.user_id) OR (b.target=? AND b.user_id=m.user_id))';array_push($args,uid(),uid());}

 if($before){$where.=' AND m.id<?';$args[]=$before;}if($after){$where.=' AND m.id>?';$args[]=$after;}if($search!==''){$where.=' AND instr(lower(m.body),lower(?))>0';$args[]=$search;}if($pins)$where.=' AND m.pinned=1';
 $args[]=uid();
 // Put the user parameter before the WHERE parameters to match the reaction subquery.
 $userId=array_pop($args);array_unshift($args,$userId);
 $order=$after?'ASC':'DESC';$messages=sql("SELECT m.reply_to,m.id,m.user_id,m.body,m.created,m.edited,m.pinned,m.system_type,CASE WHEN EXISTS(SELECT 1 FROM messages target WHERE target.id=m.system_target AND target.channel_id=m.channel_id AND target.deleted=0 AND NOT EXISTS(SELECT 1 FROM blocks bl WHERE bl.user_id=".uid()." AND bl.target=target.user_id)) THEN m.system_target END AS system_target,u.name,u.username,COALESCE(NULLIF(mb.server_avatar,''),u.avatar) AS avatar,CASE WHEN EXISTS(SELECT 1 FROM blocks bl WHERE bl.user_id=".uid()." AND bl.target=m.user_id) THEN 1 ELSE 0 END AS blocked,COALESCE(mb.nickname,'') AS nickname,mb.role,(SELECT r.color FROM roles r JOIN member_roles mr ON mr.role_id=r.id WHERE mr.user_id=u.id AND mr.server_id=ch.server_id AND length(r.color)>0 ORDER BY r.position DESC,r.id LIMIT 1) AS role_color,(SELECT count(*) FROM reactions r WHERE r.message_id=m.id AND r.emoji='❤️') AS hearts,(SELECT count(*) FROM reactions r WHERE r.message_id=m.id AND r.emoji='❤️' AND r.user_id=?) AS reacted FROM messages m JOIN users u ON u.id=m.user_id JOIN channels ch ON ch.id=m.channel_id LEFT JOIN members mb ON mb.user_id=m.user_id AND mb.server_id=ch.server_id WHERE $where ORDER BY m.id $order LIMIT 60",$args)->fetchAll();if(!$after)$messages=array_reverse($messages);
 $roleNames=array_column(sql('SELECT r.id,r.name FROM roles r JOIN channels c ON c.server_id=r.server_id WHERE c.id=?',[$cid])->fetchAll(),'name','id');
 foreach($messages as &$m){$m['preview']=role_preview($m['body'],$roleNames);$m['role_mentions']=[];foreach(mention_roles($m['body']) as $role)$m['role_mentions'][]=['id'=>$role,'name'=>$roleNames[$role]??'Deleted role'];$m['reactions']=sql('SELECT emoji,count(*) AS count,max(CASE WHEN user_id=? THEN 1 ELSE 0 END) AS selected FROM reactions WHERE message_id=? GROUP BY emoji ORDER BY min(rowid)',[uid(),$m['id']])->fetchAll();$m['attachments']=blocked((int)$m['user_id'])?[]:sql('SELECT id,name,size,description,spoiler FROM attachments WHERE message_id=? AND channel_id=?',[$m['id'],$cid])->fetchAll();$m['reply']=$m['reply_to']?one('SELECT m.id,u.name,substr(m.body,1,180) AS body,m.body AS preview FROM messages m JOIN users u ON u.id=m.user_id WHERE m.id=? AND m.channel_id=? AND m.deleted=0',[$m['reply_to'],$cid]):null;if($m['reply'])$m['reply']['preview']=role_preview($m['reply']['preview'],$roleNames);$m['thread']=one('SELECT id,name,archived FROM channels WHERE root_message=?',[$m['id']]);}unset($m);return $messages;
}

function migrate_account_export(): void {
 global $db;if((int)$db->query('PRAGMA user_version')->fetchColumn()>=49)return;
 transaction(function()use($db){if((int)$db->query('PRAGMA user_version')->fetchColumn()>=49)return;$db->exec('CREATE INDEX messages_export ON messages(user_id,id); PRAGMA user_version=49;');});
}
function migrate_social_export(): void {
 global $db;if((int)$db->query('PRAGMA user_version')->fetchColumn()>=50)return;
 transaction(function()use($db){if((int)$db->query('PRAGMA user_version')->fetchColumn()>=50)return;$db->exec('CREATE INDEX friendships_export_recipient ON friendships(b,a); CREATE INDEX reactions_export_author ON reactions(user_id); PRAGMA user_version=50;');});
}
function account_export(array $in): array {
 global $user,$db,$tokenHash;
 if(array_diff(array_keys($in),['action','export_id','cursor','format_version']))fail(400,'Invalid export request.');
 $export=$in['export_id']??null;if(!is_string($export)||!preg_match('/^[a-f0-9]{32}$/D',$export))fail(400,'Invalid export ID.');
 $version=array_key_exists('format_version',$in)?$in['format_version']:1;if(!is_int($version)||!in_array($version,[1,2,3,4],true))fail(400,'Unsupported export format.');
 $cursor=$in['cursor']??null;$phases=$version===4?['profile','servers','channels','layout','notes','friends','blocks','reactions','messages']:($version>=2?['profile','servers','channels','layout','notes','messages']:['profile','servers','channels','messages']);
 if($cursor!==null){if(!is_array($cursor)||array_diff(array_keys($cursor),['section','after','through','part'])||count($cursor)!==4||!in_array($cursor['section']??null,$phases,true)||!is_int($cursor['through']??null)||$cursor['through']<0||$cursor['through']>9007199254740991||!is_int($cursor['part']??null)||$cursor['part']<1||$cursor['part']>999999)fail(400,'Invalid export cursor.');
  if(in_array($cursor['section'],['messages','notes','friends','blocks','reactions'],true)){if(!is_int($cursor['after'])||$cursor['after']<0||$cursor['after']>($cursor['section']==='messages'?$cursor['through']:9007199254740991))fail(400,'Invalid export cursor.');}elseif(!is_string($cursor['after'])||!preg_match('/^(?:[a-f0-9]{32})?$/D',$cursor['after']))fail(400,'Invalid export cursor.');
 }
 rate('account-export:'.uid(),40,60);
 // Each part is a bounded read snapshot; access is checked again for every later part.
 $db->beginTransaction();try{
  if(!one('SELECT 1 FROM sessions WHERE user_id=? AND hash=? AND expires>?',[uid(),$tokenHash,time()]))fail(401,'Session expired. Please sign in.');
  $cursor??=['section'=>'profile','after'=>'','through'=>(int)sql('SELECT COALESCE(max(id),0) FROM messages WHERE user_id=?',[uid()])->fetchColumn(),'part'=>1];
  $section=$cursor['section'];$after=$cursor['after'];$rows=[];$records=[];$more=false;$access=[];
  $visible=function($id)use(&$access){if(!array_key_exists($id,$access)){try{$access[$id]=channel($id);}catch(ApiError $e){if(!in_array($e->status,[403,404],true))throw $e;$access[$id]=null;}}return $access[$id];};
  if($section==='profile')$records[]=['profile'=>array_intersect_key($user,array_flip(['id','username','name','created','avatar','banner','bio','pronouns','accent_color'])),'preferences'=>array_intersect_key($user,array_flip(['status','custom_status','custom_status_expires_at']))];
  elseif($section==='servers'){
   $limit=$version>=3?1:50;
   $rows=sql('SELECT s.id,s.name,m.role,m.nickname,m.server_bio,m.server_pronouns,m.server_avatar,m.server_banner,m.server_accent_color FROM members m JOIN servers s ON s.id=m.server_id WHERE m.user_id=? AND s.id>? ORDER BY s.id LIMIT '.($limit+1),[uid(),$after])->fetchAll();$more=count($rows)>$limit;$rows=array_slice($rows,0,$limit);
   foreach($rows as $row){$row['notifications']=one('SELECT mode,suppress_roles,suppress_everyone FROM server_notification_preferences WHERE user_id=? AND server_id=?',[uid(),$row['id']]);$row['mute']=one('SELECT muted_until FROM server_notification_mutes WHERE user_id=? AND server_id=?',[uid(),$row['id']]);
    if($version>=3){
     $modes=array_column(sql('SELECT category,mode FROM category_notification_preferences WHERE user_id=? AND server_id=?',[uid(),$row['id']])->fetchAll(),'mode','category');
     $mutes=array_column(sql('SELECT category,muted_until,revision,silent_through FROM category_notification_mutes WHERE user_id=? AND server_id=?',[uid(),$row['id']])->fetchAll(),null,'category');
     $collapse=array_column(sql('SELECT category,collapsed,revision FROM category_collapse_preferences WHERE user_id=? AND server_id=?',[uid(),$row['id']])->fetchAll(),null,'category');
     $row['categories']=[];foreach(server_categories($row['id']) as $category){$name=$category['name'];$mute=$mutes[$name]??null;$collapsed=$collapse[$name]??null;if($mute)unset($mute['category']);if($collapsed)unset($collapsed['category']);$row['categories'][]=['id'=>$category['id'],'key'=>$category['rename_key'],'name'=>$name,'notification_mode'=>$modes[$name]??null,'mute'=>$mute,'collapse'=>$collapsed];}
    }$records[]=$row;}
  }elseif($section==='channels'){
   $rows=sql('SELECT c.id FROM channels c WHERE c.id>? AND (EXISTS(SELECT 1 FROM members m WHERE m.server_id=c.server_id AND m.user_id=?) OR EXISTS(SELECT 1 FROM channel_members cm WHERE cm.channel_id=c.id AND cm.user_id=?)) ORDER BY c.id LIMIT 51',[$after,uid(),uid()])->fetchAll();$more=count($rows)>50;$rows=array_slice($rows,0,50);
   foreach($rows as $row){$c=$visible($row['id']);if(!$c)continue;$item=array_intersect_key($c,array_flip(['id','server_id','name','kind','parent_id','category']));$item['notifications']=one('SELECT mode FROM notification_preferences WHERE user_id=? AND channel_id=?',[uid(),$c['id']]);$item['mute']=one('SELECT muted_until FROM notification_mutes WHERE user_id=? AND channel_id=?',[uid(),$c['id']]);if($c['server_id']){$item['category_notifications']=one('SELECT mode FROM category_notification_preferences WHERE user_id=? AND server_id=? AND category=?',[uid(),$c['server_id'],$c['category']]);$item['category_mute']=one('SELECT muted_until FROM category_notification_mutes WHERE user_id=? AND server_id=? AND category=?',[uid(),$c['server_id'],$c['category']]);$item['category_collapse']=one('SELECT collapsed FROM category_collapse_preferences WHERE user_id=? AND server_id=? AND category=?',[uid(),$c['server_id'],$c['category']]);}$records[]=$item;}
  }elseif($section==='layout'){
   $row=one('SELECT layout,revision FROM server_layout_preferences WHERE user_id=?',[uid()]);$layout=$row?json_decode($row['layout'],true):null;$supported=!$row||(is_array($layout)&&($layout['version']??null)===1);
   $records[]=['layout'=>$supported&&$layout!==null?validated_server_layout($layout,false):null,'revision'=>(int)($row['revision']??0),'available'=>$supported,'notice'=>$supported?'Current memberships only; no archive import is available.':'Saved layout uses an unsupported format and is omitted.'];
  }elseif($section==='notes'){
   $rows=sql('SELECT target_id AS id,note,revision FROM profile_notes WHERE user_id=? AND target_id>? ORDER BY target_id LIMIT 51',[uid(),$after])->fetchAll();$more=count($rows)>50;$rows=array_slice($rows,0,50);
   foreach($rows as $row){try{require_note_target((int)$row['id']);}catch(ApiError $e){if(!in_array($e->status,[403,404],true))throw $e;continue;}$records[]=['target_id'=>(int)$row['id'],'note'=>$row['note'],'revision'=>(int)$row['revision']];}
  }elseif($section==='friends'){
   $rows=sql('SELECT b AS id,sender,accepted FROM friendships WHERE a=? AND b>? UNION ALL SELECT a AS id,sender,accepted FROM friendships WHERE b=? AND a>? ORDER BY id LIMIT 51',[uid(),$after,uid(),$after])->fetchAll();$more=count($rows)>50;$rows=array_slice($rows,0,50);
   foreach($rows as $row)if(one('SELECT 1 FROM users WHERE id=?',[$row['id']])&&!blocked((int)$row['id']))$records[]=['target_id'=>(int)$row['id'],'state'=>$row['accepted']?'friend':((int)$row['sender']===uid()?'outgoing':'incoming')];
  }elseif($section==='blocks'){
   $rows=sql('SELECT target AS id FROM blocks WHERE user_id=? AND target>? ORDER BY target LIMIT 51',[uid(),$after])->fetchAll();$more=count($rows)>50;$rows=array_slice($rows,0,50);
   foreach($rows as $row)if(one('SELECT 1 FROM users WHERE id=?',[$row['id']]))$records[]=['target_id'=>(int)$row['id'],'state'=>'blocked'];
  }elseif($section==='reactions'){
   // Reaction row IDs paginate live snapshots; additions/removals between parts can change later output.
   $rows=sql('SELECT rowid AS id,message_id,emoji FROM reactions WHERE user_id=? AND rowid>? ORDER BY rowid LIMIT 51',[uid(),$after])->fetchAll();$more=count($rows)>50;$rows=array_slice($rows,0,50);
   foreach($rows as $row){$m=one('SELECT channel_id,user_id FROM messages WHERE id=? AND deleted=0',[$row['message_id']]);if(!$m||blocked((int)$m['user_id']))continue;$c=$visible($m['channel_id']);if(!$c)continue;if(!$c['server_id']&&$c['pair_key']){$other=(int)sql('SELECT user_id FROM channel_members WHERE channel_id=? AND user_id<>? LIMIT 1',[$c['id'],uid()])->fetchColumn();if(blocked($other))continue;}$records[]=['message_id'=>(int)$row['message_id'],'channel_id'=>$m['channel_id'],'emoji'=>$row['emoji']];}
  }else{
   $rows=sql("SELECT id,channel_id,body,created,edited,pinned,reply_to,deleted,system_type FROM messages WHERE user_id=? AND id>? AND id<=? ORDER BY id LIMIT 51",[uid(),$after,$cursor['through']])->fetchAll();$more=count($rows)>50;$rows=array_slice($rows,0,50);
   foreach($rows as $row){if($row['deleted']||$row['system_type']!==''||!$visible($row['channel_id']))continue;unset($row['deleted'],$row['system_type']);$row['attachments']=sql('SELECT id,name,size,description,spoiler FROM attachments WHERE message_id=? AND channel_id=? AND user_id=? ORDER BY rowid LIMIT 3',[$row['id'],$row['channel_id'],uid()])->fetchAll();$records[]=$row;}
  }
  if($cursor['part']>=999999)fail(413,'Export part limit reached.');
  if($more)$next=[...$cursor,'after'=>end($rows)['id'],'part'=>$cursor['part']+1];else{$position=array_search($section,$phases,true)+1;$next=$position<count($phases)?['section'=>$phases[$position],'after'=>in_array($phases[$position],['messages','notes','friends','blocks','reactions'],true)?0:'','through'=>$cursor['through'],'part'=>$cursor['part']+1]:null;}
  $document=['format'=>'gather-account-export','version'=>$version,'export_id'=>$export,'part'=>$cursor['part'],'section'=>$section,'account_id'=>uid(),'exported_at'=>gmdate('c'),'scope'=>($version===4?'Your friendship/request states, your own blocks, and your reactions on currently accessible unblocked messages. Relationship and reaction records are live snapshots and may change between parts. ':'').($version>=3?'Visible category preferences, including empty categories. ':'').($version>=2?'Saved server layout and private notes for currently accessible people. Reference only; Gather cannot import this archive. ':'').'Profile/status, current memberships/profile overrides, accessible channel/category notification preferences, and your non-deleted authored messages up to the initial message ID. Each part is a separate read snapshot; earlier saved files cannot be revoked.','omissions'=>['Credentials and sessions','Other authors and system notices','Inaccessible/deleted content','Image/file contents',$version>=2?($version===4?'Device-local settings':($version>=3?'Device-local settings, relationships and reactions':'Device-local settings, empty-category preferences, relationships and reactions')):'Device-local settings, server layout, empty-category preferences, relationships, reactions and private notes'],'message_ceiling'=>$cursor['through'],'records'=>$records,'next'=>$next];
  $json=json_encode($document,JSON_THROW_ON_ERROR|JSON_PRETTY_PRINT|JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES);if(strlen($json)>4194304)fail(413,'Export part is too large.');$db->commit();
  return ['export_id'=>$export,'section'=>$section,'records'=>count($records),'next'=>$next,'file'=>['name'=>'gather-export-'.$export.'-'.$cursor['part'].'.json','data'=>base64_encode($json),'size'=>strlen($json),'sha256'=>hash('sha256',$json)]];
 }catch(Throwable $e){if($db->inTransaction())$db->rollBack();throw $e;}
}
