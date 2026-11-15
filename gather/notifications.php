<?php
declare(strict_types=1);
function migrate_message_search(): void {
 global $db;if((int)$db->query('PRAGMA user_version')->fetchColumn()>=55)return;
 transaction(function()use($db){
  if((int)$db->query('PRAGMA user_version')->fetchColumn()>=55)return;
  try{$db->exec("CREATE VIRTUAL TABLE message_search USING fts5(body,content='message_search_content',content_rowid='id',tokenize='trigram case_sensitive 1',detail=none,columnsize=0)");}
  catch(PDOException $e){
   // Older SQLite hosts keep the existing literal scan, without write triggers.
   if(!preg_match('/no such module: fts5|no such tokenizer: trigram/i',$e->getMessage()))throw $e;
   $db->exec('PRAGMA user_version=55');return;
  }
  $db->exec("CREATE VIEW message_search_content AS SELECT id,lower(body) AS body FROM messages WHERE deleted=0;
   CREATE TRIGGER message_search_insert AFTER INSERT ON messages WHEN new.deleted=0 BEGIN
    INSERT INTO message_search(rowid,body) VALUES(new.id,lower(new.body)); END;
   CREATE TRIGGER message_search_delete AFTER DELETE ON messages WHEN old.deleted=0 BEGIN
    INSERT INTO message_search(message_search,rowid,body) VALUES('delete',old.id,lower(old.body)); END;
   CREATE TRIGGER message_search_update AFTER UPDATE OF id,body,deleted ON messages BEGIN
    INSERT INTO message_search(message_search,rowid,body) SELECT 'delete',old.id,lower(old.body) WHERE old.deleted=0;
    INSERT INTO message_search(rowid,body) SELECT new.id,lower(new.body) WHERE new.deleted=0; END;
   INSERT INTO message_search(message_search) VALUES('rebuild'); PRAGMA user_version=55;");
 });
}
function message_search_phrase(string $query): ?string {
 // One- and two-character queries have no trigram; retain the literal scan.
 if(preg_match_all('/./us',$query)<3||!one("SELECT 1 FROM sqlite_master WHERE type='table' AND name='message_search'"))return null;
 // Positions are unnecessary: the original literal predicate verifies candidate rows.
 $chars=preg_split('//u',strtr($query,'ABCDEFGHIJKLMNOPQRSTUVWXYZ','abcdefghijklmnopqrstuvwxyz'),-1,PREG_SPLIT_NO_EMPTY);$tokens=[];
 for($i=0;$i<count($chars)-2;$i++)$tokens[]='"'.str_replace('"','""',$chars[$i].$chars[$i+1].$chars[$i+2]).'"';
 $phrase=implode(' AND ',array_unique($tokens));
 // Common text would materialize a large global candidate list; use the scoped scan instead.
 if((int)sql('SELECT count(*) FROM (SELECT rowid FROM message_search WHERE message_search MATCH ? LIMIT 1001)',[$phrase])->fetchColumn()>1000)return null;
 return $phrase;
}
function require_pin_channel(array $c): void {
   if($c['server_id']){if(member($c['server_id'])['muted_until']>time())fail(403,'You cannot change pins while timed out.');if(!channel_capabilities($c)['pin_messages'])fail(403,'You do not have permission to pin messages in this channel.');
    if($c['parent_id']){if($c['archived']||($c['auto_archive_minutes']>0&&$c['thread_activity']+$c['auto_archive_minutes']*60<=time()))fail(403,'Reopen this thread before changing pins.');if(thread_message_locked($c))fail(403,'Only thread managers can change pins in a locked thread.');}
   }else channel($c['id'],true);
}
function migrate_everyone_alerts(): void {
 global $db;
 if((int)$db->query('PRAGMA user_version')->fetchColumn()>=24)return;
 transaction(function()use($db){
  if((int)$db->query('PRAGMA user_version')->fetchColumn()>=24)return;
  $db->exec('ALTER TABLE server_notification_preferences ADD COLUMN suppress_everyone INTEGER NOT NULL DEFAULT 0 CHECK(suppress_everyone IN (0,1)); PRAGMA user_version=24;');
 });
}
function migrate_category_notification_mutes(): void {
 global $db;if((int)$db->query('PRAGMA user_version')->fetchColumn()>=23)return;
 transaction(function()use($db){if((int)$db->query('PRAGMA user_version')->fetchColumn()>=23)return;
  $db->exec("CREATE TABLE category_notification_mutes(user_id INTEGER NOT NULL REFERENCES users(id) ON DELETE CASCADE,server_id TEXT NOT NULL REFERENCES servers(id) ON DELETE CASCADE,category TEXT NOT NULL,muted_until INTEGER NOT NULL,revision INTEGER NOT NULL,silent_through INTEGER NOT NULL DEFAULT 0,PRIMARY KEY(user_id,server_id,category));
   CREATE TRIGGER category_mutes_delete AFTER DELETE ON channels WHEN OLD.parent_id IS NULL BEGIN
    DELETE FROM category_notification_mutes WHERE server_id=OLD.server_id AND category=OLD.category AND NOT EXISTS(SELECT 1 FROM channels WHERE server_id=OLD.server_id AND category=OLD.category AND parent_id IS NULL);
   END;
   CREATE TRIGGER category_mutes_move AFTER UPDATE OF category,server_id,parent_id ON channels WHEN OLD.parent_id IS NULL BEGIN
    DELETE FROM category_notification_mutes WHERE server_id=OLD.server_id AND category=OLD.category AND NOT EXISTS(SELECT 1 FROM channels WHERE server_id=OLD.server_id AND category=OLD.category AND parent_id IS NULL);
   END; PRAGMA user_version=23;");
 });
}
function migrate_role_alerts(): void {
 global $db;if((int)$db->query('PRAGMA user_version')->fetchColumn()>=22)return;
 transaction(function()use($db){if((int)$db->query('PRAGMA user_version')->fetchColumn()>=22)return;$db->exec('ALTER TABLE server_notification_preferences ADD COLUMN suppress_roles INTEGER NOT NULL DEFAULT 0 CHECK(suppress_roles IN (0,1)); PRAGMA user_version=22;');});
}
function migrate_category_notifications(): void {
 global $db;if((int)$db->query('PRAGMA user_version')->fetchColumn()>=21)return;
 transaction(function()use($db){if((int)$db->query('PRAGMA user_version')->fetchColumn()>=21)return;
  $db->exec("CREATE TABLE next_notification_preferences(user_id INTEGER NOT NULL REFERENCES users(id) ON DELETE CASCADE,channel_id TEXT NOT NULL REFERENCES channels(id) ON DELETE CASCADE,mode TEXT NOT NULL CHECK(mode IN ('all','mentions','muted','inherit','category')),PRIMARY KEY(user_id,channel_id));
   INSERT INTO next_notification_preferences SELECT * FROM notification_preferences;
   DROP TABLE notification_preferences; ALTER TABLE next_notification_preferences RENAME TO notification_preferences;
   CREATE TABLE category_notification_preferences(user_id INTEGER NOT NULL REFERENCES users(id) ON DELETE CASCADE,server_id TEXT NOT NULL REFERENCES servers(id) ON DELETE CASCADE,category TEXT NOT NULL,mode TEXT NOT NULL CHECK(mode IN ('all','mentions','muted')),PRIMARY KEY(user_id,server_id,category));
   CREATE TRIGGER category_notifications_delete AFTER DELETE ON channels WHEN OLD.parent_id IS NULL BEGIN
    DELETE FROM category_notification_preferences WHERE server_id=OLD.server_id AND category=OLD.category AND NOT EXISTS(SELECT 1 FROM channels WHERE server_id=OLD.server_id AND category=OLD.category AND parent_id IS NULL);
   END;
   CREATE TRIGGER category_notifications_move AFTER UPDATE OF category,server_id,parent_id ON channels WHEN OLD.parent_id IS NULL BEGIN
    DELETE FROM category_notification_preferences WHERE server_id=OLD.server_id AND category=OLD.category AND NOT EXISTS(SELECT 1 FROM channels WHERE server_id=OLD.server_id AND category=OLD.category AND parent_id IS NULL);
   END;
   PRAGMA user_version=21;");
 });
}
function migrate_server_notification_mutes(): void {
 global $db;if((int)$db->query('PRAGMA user_version')->fetchColumn()>=20)return;
 transaction(function()use($db){if((int)$db->query('PRAGMA user_version')->fetchColumn()>=20)return;
  $db->exec("CREATE TABLE server_notification_mutes(user_id INTEGER NOT NULL REFERENCES users(id) ON DELETE CASCADE,server_id TEXT NOT NULL REFERENCES servers(id) ON DELETE CASCADE,muted_until INTEGER NOT NULL,revision INTEGER NOT NULL,silent_through INTEGER NOT NULL DEFAULT 0,PRIMARY KEY(user_id,server_id)); PRAGMA user_version=20;");
 });
}
// Called after channel access or server membership validation, inside the preference transaction.
function save_notification_mute(string $id,array $in,bool $server=false,?string $category=null): ?array {
 $duration=array_key_exists('mute_for',$in)?$in['mute_for']:'keep';
 if($duration!=='keep'&&(!is_int($duration)||!in_array($duration,[0,900,3600,28800,86400],true)))fail(400,'Choose a mute duration.');
 $table=$category!==null?'category_notification_mutes':($server?'server_notification_mutes':'notification_mutes');$key=$server||$category!==null?'server_id':'channel_id';$ids=[uid(),$id];if($category!==null)$ids[]=$category;$where="user_id=? AND $key=?".($category!==null?' AND category=?':'');$columns="user_id,$key".($category!==null?',category':'');
 $mute=one("SELECT * FROM $table WHERE $where",$ids);if($duration==='keep')return $mute;
 if(!isset($in['mute_revision'])||!is_int($in['mute_revision'])||$in['mute_revision']!==(int)($mute['revision']??0))fail(409,'The mute changed elsewhere. Reopen notification settings and try again.');
 if($duration===0&&(int)($mute['muted_until']??0)<=time())return $mute;
 $watermark=$server?'SELECT COALESCE(max(m.id),0) FROM messages m JOIN channels c ON c.id=m.channel_id WHERE c.server_id=?':'SELECT COALESCE(max(id),0) FROM messages WHERE channel_id=?';
 if($category!==null)$watermark='SELECT COALESCE(max(m.id),0) FROM messages m JOIN channels c ON c.id=m.channel_id LEFT JOIN channels p ON p.id=c.parent_id WHERE c.server_id=? AND COALESCE(p.category,c.category)=?';
 $mute=['muted_until'=>time()+$duration,'revision'=>(int)($mute['revision']??0)+1,'silent_through'=>$duration===0?(int)sql($watermark,$category!==null?[$id,$category]:[$id])->fetchColumn():0];
 sql("INSERT INTO $table($columns,muted_until,revision,silent_through) VALUES(".implode(',',array_fill(0,count($ids)+3,'?')).") ON CONFLICT($columns) DO UPDATE SET muted_until=excluded.muted_until,revision=excluded.revision,silent_through=excluded.silent_through",[...$ids,$mute['muted_until'],$mute['revision'],$mute['silent_through']]);return $mute;
}
function migrate_notification_mutes(): void {
 global $db;if((int)$db->query('PRAGMA user_version')->fetchColumn()>=19)return;
 transaction(function()use($db){if((int)$db->query('PRAGMA user_version')->fetchColumn()>=19)return;
  $db->exec("CREATE TABLE notification_mutes(user_id INTEGER NOT NULL REFERENCES users(id) ON DELETE CASCADE,channel_id TEXT NOT NULL REFERENCES channels(id) ON DELETE CASCADE,muted_until INTEGER NOT NULL,revision INTEGER NOT NULL,silent_through INTEGER NOT NULL DEFAULT 0,PRIMARY KEY(user_id,channel_id)); PRAGMA user_version=19;");
 });
}
function notification_mute_fields(?array $mute): array {
 $until=(int)($mute['muted_until']??0);return ['notification_muted_until'=>$until,'notification_muted'=>$until>time(),'notification_mute_revision'=>(int)($mute['revision']??0)];
}
function migrate_server_notifications(): void {
 global $db;if((int)$db->query('PRAGMA user_version')->fetchColumn()>=18)return;
 transaction(function()use($db){if((int)$db->query('PRAGMA user_version')->fetchColumn()>=18)return;
  $db->exec("CREATE TABLE next_notification_preferences(user_id INTEGER NOT NULL REFERENCES users(id) ON DELETE CASCADE,channel_id TEXT NOT NULL REFERENCES channels(id) ON DELETE CASCADE,mode TEXT NOT NULL CHECK(mode IN ('all','mentions','muted','inherit')),PRIMARY KEY(user_id,channel_id));
   INSERT INTO next_notification_preferences SELECT * FROM notification_preferences;
   DROP TABLE notification_preferences; ALTER TABLE next_notification_preferences RENAME TO notification_preferences;
   CREATE TABLE server_notification_preferences(user_id INTEGER NOT NULL REFERENCES users(id) ON DELETE CASCADE,server_id TEXT NOT NULL REFERENCES servers(id) ON DELETE CASCADE,mode TEXT NOT NULL CHECK(mode IN ('all','mentions','muted')),PRIMARY KEY(user_id,server_id)); PRAGMA user_version=18;");
 });
}
function migrate_notification_preferences(): void {
 global $db;if((int)$db->query('PRAGMA user_version')->fetchColumn()>=17)return;
 transaction(function()use($db){if((int)$db->query('PRAGMA user_version')->fetchColumn()>=17)return;
  $db->exec("CREATE TABLE notification_preferences(user_id INTEGER NOT NULL REFERENCES users(id) ON DELETE CASCADE,channel_id TEXT NOT NULL REFERENCES channels(id) ON DELETE CASCADE,mode TEXT NOT NULL CHECK(mode IN ('all','mentions','muted')),PRIMARY KEY(user_id,channel_id)); PRAGMA user_version=17;");
 });
}
function migrate_notifications(): void {
 global $db;if((int)$db->query('PRAGMA user_version')->fetchColumn()>=5)return;
 transaction(function()use($db){if((int)$db->query('PRAGMA user_version')->fetchColumn()>=5)return;
  $db->exec("ALTER TABLE reactions RENAME TO old_reactions;
   CREATE TABLE reactions(message_id INTEGER NOT NULL REFERENCES messages(id) ON DELETE CASCADE,user_id INTEGER NOT NULL REFERENCES users(id),emoji TEXT NOT NULL,PRIMARY KEY(message_id,user_id,emoji));
   INSERT INTO reactions SELECT message_id,user_id,'❤️' FROM old_reactions;
   DROP TABLE old_reactions;
   CREATE TABLE notifications(user_id INTEGER NOT NULL REFERENCES users(id),message_id INTEGER NOT NULL REFERENCES messages(id) ON DELETE CASCADE,kind TEXT NOT NULL,seen INTEGER NOT NULL DEFAULT 0,PRIMARY KEY(user_id,message_id));
   PRAGMA user_version=5;");
 });
}
function can_view_as(string $cid,int $who): bool {
 global $user;$saved=$user;$user=['id'=>$who];try{channel($cid);return true;}catch(ApiError $e){if(in_array($e->status,[403,404],true))return false;throw $e;}finally{$user=$saved;}
}
function mention_text(string $body): string {
 $body=preg_replace('/```[\s\S]*?```|`[^`\n]+`/',' ',$body);
 $body=preg_replace('~\[([^\]\n]+)\]\(([^()\s]+(?:\([^()\s]*\)[^()\s]*)*)\)~','$1',$body);
 $body=preg_replace('~(?<![a-z0-9_@/\\\\])https?://(?:(?!\~\~)[^\s<>"\'`*|])+~i',' ',$body);
 return $body;
}
function mention_names(string $body): array {
 preg_match_all('/(?<![a-z0-9_@.])@([a-z0-9_]{3,24})(?![a-z0-9_])/i',mention_text($body),$matches);return array_values(array_unique(array_map('strtolower',$matches[1])));
}
function search_web_link(string $url): bool {
 if(preg_match_all('/./us',$url)+preg_match_all('/[\x{10000}-\x{10FFFF}]/u',$url)>2048||!preg_match('~^https?://~i',$url)||preg_match('/[\x00-\x20\x7f<>"\'\\\\]/',$url))return false;
 // shortcut: search accepts standard ASCII hosts and canonical IPs; use a shared WHATWG parser for IDNs and legacy numeric forms.
 // parse_url accepts numeric prefixes of malformed ports; check the original authority too.
 $parts=parse_url($url);if($parts===false||!isset($parts['host'])||!preg_match('~^https?://([^/?#]*)~i',$url,$authority)||!preg_match('~^(?:@|:@)?'.preg_quote($parts['host'],'~').'(?::[0-9]*)?$~D',$authority[1]))return false;if(isset($parts['host'])&&preg_match('/(?:^|\.)(?:[0-9]+|0x[0-9a-f]+)\.?$/i',$parts['host'])&&filter_var($parts['host'],FILTER_VALIDATE_IP,FILTER_FLAG_IPV4)===false)return false;return $parts!==false&&($parts['user']??'')===''&&($parts['pass']??'')===''&&isset($parts['host'])&&filter_var($parts['scheme'].'://'.$parts['host'].(isset($parts['port'])?':'.$parts['port']:''),FILTER_VALIDATE_URL)!==false;
}
function message_link_chunks(string $body): array {
 // Match messageContent's structural boundaries before applying inline token precedence.
 $item=static function($line){if(!is_string($line))return $line['entry']??null;if(!preg_match('/^( {0,14})([-*]|\d{1,9}\.)( +)(.+)$/',$line,$m)||strlen($m[1])%2)return null;return ['indent'=>strlen($m[1]),'contentIndent'=>strlen($m[1].$m[2].$m[3]),'tag'=>ctype_digit($m[2][0])?'ol':'ul','text'=>$m[4]];};
 $blank=static fn($line)=>is_string($line)&&preg_match('/^[\s\x{FEFF}]*$/u',$line);
 $aligned=static fn($line,$indent)=>is_string($line)?str_starts_with($line,str_repeat(' ',$indent)):!isset($line['entry'])&&$line['indent']>=$indent;
 $lines=[];$tail='';$cursor=0;$afterCode=false;
 $append=static function($text)use(&$lines,&$tail,&$afterCode){if($afterCode)$text=preg_replace('/^\r?\n/','',$text);$rows=explode("\n",$text);$tail.=array_shift($rows);foreach($rows as $row){$lines[]=$tail;$tail=$row;}$afterCode=false;};
 preg_match_all('/```[\s\S]*?```/',$body,$fences,PREG_OFFSET_CAPTURE);
 foreach($fences[0] as [$fence,$offset]){
  $append(substr($body,$cursor,$offset-$cursor));$direct=preg_match('/^( {0,14})([-*]|\d{1,9}\.)( +)$/',$tail)?$item($tail.'x'):null;
  if($direct){$direct['text']='';$lines[]=['entry'=>$direct];}
  else{if(!$blank($tail))$lines[]=$tail;$lines[]=['indent'=>preg_match('/^ *$/',$tail)?strlen($tail):0];}
  $tail='';$afterCode=true;$cursor=$offset+strlen($fence);
 }
 $append(substr($body,$cursor));$lines[]=$tail;$chunks=[];
 $list=static function($start)use(&$list,&$chunks,$lines,$item,$blank,$aligned){
  $first=$item($lines[$start]);$end=$start;$count=count($lines);
  while($end<$count){
   $entry=$item($lines[$end]);if(!$entry||$entry['indent']!==$first['indent']||$entry['tag']!==$first['tag'])break;
   $text=$entry['text'];$end++;$flush=static function()use(&$chunks,&$text){if($text!=='')$chunks[]=$text;$text='';};
   while($end<$count){
    if($blank($lines[$end])){
     $after=$end;while($after<$count&&$blank($lines[$after]))$after++;if($after===$count)break;$next=$item($lines[$after]);
     if($next&&$next['indent']===$first['indent']&&$next['tag']===$first['tag']){$end=$after;break;}
     if($next&&$next['indent']===$first['indent']+2||!$next&&$aligned($lines[$after],$entry['contentIndent'])){$flush();$end=$after;continue;}break;
    }
    $next=$item($lines[$end]);
    if($next&&$next['indent']===$first['indent']+2){$flush();$end=$list($end);}
    elseif(!$next&&$aligned($lines[$end],$entry['contentIndent'])){if(is_string($lines[$end]))$text.=($text!==''?"\n":'').substr($lines[$end],$entry['contentIndent']);else $flush();$end++;}
    else break;
   }
   $flush();
  }
  return $end;
 };
 for($i=0;$i<count($lines);$i++){
  if($item($lines[$i])){$i=$list($i)-1;continue;}if(!is_string($lines[$i]))continue;
  $chunks[]=preg_match('/^(#{1,3}) +(.+)$/',$lines[$i],$heading)?$heading[2]:(str_starts_with($lines[$i],'> ')?substr($lines[$i],2):$lines[$i]);
 }
 return $chunks;
}
function message_has_link(string $body): bool {
 foreach(message_link_chunks($body) as $chunk)if(message_inline_has_link($chunk))return true;return false;
}
function message_inline_has_link(string $body,int $depth=0): bool {
 if($depth>=8)return false;
 // Keep token precedence and formatting depth aligned with messageContent; spoilers count even before reveal.
 $token='%\\\\([\\\\`*_~|<>\[\]()#+.\-])|`([^`\n]+)`|\[([^\]\n]+)\]\(([^()\s]+(?:\([^()\s]*\)[^()\s]*)*)\)|(?<![a-z0-9_@/\\\\])(https?://(?:(?!~~)[^\s<>"\'`*|])+)|(?!(?<=[a-z0-9_])_)(\*\*\*|\*\*|__|~~|\|\||\*|_)(?=\S)([\s\S]*?\S)\6%i';
 preg_match_all($token,$body,$matches,PREG_SET_ORDER|PREG_UNMATCHED_AS_NULL);
  foreach($matches as $m){
   if($m[3]!==null){if(search_web_link($m[4]))return true;}
   elseif($m[5]!==null){$raw=$m[5];do{$last=substr($raw,-1);$pairs=[')'=>'(',']'=>'[','}'=>'{'];$trim=str_contains('.,!?;:~',$last)||isset($pairs[$last])&&substr_count($raw,$last)>substr_count($raw,$pairs[$last]);if($trim)$raw=substr($raw,0,-1);}while($raw!==''&&$trim);if(search_web_link($raw))return true;}
   elseif($m[6]!==null&&message_inline_has_link($m[7],$depth+1))return true;
  }
 return false;
}
function register_link_search(): void {
 global $db;$method=method_exists($db,'createFunction')?'createFunction':'sqliteCreateFunction';
 if(!$db->$method('gather_has_link',fn($body)=>(int)message_has_link($body),1))throw new RuntimeException('Could not register link search.');
}
function register_mention_search(): void {
 global $db;$method=method_exists($db,'createFunction')?'createFunction':'sqliteCreateFunction';
 if(!$db->$method('gather_mentions',fn($body,$name)=>(int)in_array($name,mention_names($body),true),2))throw new RuntimeException('Could not register mention search.');
}
function mention_roles(string $body): array {
 preg_match_all('/<@&([a-f0-9]{32})>/',mention_text($body),$matches);return array_values(array_unique($matches[1]));
}
function role_preview(string $body,array $names): string {
 // Match code and whole links first so only mention text, including link labels, is substituted.
 return preg_replace_callback('~```[\s\S]*?```|`[^`\n]+`|\[([^\]\n]+)\]\(([^()\s]+(?:\([^()\s]*\)[^()\s]*)*)\)|<@&([a-f0-9]{32})>~',function($m)use($names){
  if(isset($m[3]))return '@'.($names[$m[3]]??'Deleted role');
  if(isset($m[1]))return '['.role_preview($m[1],$names).']('.$m[2].')';
  return $m[0];
 },$body);
}
function notify_message(int $id,array $channel,string $body,?int $reply,?string $broadcast=null): void {
 $targets=[];if($reply){$original=one('SELECT user_id FROM messages WHERE id=? AND channel_id=? AND deleted=0',[$reply,$channel['id']]);if($original)$targets[(int)$original['user_id']]='reply';}
 $names=mention_names($body);$roles=mention_roles($body);if(count($names)+count($roles)>20)fail(400,'Use up to 20 person or role mentions per message.');
 if($roles){if(!$channel['server_id'])fail(400,'Role mentions are only available in servers.');require_permission($channel['server_id'],'mention_roles');
  foreach($roles as $role)if(!one('SELECT id FROM roles WHERE id=? AND server_id=?',[$role,$channel['server_id']]))fail(400,'A mentioned role is no longer available in this server.');
  // shortcut: bound role expansion for shared hosting; use queued delivery before supporting larger mentions.
  $marks=implode(',',array_fill(0,count($roles),'?'));$people=sql("SELECT DISTINCT user_id FROM member_roles WHERE server_id=? AND role_id IN ($marks) LIMIT 201",[$channel['server_id'],...$roles])->fetchAll();if(count($people)>200)fail(400,'This role mention is too large. Mention a smaller role.');
  foreach($people as $person)$targets[(int)$person['user_id']]??='role';
 }
 if($broadcast){
  if(!$channel['server_id']||!in_array($broadcast,$names,true))fail(400,"Use @$broadcast in a server message.");
  require_permission($channel['server_id'],'mention_everyone');
  $people=$broadcast==='here'
   ?sql("SELECT m.user_id FROM members m JOIN users u ON u.id=m.user_id WHERE m.server_id=? AND u.status='online' AND EXISTS(SELECT 1 FROM sessions s WHERE s.user_id=u.id AND s.expires>? AND s.seen>=? AND s.idle=0) LIMIT 101",[$channel['server_id'],time(),time()-45])->fetchAll()
   :sql('SELECT user_id FROM members WHERE server_id=? LIMIT 101',[$channel['server_id']])->fetchAll();
  if(count($people)>100)fail(400,'This server is too large for an everyone mention.');
  foreach($people as $person){
   $who=(int)$person['user_id'];
   if(!isset($targets[$who]))$targets[$who]='everyone';
   elseif($targets[$who]==='role')$targets[$who]='role_everyone';
  }
  $names=array_diff($names,['everyone','here']);
 }
 foreach($names as $name){$person=one('SELECT id FROM users WHERE username=?',[$name]);if($person)$targets[(int)$person['id']]='mention';}
 foreach($targets as $who=>$kind){if($who===uid()||blocked($who)||!can_view_as($channel['id'],$who))continue;sql('INSERT OR IGNORE INTO notifications VALUES(?,?,?,0)',[$who,$id,$kind]);
  // shortcut: retain the latest 500 events per person; add paged archival if larger inboxes are needed.
  sql('DELETE FROM notifications WHERE user_id=? AND message_id NOT IN (SELECT message_id FROM notifications WHERE user_id=? ORDER BY message_id DESC LIMIT 500)',[$who,$who]);
 }
}
function inbox_rows(bool $unread=false): array {
 $rows=sql('SELECT n.message_id,n.kind,n.seen,m.channel_id,m.body,m.created,m.user_id,u.name,u.username,u.avatar,c.name AS channel_name,c.server_id,s.name AS server_name FROM notifications n JOIN messages m ON m.id=n.message_id JOIN users u ON u.id=m.user_id JOIN channels c ON c.id=m.channel_id LEFT JOIN servers s ON s.id=c.server_id WHERE n.user_id=? AND m.deleted=0'.($unread?' AND n.seen=0':'').' AND NOT EXISTS(SELECT 1 FROM blocks b WHERE (b.user_id=? AND b.target=m.user_id) OR (b.target=? AND b.user_id=m.user_id)) ORDER BY n.message_id DESC LIMIT 500',[uid(),uid(),uid()])->fetchAll();
 $visible=[];$result=[];$roleNames=[];foreach($rows as $row){$cid=$row['channel_id'];if(!array_key_exists($cid,$visible))$visible[$cid]=can_view_as($cid,uid());if(!$visible[$cid])continue;$sid=$row['server_id'];if($sid&&!isset($roleNames[$sid]))$roleNames[$sid]=array_column(sql('SELECT id,name FROM roles WHERE server_id=?',[$sid])->fetchAll(),'name','id');$row['preview']=role_preview($row['body'],$roleNames[$sid]??[]);if(in_array($row['kind'],['role','everyone','role_everyone'],true))$row['kind']='mention';if(!$row['server_id']&&one('SELECT 1 FROM channels WHERE id=? AND pair_key IS NOT NULL',[$cid]))$row['channel_name']=$row['name'];$result[]=$row;}return $result;
}
function inbox_summary(array $channels): array {
 $visible=array_fill_keys(array_column($channels,'id'),true);$result=[];
 foreach(sql('SELECT m.channel_id,count(*) AS total,min(m.id) AS first_id,max(m.id) AS last_id,max(CASE WHEN (CASE n.kind WHEN \'role\' THEN COALESCE(np.suppress_roles,0)=0 WHEN \'everyone\' THEN COALESCE(np.suppress_everyone,0)=0 WHEN \'role_everyone\' THEN (COALESCE(np.suppress_roles,0)=0 OR COALESCE(np.suppress_everyone,0)=0) ELSE 1 END) AND m.created>=max(COALESCE(nm.muted_until,0),COALESCE(sm.muted_until,0),COALESCE(cm.muted_until,0)) AND m.id>max(COALESCE(nm.silent_through,0),COALESCE(sm.silent_through,0),COALESCE(cm.silent_through,0)) THEN m.id END) AS alert_id FROM notifications n JOIN messages m ON m.id=n.message_id LEFT JOIN notification_mutes nm ON nm.user_id=n.user_id AND nm.channel_id=m.channel_id LEFT JOIN channels mc ON mc.id=m.channel_id LEFT JOIN channels mp ON mp.id=mc.parent_id LEFT JOIN category_notification_mutes cm ON cm.user_id=n.user_id AND cm.server_id=mc.server_id AND cm.category=COALESCE(mp.category,mc.category) LEFT JOIN server_notification_mutes sm ON sm.user_id=n.user_id AND sm.server_id=mc.server_id LEFT JOIN server_notification_preferences np ON np.user_id=n.user_id AND np.server_id=mc.server_id WHERE n.user_id=? AND n.seen=0 AND m.deleted=0 AND NOT EXISTS(SELECT 1 FROM blocks b WHERE (b.user_id=? AND b.target=m.user_id) OR (b.target=? AND b.user_id=m.user_id)) GROUP BY m.channel_id',[uid(),uid(),uid()])->fetchAll() as $r)if(isset($visible[$r['channel_id']]))$result[$r['channel_id']]=['total'=>(int)$r['total'],'first_id'=>(int)$r['first_id'],'last_id'=>(int)$r['last_id'],'alert_id'=>(int)($r['alert_id']??0)];return $result;
}
function reaction_channel(int $id): array {
 $msg=one('SELECT * FROM messages WHERE id=? AND deleted=0',[$id]);if(!$msg)fail(404,'Message not found.');$c=channel($msg['channel_id']);
  if($c['server_id']){if(member($c['server_id'])['muted_until']>time())fail(403,'You cannot change reactions while timed out.');if($c['parent_id']&&($c['archived']||($c['auto_archive_minutes']>0&&$c['thread_activity']+$c['auto_archive_minutes']*60<=time())))fail(403,'Reopen this thread before changing reactions.');}else channel($c['id'],true);
 return $c;
}
function react_message(int $id,array $c,array $in): void {
 // Re-read message and access after taking the write lock; keep the old signature for staged deployment.
 $emoji=$in['emoji']??'❤️';if(!is_string($emoji)||strlen($emoji)>50)fail(400,'Invalid reaction.');if(array_key_exists('selected',$in)&&!is_bool($in['selected']))fail(400,'Invalid reaction state.');
 transaction(function()use($id,$in,$emoji){
  $c=reaction_channel($id);
  $exists=!!one('SELECT 1 FROM reactions WHERE message_id=? AND user_id=? AND emoji=?',[$id,uid(),$emoji]);$add=$in['selected']??!$exists;if(!$add){sql('DELETE FROM reactions WHERE message_id=? AND user_id=? AND emoji=?',[$id,uid(),$emoji]);return;}if($exists)return;
  if(!one('SELECT 1 FROM reactions WHERE message_id=? AND emoji=?',[$id,$emoji])&&!channel_capabilities($c)['add_reactions'])fail(403,'You need Add Reactions to start a new emoji reaction.');
  if(!in_array($emoji,['😀','😃','😂','😊','😍','🤔','😢','😴','😮','👋','👋🏻','👋🏼','👋🏽','👋🏾','👋🏿','👍','👍🏻','👍🏼','👍🏽','👍🏾','👍🏿','👎','👎🏻','👎🏼','👎🏽','👎🏾','👎🏿','👏','👏🏻','👏🏼','👏🏽','👏🏾','👏🏿','🙌','🙌🏻','🙌🏼','🙌🏽','🙌🏾','🙌🏿','🙏','🙏🏻','🙏🏼','🙏🏽','🙏🏾','🙏🏿','💪','💪🏻','💪🏼','💪🏽','💪🏾','💪🏿','🤝','🤝🏻','🤝🏼','🤝🏽','🤝🏾','🤝🏿','👀','🐶','🐱','🐼','🦊','🌸','🌳','☀️','🌈','🍎','🍕','🍔','🍟','🍜','🍰','☕','🧋','⚽','🏀','🎮','🎲','🎨','🎸','🎬','🏆','💻','📱','💡','📚','🔑','🎁','🚀','📷','❤️','💜','✅','❌','⚠️','⭐','🔥','🎉'],true)){
   if(!preg_match('/^custom:([a-f0-9]{32})$/',$emoji,$match)||!$c['server_id']||!one("SELECT 1 FROM media WHERE id=? AND server_id=? AND slot LIKE 'emoji:%'",[$match[1],$c['server_id']]))fail(400,'Choose a reaction from this server.');
  }
  if(sql('SELECT count(*) FROM reactions WHERE message_id=? AND user_id=?',[$id,uid()])->fetchColumn()>=10)fail(409,'Use up to 10 reactions per message.');if(!one('SELECT 1 FROM reactions WHERE message_id=? AND emoji=?',[$id,$emoji])&&sql('SELECT count(DISTINCT emoji) FROM reactions WHERE message_id=?',[$id])->fetchColumn()>=20)fail(409,'This message already has 20 different reactions.');
  sql('INSERT INTO reactions VALUES(?,?,?)',[$id,uid(),$emoji]);
 });
}
function search_conversation(string $id): array {
 $c=channel($id);if($c['server_id']||$c['kind']!=='text')fail(400,'Choose a direct or group conversation.');
 if(!$c['group_owner']){$peer=(int)sql('SELECT user_id FROM channel_members WHERE channel_id=? AND user_id<>?',[$id,uid()])->fetchColumn();if($peer&&blocked($peer))fail(403,'This conversation is unavailable.');}return $c;
}
function notification_actions(string $action,array $in): array {
 switch($action){
 case 'category_notification_preference':
  $sid=text_field($in,'server_id',32,32);$category=text_field($in,'category',1,40);$mode=$in['mode']??null;if(!is_string($mode)||!in_array($mode,['all','mentions','muted','inherit'],true))fail(400,'Choose a notification setting.');
  return transaction(function()use($sid,$category,$mode,$in){member($sid);if(array_key_exists('category_id',$in)){$id=text_field($in,'category_id',32,32);if(!one('SELECT 1 FROM explicit_categories WHERE server_id=? AND name=? AND id=?',[$sid,$category,$id]))fail(409,'This category changed. Reopen its notification settings.');}$visible=array_column(server_categories($sid),'name');if(!in_array($category,$visible,true))fail(403,'Choose a category you can view.');
   $mute=save_notification_mute($sid,$in,false,$category);if($mode==='inherit')sql('DELETE FROM category_notification_preferences WHERE user_id=? AND server_id=? AND category=?',[uid(),$sid,$category]);else sql('INSERT INTO category_notification_preferences VALUES(?,?,?,?) ON CONFLICT(user_id,server_id,category) DO UPDATE SET mode=excluded.mode',[uid(),$sid,$category,$mode]);return ['ok'=>true,'mode'=>$mode==='inherit'?null:$mode]+notification_mute_fields($mute);
  });
 case 'server_notification_preference':
  $id=text_field($in,'server_id',32,32);$mode=$in['mode']??null;if(!is_string($mode)||!in_array($mode,['all','mentions','muted'],true))fail(400,'Choose a notification setting.');
  if(array_key_exists('suppress_roles',$in)&&!is_bool($in['suppress_roles']))fail(400,'Choose whether to suppress role alerts.');
  if(array_key_exists('suppress_everyone',$in)&&!is_bool($in['suppress_everyone']))fail(400,'Choose whether to suppress everyone alerts.');
  return transaction(function()use($id,$mode,$in){
   member($id);
   $previous=one('SELECT suppress_roles,suppress_everyone FROM server_notification_preferences WHERE user_id=? AND server_id=?',[uid(),$id]);
   $roles=$in['suppress_roles']??(bool)($previous['suppress_roles']??false);
   $everyone=$in['suppress_everyone']??(bool)($previous['suppress_everyone']??false);
   $mute=save_notification_mute($id,$in,true);
   sql('INSERT INTO server_notification_preferences(user_id,server_id,mode,suppress_roles,suppress_everyone) VALUES(?,?,?,?,?) ON CONFLICT(user_id,server_id) DO UPDATE SET mode=excluded.mode,suppress_roles=excluded.suppress_roles,suppress_everyone=excluded.suppress_everyone',[uid(),$id,$mode,(int)$roles,(int)$everyone]);
   return ['ok'=>true,'mode'=>$mode,'suppress_roles'=>$roles,'suppress_everyone'=>$everyone]+notification_mute_fields($mute);
  });
 case 'notification_preference':
  $id=text_field($in,'channel_id',32,32);$mode=$in['mode']??null;if(!is_string($mode)||!in_array($mode,['all','mentions','muted','inherit','category'],true))fail(400,'Choose a notification setting.');
  return transaction(function()use($id,$mode,$in){$c=channel($id);if(in_array($mode,['inherit','category'],true)&&!$c['server_id'])fail(400,'Direct messages have independent notification settings.');$mute=save_notification_mute($id,$in);
   sql('INSERT INTO notification_preferences(user_id,channel_id,mode) VALUES(?,?,?) ON CONFLICT(user_id,channel_id) DO UPDATE SET mode=excluded.mode',[uid(),$id,$mode]);return ['ok'=>true,'mode'=>$mode]+notification_mute_fields($mute);
  });
 case 'inbox':$rows=inbox_rows(($in['unread']??false)===true);return ['items'=>array_slice($rows,0,100),'unread'=>count(array_filter($rows,fn($r)=>!$r['seen'])),'limited'=>count($rows)>100];
 case 'inbox_read':
  if(($in['all']??false)===true)sql('UPDATE notifications SET seen=1 WHERE user_id=?',[uid()]);else{$id=positive_id($in['message_id']??null);sql('UPDATE notifications SET seen=1 WHERE user_id=? AND message_id=?',[uid(),$id]);}return ['ok'=>true];
 case 'mention_members':
  $c=channel(text_field($in,'channel_id',32,32));$people=$c['server_id']?sql('SELECT u.* FROM users u JOIN members m ON m.user_id=u.id WHERE m.server_id=? ORDER BY u.name',[$c['server_id']])->fetchAll():sql('SELECT u.* FROM users u JOIN channel_members m ON m.user_id=u.id WHERE m.channel_id=? ORDER BY u.name',[$c['id']])->fetchAll();
  $permissions=$c['server_id']?permissions_for($c['server_id']):[];
  return ['everyone'=>in_array('mention_everyone',$permissions,true),'roles'=>in_array('mention_roles',$permissions,true)?sql('SELECT id,name FROM roles WHERE server_id=? ORDER BY position DESC,id',[$c['server_id']])->fetchAll():[],'members'=>array_values(array_map('public_user',array_filter($people,fn($p)=>(int)$p['id']!==uid()&&!blocked((int)$p['id'])&&can_view_as($c['id'],(int)$p['id']))))];
 case 'reaction_remove':
  $id=positive_id($in['message_id']??null);$scope=$in['scope']??null;if(!in_array($scope,['user','emoji','all'],true))fail(400,'Choose a reaction removal scope.');
  $emoji=null;$target=null;if($scope!=='all'){$emoji=$in['emoji']??null;if(!is_string($emoji)||$emoji===''||strlen($emoji)>50)fail(400,'Invalid reaction.');}elseif(array_key_exists('emoji',$in))fail(400,'All reactions does not accept an emoji.');
  if($scope==='user')$target=positive_id($in['user_id']??null);elseif(array_key_exists('user_id',$in))fail(400,'Choose the user removal scope.');
  rate('message-action:'.uid(),60,60);
  return transaction(function()use($id,$scope,$emoji,$target){
   $c=reaction_channel($id);if(!$c['server_id'])fail(403,'Reaction moderation is only available in servers.');require_permission($c['server_id'],'manage_messages');
   $query='DELETE FROM reactions WHERE message_id=?';$args=[$id];if($scope!=='all'){$query.=' AND emoji=?';$args[]=$emoji;}if($scope==='user'){$query.=' AND user_id=?';$args[]=$target;}sql($query,$args);return ['ok'=>true];
  });
 case 'conversation_search_authors':
  if(array_diff(array_keys($in),['action','channel_id']))fail(400,'Invalid author request.');$c=search_conversation(text_field($in,'channel_id',32,32));
  return ['members'=>sql("SELECT u.id,u.name,u.username,'' AS nickname FROM channel_members m JOIN users u ON u.id=m.user_id WHERE m.channel_id=? AND NOT EXISTS(SELECT 1 FROM blocks b WHERE (b.user_id=? AND b.target=u.id) OR (b.target=? AND b.user_id=u.id)) ORDER BY u.name,u.id",[$c['id'],uid(),uid()])->fetchAll()];
 case 'server_search_authors':
  if(array_diff(array_keys($in),['action','server_id']))fail(400,'Invalid author request.');
  $sid=text_field($in,'server_id',32,32);member($sid);
  return ['members'=>sql('SELECT u.id,u.name,u.username,m.nickname FROM members m JOIN users u ON u.id=m.user_id WHERE m.server_id=? AND NOT EXISTS(SELECT 1 FROM blocks b WHERE (b.user_id=? AND b.target=u.id) OR (b.target=? AND b.user_id=u.id)) ORDER BY u.name,u.id',[$sid,uid(),uid()])->fetchAll()];
 case 'conversation_search':case 'server_search':
  $direct=$action==='conversation_search';$keys=['action','search','before','after','order','channel_id','after_date','before_date','on_date','file_type','author_id','mention_id','has_link','pins','rank_cursor'];if(!$direct)$keys[]='server_id';if(array_diff(array_keys($in),$keys))fail(400,'Invalid search request.');
  $ordering=array_key_exists('order',$in)?$in['order']:'newest';if(!in_array($ordering,['newest','oldest','relevant'],true))fail(400,'Choose newest, oldest or most relevant order.');$oldest=$ordering==='oldest';$ranked=$ordering==='relevant';$direction=$oldest?'ASC':'DESC';$cursorKey=$ranked?'rank_cursor':($oldest?'after':'before');
  if($ranked?(array_key_exists('before',$in)||array_key_exists('after',$in)):(array_key_exists('rank_cursor',$in)||array_key_exists($oldest?'before':'after',$in)))fail(400,'Cursor direction must match search order.');
  $sid=null;if($direct)$conversation=search_conversation(text_field($in,'channel_id',32,32));else{$sid=text_field($in,'server_id',32,32);member($sid);}$rawSearch=$in['search']??null;if(is_string($rawSearch)&&preg_match('/[\x00-\x08\x0B\x0C\x0E-\x1F]/',$rawSearch))fail(400,'Invalid search text.');$query=text_field($in,'search',0,100);if($ranked&&$query==='')fail(400,'Enter message text for most relevant order.');$cursor=0;
  if(array_key_exists($cursorKey,$in)){
   $cursor=$in[$cursorKey];if($ranked){if(!is_array($cursor)||count($cursor)!==3||array_diff(array_keys($cursor),['rank','length','id'])||!isset($cursor['rank'],$cursor['length'],$cursor['id'])||!is_int($cursor['rank'])||$cursor['rank']<0||$cursor['rank']>2||!is_int($cursor['length'])||$cursor['length']<1||$cursor['length']>9007199254740991)fail(400,'Invalid relevance cursor.');$cursorId=positive_id($cursor['id']);}else{$cursor=positive_id($cursor);$cursorId=$cursor;}
   if($cursorId>9007199254740991)fail(400,'Invalid search cursor.');if($direct&&!one('SELECT 1 FROM messages WHERE id=? AND channel_id=?',[$cursorId,$conversation['id']]))fail(400,'Search cursor must belong to this conversation.');
  }
  $hasLink=$in['has_link']??false;if(array_key_exists('has_link',$in)&&!is_bool($in['has_link']))fail(400,'Invalid link filter.');if($hasLink)register_link_search();
  $pins=$in['pins']??false;if(array_key_exists('pins',$in)&&!is_bool($in['pins']))fail(400,'Invalid pinned-message filter.');
  $dates=message_search_dates($in);$extensions=array_key_exists('file_type',$in)?attachment_type_extensions($in['file_type']):null;
  $author=null;if(array_key_exists('author_id',$in)){$author=message_search_author($in['author_id']);if($direct?!can_view_as($conversation['id'],$author):!one('SELECT 1 FROM members WHERE server_id=? AND user_id=?',[$sid,$author]))fail(403,'Choose a current author in this search.');}
  $mentioned=null;$mentionName=null;if(array_key_exists('mention_id',$in)){
   $mentioned=positive_id($in['mention_id']);if($mentioned>9007199254740991)fail(400,'Invalid mentioned person.');
   if(blocked($mentioned)||($direct?!can_view_as($conversation['id'],$mentioned):!one('SELECT 1 FROM members WHERE server_id=? AND user_id=?',[$sid,$mentioned])))fail(403,'Choose a current unblocked mentioned person in this search.');
   $mentionName=one('SELECT username FROM users WHERE id=?',[$mentioned])['username']??fail(403,'This mentioned person is no longer available.');register_mention_search();
  }
  if($query===''&&$author===null&&$mentioned===null&&$extensions===null&&!$hasLink&&!$pins&&!$dates&&($direct||!array_key_exists('channel_id',$in)))fail(400,'Enter message text or choose at least one filter.');
  if($direct)$visible=[$conversation['id']];
  elseif(array_key_exists('channel_id',$in)){$selected=channel(text_field($in,'channel_id',32,32));if($selected['server_id']!==$sid||$selected['kind']!=='text')fail(403,'Choose a readable text channel in this server.');if($author!==null&&!can_view_as($selected['id'],$author))fail(403,'This author is not available in this conversation.');if($mentioned!==null&&!can_view_as($selected['id'],$mentioned))fail(403,'This mentioned person is not available in this conversation.');$visible=[$selected['id']];}
  else{$visible=[];foreach(sql("SELECT id FROM channels WHERE server_id=? AND kind='text'",[$sid])->fetchAll() as $c)if(can_view_as($c['id'],uid())&&($author===null||can_view_as($c['id'],$author))&&($mentioned===null||can_view_as($c['id'],$mentioned)))$visible[]=$c['id'];}
  $rows=[];$folded=message_casefold($query);if($query!=='')register_casefold_search();$phrase=$query!==''?message_search_phrase($folded):null;
  // Keep SQLite parameter counts bounded even when a server has many threads.
  foreach(array_chunk($visible,500) as $ids){
   $where='m.channel_id IN ('.implode(',',array_fill(0,count($ids),'?')).') AND m.deleted=0 AND NOT EXISTS(SELECT 1 FROM blocks b WHERE (b.user_id=? AND b.target=m.user_id) OR (b.target=? AND b.user_id=m.user_id))';$args=array_merge([$sid],$ids,[uid(),uid()]);if($cursor&&!$ranked){$where.=' AND m.id'.($oldest?'>':'<').'?';$args[]=$cursor;}
   if($query!==''){$where.=' AND instr(gather_casefold(m.body),?)>0';$args[]=$folded;}
   // shortcut: Non-ASCII bodies bypass the ASCII index; migrate to a folded index if profiling warrants it.
   if($phrase!==null){$where.=" AND (m.id IN (SELECT rowid FROM message_search WHERE message_search MATCH ?) OR m.body GLOB '*[^ -~]*')";$args[]=$phrase;}
   if($author!==null){$where.=' AND m.user_id=?';$args[]=$author;}
   if($pins)$where.=' AND m.pinned=1';
   if($hasLink)$where.=' AND gather_has_link(m.body)=1';
   if($mentionName!==null){$where.=' AND gather_mentions(m.body,?)=1';$args[]=$mentionName;}
   if(isset($dates['after_date'])){$where.=' AND m.created>=?';$args[]=$dates['after_date'];}if(isset($dates['before_date'])){$where.=' AND m.created<?';$args[]=$dates['before_date'];}
   if($extensions!==null){$fileWhere='';if($extensions){$fileWhere=' AND ('.implode(' OR ',array_fill(0,count($extensions),'lower(a.name) LIKE ?')).')';foreach($extensions as $extension)$args[]='%.'.$extension;}$where.=' AND EXISTS(SELECT 1 FROM attachments a WHERE a.message_id=m.id AND a.channel_id=m.channel_id'.$fileWhere.')';}
   $rankFields=$ranked?"CASE WHEN gather_casefold(m.body)=? THEN 0 WHEN instr(gather_casefold(m.body),?)=1 THEN 1 ELSE 2 END AS search_rank,length(m.body) AS search_length,":'';
   $select="SELECT $rankFields m.id,m.channel_id,c.name AS channel_name,m.created,u.name,COALESCE(mb.nickname,'') AS nickname,CASE WHEN length(m.body)<=1000 THEN m.body END AS content FROM messages m JOIN channels c ON c.id=m.channel_id JOIN users u ON u.id=m.user_id LEFT JOIN members mb ON mb.user_id=m.user_id AND mb.server_id=? WHERE $where";
   if($ranked){
    array_unshift($args,$folded,$folded);$boundary='';if($cursor){$boundary=' WHERE search_rank>CAST(? AS INTEGER) OR (search_rank=CAST(? AS INTEGER) AND (search_length>CAST(? AS INTEGER) OR (search_length=CAST(? AS INTEGER) AND id<?)))';array_push($args,$cursor['rank'],$cursor['rank'],$cursor['length'],$cursor['length'],$cursor['id']);}
    // Rank the filtered candidates identically with or without the optional text index.
    $select="SELECT * FROM ($select)$boundary ORDER BY search_rank,search_length,id DESC LIMIT 31";
   }else $select.=" ORDER BY m.id $direction LIMIT 31";
   $rows=array_merge($rows,sql($select,$args)->fetchAll());
  }
  usort($rows,fn($a,$b)=>$ranked?(($a['search_rank']<=>$b['search_rank'])?:($a['search_length']<=>$b['search_length'])?:($b['id']<=>$a['id'])):($oldest?($a['id']<=>$b['id']):($b['id']<=>$a['id'])));$more=count($rows)>30;$rows=array_slice($rows,0,30);$last=end($rows);$next=$more?($ranked?['rank'=>(int)$last['search_rank'],'length'=>(int)$last['search_length'],'id'=>(int)$last['id']]:(int)$last['id']):null;$names=$sid?array_column(sql('SELECT id,name FROM roles WHERE server_id=?',[$sid])->fetchAll(),'name','id'):[];
  foreach($rows as &$row){unset($row['search_rank'],$row['search_length']);$row['role_mentions']=[];if($row['content']!==null)foreach(mention_roles($row['content']) as $role)$row['role_mentions'][]=['id'=>$role,'name'=>$names[$role]??'Deleted role'];}unset($row);return ['messages'=>$rows,'next'=>$next];
 case 'pin_list':
  $cid=text_field($in,'channel_id',32,32);$c=channel($cid);$canUnpin=true;try{require_pin_channel($c);}catch(ApiError $e){if($e->status!==403)throw $e;$canUnpin=false;}$where='m.channel_id=? AND m.pinned=1 AND m.deleted=0 AND NOT EXISTS(SELECT 1 FROM blocks b WHERE b.user_id=? AND b.target=m.user_id)';$args=[$cid,uid()];
  if(array_key_exists('before',$in)){$cursor=$in['before'];if(!is_array($cursor)||count($cursor)!==2||!array_key_exists('at',$cursor)||!array_key_exists('id',$cursor)||!is_int($cursor['at'])||$cursor['at']<0||$cursor['at']>8640000000000000)fail(400,'Invalid pin cursor.');$id=positive_id($cursor['id']);$where.=' AND (m.pinned_at<? OR (m.pinned_at=? AND m.id<?))';array_push($args,$cursor['at'],$cursor['at'],$id);}
  $rows=sql("SELECT m.id,m.user_id,substr(m.body,1,300) AS body,m.body AS preview,m.created,m.pinned_at,u.name,u.username,COALESCE(mb.nickname,'') AS nickname,COALESCE(NULLIF(mb.server_avatar,''),u.avatar) AS avatar,(SELECT count(*) FROM attachments a WHERE a.message_id=m.id) AS attachments FROM messages m JOIN users u ON u.id=m.user_id LEFT JOIN members mb ON mb.user_id=m.user_id AND mb.server_id=? WHERE $where ORDER BY m.pinned_at DESC,m.id DESC LIMIT 51",array_merge([$c['server_id']],$args))->fetchAll();$more=count($rows)>50;$rows=array_slice($rows,0,50);$names=$c['server_id']?array_column(sql('SELECT id,name FROM roles WHERE server_id=?',[$c['server_id']])->fetchAll(),'name','id'):[];foreach($rows as &$row){$row['content']=preg_match('/\A.{0,1000}\z/us',$row['preview'])?$row['preview']:null;$row['role_mentions']=[];if($row['content']!==null)foreach(mention_roles($row['content']) as $role)$row['role_mentions'][]=['id'=>$role,'name'=>$names[$role]??'Deleted role'];preg_match('/^.{0,300}/us',role_preview($row['preview'],$names),$preview);$row['preview']=$preview[0];$row['files']=sql('SELECT id,name,size,description,spoiler FROM attachments WHERE message_id=? AND channel_id=? ORDER BY rowid LIMIT 3',[$row['id'],$cid])->fetchAll();$row['can_unpin']=$canUnpin&&($c['server_id']||!blocked((int)$row['user_id']));}unset($row);$last=end($rows);return ['pins'=>$rows,'next'=>$more?['at'=>(int)$last['pinned_at'],'id'=>(int)$last['id']]:null];
 case 'reaction_members':
  $id=positive_id($in['message_id']??null);$emoji=text_field($in,'emoji',1,50);if(strlen($emoji)>50)fail(400,'Invalid reaction.');$after=$in['after']??0;if(!is_int($after)||$after<0||$after>9007199254740991)fail(400,'Invalid cursor.');
  $m=one('SELECT channel_id,user_id FROM messages WHERE id=? AND deleted=0',[$id]);if(!$m)fail(404,'Message no longer available.');$c=channel($m['channel_id']);if(blocked((int)$m['user_id']))fail(403,'This message is unavailable.');
  $rows=sql("SELECT u.id,u.username,u.name,COALESCE(NULLIF(mb.server_avatar,''),u.avatar) AS avatar,COALESCE(mb.nickname,'') AS nickname FROM reactions r JOIN users u ON u.id=r.user_id LEFT JOIN members mb ON mb.user_id=u.id AND mb.server_id=? WHERE r.message_id=? AND r.emoji=? AND u.id>? AND NOT EXISTS(SELECT 1 FROM blocks b WHERE (b.user_id=? AND b.target=u.id) OR (b.target=? AND b.user_id=u.id)) ORDER BY u.id LIMIT 51",[$c['server_id'],$id,$emoji,$after,uid(),uid()])->fetchAll();
  $more=count($rows)>50;$rows=array_slice($rows,0,50);$last=end($rows);$people=[];foreach($rows as $row)if(can_view_as($c['id'],(int)$row['id'])){$row['id']=(int)$row['id'];$people[]=$row;}
  return ['members'=>$people,'next'=>$more?(int)$last['id']:null];
 case 'message_context':
  $id=positive_id($in['message_id']??null);$m=one('SELECT channel_id FROM messages WHERE id=? AND deleted=0',[$id]);if(!$m)fail(404,'Message no longer available.');$c=channel($m['channel_id']);$after=sql('SELECT id FROM messages WHERE channel_id=? AND deleted=0 AND id>=? ORDER BY id LIMIT 30',[$c['id'],$id])->fetchAll();$last=end($after);return ['channel'=>$c,'messages'=>messages_for($c['id'],(int)$last['id']+1)];
 }fail(404,'Unknown notification action.');
}
