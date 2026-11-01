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
 if($c['parent_id']){$parent=channel($c['parent_id'],$write,$permission);$c['slowmode']=$parent['slowmode'];$c['category']=$parent['category'];if($write&&$c['archived'])fail(403,'This thread is archived.');return $c;}
 if($c['server_id']){$m=member($c['server_id']);if($write&&$m['muted_until']>time())fail(403,'You are temporarily muted in this server.');$p=channel_capabilities($c);if(!$p['view']||($write&&!$p[$permission]))fail(403,'You do not have permission in this channel.');
 }else{if(!one('SELECT 1 FROM channel_members WHERE channel_id=? AND user_id=?',[$id,uid()]))fail(403,'This conversation is private.');if($write&&$c['pair_key']){$other=(int)sql('SELECT user_id FROM channel_members WHERE channel_id=? AND user_id<>?',[$id,uid()])->fetchColumn();if(blocked($other))fail(403,'This conversation is unavailable.');}}return $c;
}
function session_for(int $id): string {
 $token=bin2hex(random_bytes(32));sql('DELETE FROM sessions WHERE user_id=? AND hash NOT IN (SELECT hash FROM sessions WHERE user_id=? ORDER BY expires DESC LIMIT 4)',[$id,$id]);
 sql('INSERT INTO sessions VALUES(?,?,?)',[hash('sha256',$token),$id,time()+30*86400]);return $token;
}
function public_user(array $u): array {return ['id'=>(int)$u['id'],'username'=>$u['username'],'name'=>$u['name'],'avatar'=>$u['avatar'],'banner'=>$u['banner'],'bio'=>$u['bio'],'pronouns'=>$u['pronouns'],'presence'=>presence($u),'custom_status'=>$u['custom_status'],'last_seen'=>$u['status']==='invisible'?0:(int)$u['last_seen']];}
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
function messages_for(string $cid,int $before=0,string $search='',bool $pins=false): array {
 $where='m.channel_id=? AND m.deleted=0';$args=[$cid];
 if($before){$where.=' AND m.id<?';$args[]=$before;}if($search!==''){$where.=' AND instr(lower(m.body),lower(?))>0';$args[]=$search;}if($pins)$where.=' AND m.pinned=1';
 $args[]=uid();
 // Put the user parameter before the WHERE parameters to match the reaction subquery.
 $userId=array_pop($args);array_unshift($args,$userId);
 $messages=array_reverse(sql("SELECT m.reply_to,m.id,m.user_id,m.body,m.created,m.edited,m.pinned,u.name,u.username,u.avatar,CASE WHEN EXISTS(SELECT 1 FROM blocks bl WHERE bl.user_id=".uid()." AND bl.target=m.user_id) THEN 1 ELSE 0 END AS blocked,COALESCE(mb.nickname,'') AS nickname,mb.role,(SELECT r.color FROM roles r JOIN member_roles mr ON mr.role_id=r.id WHERE mr.user_id=u.id AND mr.server_id=ch.server_id AND length(r.color)>0 ORDER BY r.position DESC,r.id LIMIT 1) AS role_color,(SELECT count(*) FROM reactions r WHERE r.message_id=m.id AND r.emoji='❤️') AS hearts,(SELECT count(*) FROM reactions r WHERE r.message_id=m.id AND r.emoji='❤️' AND r.user_id=?) AS reacted FROM messages m JOIN users u ON u.id=m.user_id JOIN channels ch ON ch.id=m.channel_id LEFT JOIN members mb ON mb.user_id=m.user_id AND mb.server_id=ch.server_id WHERE $where ORDER BY m.id DESC LIMIT 60",$args)->fetchAll());
 foreach($messages as &$m){$m['reactions']=sql('SELECT emoji,count(*) AS count,max(CASE WHEN user_id=? THEN 1 ELSE 0 END) AS selected FROM reactions WHERE message_id=? GROUP BY emoji ORDER BY min(rowid)',[uid(),$m['id']])->fetchAll();$m['attachments']=sql('SELECT id,name,size FROM attachments WHERE message_id=?',[$m['id']])->fetchAll();$m['reply']=$m['reply_to']?one('SELECT m.id,u.name,substr(m.body,1,180) AS body FROM messages m JOIN users u ON u.id=m.user_id WHERE m.id=? AND m.channel_id=? AND m.deleted=0',[$m['reply_to'],$cid]):null;$m['thread']=one('SELECT id,name,archived FROM channels WHERE root_message=?',[$m['id']]);}unset($m);return $messages;
}
