<?php
declare(strict_types=1);
final class ApiError extends RuntimeException { public function __construct(public int $status,string $message){parent::__construct($message);} }
function fail(int $status,string $message): never {throw new ApiError($status,$message);}
function sql(string $query,array $args=[]): PDOStatement {global $db;$s=$db->prepare($query);$s->execute($args);return $s;}
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
 $r=sql('INSERT INTO limits(key,window,hits) VALUES(?,?,1) ON CONFLICT(key) DO UPDATE SET hits=CASE WHEN window=excluded.window THEN hits+1 ELSE 1 END,window=excluded.window RETURNING hits',[$key,$window])->fetch();
 if($r['hits']>$max)fail(429,'Too many attempts. Please wait and try again.');
}
function transaction(callable $fn): mixed {global $db;$db->exec('BEGIN IMMEDIATE');try{$value=$fn();$db->exec('COMMIT');return $value;}catch(Throwable $e){$db->exec('ROLLBACK');throw $e;}}
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
function public_user(array $u): array {return ['id'=>(int)$u['id'],'username'=>$u['username'],'name'=>$u['name'],'avatar'=>$u['avatar'],'banner'=>$u['banner'],'bio'=>$u['bio'],'pronouns'=>$u['pronouns'],'presence'=>presence($u),'custom_status'=>custom_status($u),'last_seen'=>$u['status']==='invisible'?0:(int)$u['last_seen']];}
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
function messages_for(string $cid,int $before=0,string $search='',bool $pins=false,int $after=0): array {
 $where='m.channel_id=? AND m.deleted=0';$args=[$cid];
 if($before){$where.=' AND m.id<?';$args[]=$before;}if($after){$where.=' AND m.id>?';$args[]=$after;}if($search!==''){$where.=' AND instr(lower(m.body),lower(?))>0';$args[]=$search;}if($pins)$where.=' AND m.pinned=1';
 $args[]=uid();
 // Put the user parameter before the WHERE parameters to match the reaction subquery.
 $userId=array_pop($args);array_unshift($args,$userId);
 $order=$after?'ASC':'DESC';$messages=sql("SELECT m.reply_to,m.id,m.user_id,m.body,m.created,m.edited,m.pinned,m.system_type,CASE WHEN EXISTS(SELECT 1 FROM messages target WHERE target.id=m.system_target AND target.channel_id=m.channel_id AND target.deleted=0 AND NOT EXISTS(SELECT 1 FROM blocks bl WHERE bl.user_id=".uid()." AND bl.target=target.user_id)) THEN m.system_target END AS system_target,u.name,u.username,COALESCE(NULLIF(mb.server_avatar,''),u.avatar) AS avatar,CASE WHEN EXISTS(SELECT 1 FROM blocks bl WHERE bl.user_id=".uid()." AND bl.target=m.user_id) THEN 1 ELSE 0 END AS blocked,COALESCE(mb.nickname,'') AS nickname,mb.role,(SELECT r.color FROM roles r JOIN member_roles mr ON mr.role_id=r.id WHERE mr.user_id=u.id AND mr.server_id=ch.server_id AND length(r.color)>0 ORDER BY r.position DESC,r.id LIMIT 1) AS role_color,(SELECT count(*) FROM reactions r WHERE r.message_id=m.id AND r.emoji='❤️') AS hearts,(SELECT count(*) FROM reactions r WHERE r.message_id=m.id AND r.emoji='❤️' AND r.user_id=?) AS reacted FROM messages m JOIN users u ON u.id=m.user_id JOIN channels ch ON ch.id=m.channel_id LEFT JOIN members mb ON mb.user_id=m.user_id AND mb.server_id=ch.server_id WHERE $where ORDER BY m.id $order LIMIT 60",$args)->fetchAll();if(!$after)$messages=array_reverse($messages);
 $roleNames=array_column(sql('SELECT r.id,r.name FROM roles r JOIN channels c ON c.server_id=r.server_id WHERE c.id=?',[$cid])->fetchAll(),'name','id');
 foreach($messages as &$m){$m['preview']=role_preview($m['body'],$roleNames);$m['role_mentions']=[];foreach(mention_roles($m['body']) as $role)$m['role_mentions'][]=['id'=>$role,'name'=>$roleNames[$role]??'Deleted role'];$m['reactions']=sql('SELECT emoji,count(*) AS count,max(CASE WHEN user_id=? THEN 1 ELSE 0 END) AS selected FROM reactions WHERE message_id=? GROUP BY emoji ORDER BY min(rowid)',[uid(),$m['id']])->fetchAll();$m['attachments']=sql('SELECT id,name,size FROM attachments WHERE message_id=?',[$m['id']])->fetchAll();$m['reply']=$m['reply_to']?one('SELECT m.id,u.name,substr(m.body,1,180) AS body,m.body AS preview FROM messages m JOIN users u ON u.id=m.user_id WHERE m.id=? AND m.channel_id=? AND m.deleted=0',[$m['reply_to'],$cid]):null;if($m['reply'])$m['reply']['preview']=role_preview($m['reply']['preview'],$roleNames);$m['thread']=one('SELECT id,name,archived FROM channels WHERE root_message=?',[$m['id']]);}unset($m);return $messages;
}
