<?php
declare(strict_types=1);
function migrate_thread_capacity_index(): void {
 global $db;if((int)$db->query('PRAGMA user_version')->fetchColumn()>=75)return;
 transaction(function()use($db){if((int)$db->query('PRAGMA user_version')->fetchColumn()>=75)return;$db->exec('CREATE INDEX channels_parent_created ON channels(parent_id,created DESC,id) WHERE parent_id IS NOT NULL; PRAGMA user_version=75;');});
}
function require_thread_capacity(string $parent): void {
 if((int)sql('SELECT count(*) FROM (SELECT 1 FROM channels WHERE parent_id=? LIMIT 20)',[$parent])->fetchColumn()>=20)fail(409,'This channel has reached its 20-thread limit.');
}

function migrate_forum_guidelines(): void {
 global $db;if((int)$db->query('PRAGMA user_version')->fetchColumn()>=74)return;
 transaction(function()use($db){if((int)$db->query('PRAGMA user_version')->fetchColumn()>=74)return;$db->exec("ALTER TABLE channels ADD COLUMN forum_guidelines TEXT NOT NULL DEFAULT '' CHECK(length(forum_guidelines)<=2000); ALTER TABLE channels ADD COLUMN forum_guidelines_revision INTEGER NOT NULL DEFAULT 0 CHECK(forum_guidelines_revision>=0); PRAGMA user_version=74;");});
}
function migrate_forum_layout(): void {
 global $db;if((int)$db->query('PRAGMA user_version')->fetchColumn()>=73)return;
 transaction(function()use($db){if((int)$db->query('PRAGMA user_version')->fetchColumn()>=73)return;$db->exec("ALTER TABLE channels ADD COLUMN forum_default_layout TEXT NOT NULL DEFAULT 'list' CHECK(forum_default_layout IN ('list','cards')); ALTER TABLE channels ADD COLUMN forum_layout_revision INTEGER NOT NULL DEFAULT 0; PRAGMA user_version=73;");});
}
function migrate_forum_sort(): void {
 global $db;if((int)$db->query('PRAGMA user_version')->fetchColumn()>=72)return;
 transaction(function()use($db){if((int)$db->query('PRAGMA user_version')->fetchColumn()>=72)return;$db->exec("ALTER TABLE channels ADD COLUMN forum_default_sort TEXT NOT NULL DEFAULT 'activity' CHECK(forum_default_sort IN ('activity','newest')); ALTER TABLE channels ADD COLUMN forum_sort_revision INTEGER NOT NULL DEFAULT 0; PRAGMA user_version=72;");});
}
function migrate_forum_pins(): void {
 global $db;if((int)$db->query('PRAGMA user_version')->fetchColumn()>=71)return;
 transaction(function()use($db){if((int)$db->query('PRAGMA user_version')->fetchColumn()>=71)return;$db->exec('CREATE TABLE forum_pins(channel_id TEXT PRIMARY KEY REFERENCES channels(id) ON DELETE CASCADE,post_id TEXT UNIQUE REFERENCES channels(id) ON DELETE SET NULL,revision INTEGER NOT NULL DEFAULT 0 CHECK(revision>=0)); CREATE TRIGGER forum_pinned_post_deleted BEFORE DELETE ON channels BEGIN UPDATE forum_pins SET post_id=NULL,revision=revision+1 WHERE post_id=OLD.id; END; PRAGMA user_version=71;');});
}
function forum_pin(string $parent): array {return one('SELECT post_id,revision FROM forum_pins WHERE channel_id=?',[$parent])?:['post_id'=>null,'revision'=>0];}
function can_pin_forum_post(array $c): bool {return (bool)($c['parent_id']&&$c['kind']==='text'&&empty($c['thread_private'])&&!$c['archived']&&member($c['server_id'])['muted_until']<=time()&&channel_capabilities($c)['manage_threads']);}
// Input must contain only channels whose current access has already been checked, including forum parents.
function forum_state_fields(array $channels,bool $includeSummaries=true): array {
 $parents=array_column(array_filter($channels,fn($c)=>!empty($c['forum'])&&!$c['parent_id']&&$c['kind']==='text'),null,'id');
 $posts=array_filter($channels,fn($c)=>$c['parent_id']&&isset($parents[$c['parent_id']])&&$parents[$c['parent_id']]['server_id']===$c['server_id']);
 $summaries=$includeSummaries?forum_post_summaries($channels):[];$pins=[];$tags=[];$result=[];
 foreach(array_chunk(array_keys($parents),500) as $ids){$placeholders=implode(',',array_fill(0,count($ids),'?'));foreach(sql('SELECT channel_id,post_id,revision FROM forum_pins WHERE channel_id IN ('.$placeholders.')',$ids)->fetchAll() as $pin)$pins[$pin['channel_id']]=$pin;}
 foreach(array_chunk(array_column($posts,'id'),500) as $ids){$placeholders=implode(',',array_fill(0,count($ids),'?'));foreach(sql('SELECT post_id,tag_id FROM forum_post_tags WHERE post_id IN ('.$placeholders.') ORDER BY post_id,tag_id',$ids)->fetchAll() as $tag)$tags[$tag['post_id']][]=$tag['tag_id'];}
 foreach($parents as $c){
  $manage=in_array('manage_channels',permissions_for($c['server_id']),true)&&member($c['server_id'])['muted_until']<=time();
  $result[$c['id']]=['forum_pin_revision'=>$pins[$c['id']]['revision']??0,'forum_tags'=>forum_tags($c['id'],$c['server_id']),'forum_tag_emoji'=>true,'forum_opening_attachment'=>true,'can_apply_moderated_forum_tags'=>channel_capabilities($c)['manage_threads']&&member($c['server_id'])['muted_until']<=time(),'can_manage_forum_tags'=>$manage,'can_manage_forum_sort'=>$manage,'can_manage_forum_layout'=>$manage,'can_manage_forum_guidelines'=>$manage];
 }
 foreach($posts as $c){
  $summary=$summaries[$c['id']]??[];
  $result[$c['id']]=['forum_pinned'=>empty($c['thread_private'])&&($pins[$c['parent_id']]['post_id']??null)===$c['id'],'can_pin_forum_post'=>can_pin_forum_post($c),'forum_preview'=>$summary['preview']??null,'forum_image_id'=>$summary['image_id']??null,'forum_reply_count'=>$summary['reply_count']??null,'forum_reply_count_limited'=>$summary['reply_count_limited']??false,'forum_tag_ids'=>$tags[$c['id']]??[],'can_edit_forum_tags'=>can_edit_forum_post_tags($c)];
  if(!$includeSummaries)foreach(['forum_preview','forum_image_id','forum_reply_count','forum_reply_count_limited'] as $key)unset($result[$c['id']][$key]);
 }
 return $result;
}
function forum_catalog(array $in): array {
 if(array_diff(array_keys($in),['action','channel_id','catalog_version','fields_version','query','status','sort','tags','tag_match','cursor'])||($in['catalog_version']??null)!==1)fail(400,'Unsupported forum catalog request.');
 $fields=($in['fields_version']??null)===1;if(array_key_exists('fields_version',$in)&&!$fields)fail(400,'Unsupported forum catalog fields version.');
 $in+=['query'=>'','status'=>'all','sort'=>'default','tag_match'=>'any','tags'=>[]];
 $cid=text_field($in,'channel_id',32,32);$query=message_casefold(text_field($in,'query',0,100));
 $status=$in['status'];$sort=$in['sort'];$match=$in['tag_match'];
 if(!in_array($status,['all','active','archived','joined'],true)||!in_array($sort,['default','activity','newest','oldest'],true)||!in_array($match,['any','all'],true))fail(400,'Choose supported forum filters.');
 $tags=$in['tags'];if(!is_array($tags)||!array_is_list($tags)||count($tags)>20)fail(400,'Choose up to twenty distinct tags.');$seen=[];foreach($tags as $id){if(!is_string($id)||!preg_match('/^[a-f0-9]{32}$/D',$id)||isset($seen[$id]))fail(400,'Choose distinct tag identifiers.');$seen[$id]=true;}sort($tags,SORT_STRING);
 $cursor=$in['cursor']??null;if(array_key_exists('cursor',$in)&&(!is_string($cursor)||!preg_match('/^10\.[a-f0-9]{64}$/D',$cursor)))fail(400,'Invalid forum cursor.');
 global $db,$tokenHash;$db->exec('BEGIN');
 try{
  $parent=channel($cid);if(empty($parent['forum'])||$parent['parent_id']||$parent['kind']!=='text')fail(400,'Choose a forum.');
  if($sort==='default')$sort=$parent['forum_default_sort']==='newest'?'newest':'activity';
  // shortcut: reject catalogs above the current cap; replace this bounded snapshot with indexed keysets before raising capacity.
  $candidates=sql('SELECT * FROM channels WHERE parent_id=? ORDER BY created DESC,id LIMIT 21',[$cid])->fetchAll();if(count($candidates)>20)fail(409,'This forum exceeds the current catalog limit.');
  $posts=[];foreach($candidates as $post)if($post['server_id']===$parent['server_id']&&$post['kind']==='text'&&empty($post['thread_private'])&&can_view_as($post['id'],uid()))$posts[]=$post;
  $available=count($posts);$ids=array_column($posts,'id');$assignments=[];$joined=[];
  if($ids){$placeholders=implode(',',array_fill(0,count($ids),'?'));foreach(sql('SELECT p.post_id,p.tag_id FROM forum_post_tags p JOIN forum_tags t ON t.id=p.tag_id AND t.channel_id=? WHERE p.post_id IN ('.$placeholders.')',array_merge([$cid],$ids))->fetchAll() as $row)$assignments[$row['post_id']][]=$row['tag_id'];$joined=array_column(sql('SELECT channel_id FROM thread_members WHERE user_id=? AND channel_id IN ('.$placeholders.')',array_merge([uid()],$ids))->fetchAll(),'channel_id');}
  $posts=array_values(array_filter($posts,function($post)use($query,$status,$tags,$match,$assignments,$joined){
   if(!str_contains(message_casefold($post['name']),$query))return false;
   if($status==='joined'&&!in_array($post['id'],$joined,true)||$status==='active'&&$post['archived']||$status==='archived'&&!$post['archived'])return false;
   $hits=count(array_intersect($tags,$assignments[$post['id']]??[]));return !$tags||($match==='all'?$hits===count($tags):$hits>0);
  }));
  $pinState=forum_pin($cid);$pin=$pinState['post_id'];usort($posts,function($a,$b)use($sort,$pin){
   $pinned=(int)($b['id']===$pin)-(int)($a['id']===$pin);if($pinned)return $pinned;
   if($sort==='activity')return ((int)(bool)$a['archived']<=>(int)(bool)$b['archived'])?:(((int)$b['thread_activity']?:(int)$b['created'])<=>((int)$a['thread_activity']?:(int)$a['created']))?:strcmp($a['id'],$b['id']);
   return (($sort==='newest'?-1:1)*((int)$a['created']<=>(int)$b['created']))?:strcmp($a['id'],$b['id']);
  });
  $ids=array_column($posts,'id');$signature=hash_hmac('sha256',json_encode([1,uid(),$parent['server_id'],$cid,$query,$status,$sort,$tags,$match,$ids],JSON_THROW_ON_ERROR),$tokenHash);
  if($cursor!==null&&(!hash_equals('10.'.$signature,$cursor)||count($ids)<=10))fail(409,'The forum results changed. Restart from the first page.');
  $offset=$cursor===null?0:10;$result=['post_ids'=>array_slice($ids,$offset,10),'next_cursor'=>$offset===0&&count($ids)>10?'10.'.$signature:null,'available'=>$available,'total'=>count($ids),'sort'=>$sort];
  if($fields)$result['posts']=array_map(fn($post)=>['id'=>$post['id'],'name'=>$post['name'],'archived'=>(bool)$post['archived'],'locked'=>(bool)$post['locked'],'thread_joined'=>in_array($post['id'],$joined,true),'forum_tag_ids'=>$assignments[$post['id']]??[]],array_slice($posts,$offset,10));
  if($fields){
   $creators=[];if($result['post_ids']){$placeholders=implode(',',array_fill(0,count($result['post_ids']),'?'));foreach(sql('SELECT c.id AS post_id,u.id,u.username,u.name,m.nickname FROM channels c JOIN members m ON m.server_id=c.server_id AND m.user_id=c.thread_creator_id JOIN users u ON u.id=m.user_id WHERE c.id IN ('.$placeholders.')',$result['post_ids'])->fetchAll() as $creator)$creators[$creator['post_id']]=array_intersect_key($creator,array_flip(['id','username','name','nickname']));}
   $result['creators']=array_map(fn($id)=>['post_id'=>$id,'creator'=>$creators[$id]??null],$result['post_ids']);
  }
  if($fields)$result['tags']=forum_tags($cid,$parent['server_id']);
  if($fields)$result['pin']=['post_id'=>in_array($pin,$result['post_ids'],true)?$pin:null,'revision'=>(int)$pinState['revision']];
  return $result;
 }finally{$db->exec('ROLLBACK');}
}
function forum_post_details(array $in): array {
 if(array_diff(array_keys($in),['action','channel_id','post_ids']))fail(400,'Unsupported post preview field.');
 $parent=channel(text_field($in,'channel_id',32,32));if(empty($parent['forum'])||$parent['parent_id']||$parent['kind']!=='text')fail(400,'Choose a forum.');
 $ids=$in['post_ids']??null;if(!is_array($ids)||!array_is_list($ids)||count($ids)<1||count($ids)>10)fail(400,'Choose one to ten posts.');$channels=[$parent];$seen=[];
 foreach($ids as $id){if(!is_string($id)||!preg_match('/^[a-f0-9]{32}$/',$id)||isset($seen[$id]))fail(400,'Choose distinct post identifiers.');$seen[$id]=true;$post=channel($id);if($post['parent_id']!==$parent['id']||$post['server_id']!==$parent['server_id']||$post['kind']!=='text'||!empty($post['thread_private']))fail(403,'This post is not available in this forum.');$channels[]=$post;}
 $fields=forum_state_fields($channels);$posts=[];foreach($ids as $id)$posts[]=['id'=>$id]+array_intersect_key($fields[$id],array_flip(['forum_preview','forum_image_id','forum_reply_count','forum_reply_count_limited']));return ['posts'=>$posts];
}
// Call only after checking current access to each post and its forum parent.
function forum_images(array $ids): array {
 $extensions=attachment_type_extensions('image');$images=implode(' OR ',array_fill(0,count($extensions),'lower(a.name) LIKE ?'));$result=[];
 foreach(array_chunk($ids,500) as $batch){$placeholders=implode(',',array_fill(0,count($batch),'?'));
  $rows=sql("SELECT c.id,(SELECT a.id FROM attachments a WHERE a.channel_id=c.id AND a.message_id=m.id AND a.spoiler=0 AND a.size BETWEEN 1 AND 4194304 AND ($images) ORDER BY a.created,a.id LIMIT 1) AS image_id FROM channels c JOIN channels p ON p.id=c.parent_id AND p.server_id=c.server_id JOIN messages m ON m.channel_id=c.id AND m.user_id=c.thread_creator_id AND m.client_id='thread-start:'||c.id WHERE c.id IN ($placeholders) AND c.kind='text' AND c.thread_private=0 AND c.root_message IS NULL AND c.thread_client_id IS NOT NULL AND p.forum=1 AND p.parent_id IS NULL AND p.kind='text' AND m.deleted=0 AND m.system_type='' AND instr(m.body,'||')=0 AND NOT EXISTS(SELECT 1 FROM blocks b WHERE (b.user_id=? AND b.target=m.user_id) OR (b.target=? AND b.user_id=m.user_id))",array_merge(array_map(fn($ext)=>'%.'.$ext,$extensions),$batch,[uid(),uid()]))->fetchAll();foreach($rows as $row)if($row['image_id']!==null)$result[$row['id']]=$row['image_id'];
 }return $result;
}
function forum_post_summaries(array $channels): array {
 $parents=array_column(array_filter($channels,fn($c)=>!empty($c['forum'])&&!$c['parent_id']&&$c['kind']==='text'),'server_id','id');
 $ids=array_column(array_filter($channels,fn($c)=>$c['parent_id']&&empty($c['thread_private'])&&$c['kind']==='text'&&isset($parents[$c['parent_id']])&&$parents[$c['parent_id']]===$c['server_id']),'id');$result=[];$roles=[];
 // Use the original standalone starter identity, never a later reply when it is unavailable.
 foreach(array_chunk($ids,500) as $batch){$placeholders=implode(',',array_fill(0,count($batch),'?'));
  // shortcut: stop counting at 1001 visible replies; revisit with catalog pagination and capacity measurements.
  foreach(sql("SELECT c.id,(SELECT count(*) FROM (SELECT m.id FROM messages m WHERE m.channel_id=c.id AND m.deleted=0 AND m.system_type='' AND NOT(m.user_id IS c.thread_creator_id AND m.client_id='thread-start:'||c.id) AND NOT EXISTS(SELECT 1 FROM blocks b WHERE (b.user_id=? AND b.target=m.user_id) OR (b.target=? AND b.user_id=m.user_id)) LIMIT 1001)) AS replies FROM channels c WHERE c.id IN ($placeholders)",array_merge([uid(),uid()],$batch))->fetchAll() as $row)$result[$row['id']]=['preview'=>null,'reply_count'=>min(1000,(int)$row['replies']),'reply_count_limited'=>(int)$row['replies']>1000];
  $rows=sql("SELECT c.id,c.server_id,m.body FROM channels c JOIN messages m ON m.channel_id=c.id AND m.user_id=c.thread_creator_id AND m.client_id='thread-start:'||c.id WHERE c.id IN ($placeholders) AND m.deleted=0 AND m.system_type='' AND NOT EXISTS(SELECT 1 FROM blocks b WHERE (b.user_id=? AND b.target=m.user_id) OR (b.target=? AND b.user_id=m.user_id))",array_merge($batch,[uid(),uid()]))->fetchAll();
  foreach($rows as $row){$body=$row['body'];if(str_contains($body,'||')){$result[$row['id']]['preview']='Contains a spoiler. Open the post to read.';continue;}
   $sid=$row['server_id'];if(!isset($roles[$sid]))$roles[$sid]=array_column(sql('SELECT id,name FROM roles WHERE server_id=?',[$sid])->fetchAll(),'name','id');$body=trim(preg_replace('/\s+/u',' ',role_preview($body,$roles[$sid])));if($body==='')continue;
   preg_match('/^.{0,240}/us',$body,$match);$result[$row['id']]['preview']=$match[0].(strlen($match[0])<strlen($body)?'…':'');
  }
 }foreach(forum_images($ids) as $id=>$image)$result[$id]['image_id']=$image;return $result;
}
function migrate_forum_tag_emoji(): void {
 global $db;if((int)$db->query('PRAGMA user_version')->fetchColumn()>=70)return;
 transaction(function()use($db){if((int)$db->query('PRAGMA user_version')->fetchColumn()>=70)return;$db->exec("ALTER TABLE forum_tags ADD COLUMN emoji TEXT; PRAGMA user_version=70;");});
}
function validated_forum_tag_emoji(array $tag,?array $old,string $server): ?array {
 $previous=json_decode($old['emoji']??'null',true);if(!array_key_exists('emoji',$tag))return $previous;$value=$tag['emoji'];if($value===null||$value==='')return null;
 if(!is_string($value)||strlen($value)>50)fail(400,'Choose a supported emoji.');if(in_array($value,unicode_emoji_choices(),true))return ['value'=>$value];
 if(!preg_match('/^custom:([a-f0-9]{32})$/D',$value,$match))fail(400,'Choose a supported emoji.');
 // Preserve a removed emoji by identity on unrelated edits, never by its reusable name.
 if(($previous['value']??null)===$value)return $previous;
 $media=one("SELECT slot FROM media WHERE id=? AND server_id=? AND slot LIKE 'emoji:%'",[$match[1],$server]);if(!$media)fail(400,'This server emoji is no longer available.');return ['value'=>$value,'name'=>substr($media['slot'],6)];
}
function migrate_moderated_forum_tags(): void {
 global $db;if((int)$db->query('PRAGMA user_version')->fetchColumn()>=69)return;
 transaction(function()use($db){if((int)$db->query('PRAGMA user_version')->fetchColumn()>=69)return;$db->exec('ALTER TABLE forum_tags ADD COLUMN moderated INTEGER NOT NULL DEFAULT 0 CHECK(moderated IN (0,1)); PRAGMA user_version=69;');});
}
function migrate_required_forum_tags(): void {
 global $db;if((int)$db->query('PRAGMA user_version')->fetchColumn()>=68)return;
 transaction(function()use($db){if((int)$db->query('PRAGMA user_version')->fetchColumn()>=68)return;$db->exec('ALTER TABLE channels ADD COLUMN forum_require_tag INTEGER NOT NULL DEFAULT 0 CHECK(forum_require_tag IN (0,1)); PRAGMA user_version=68;');});
}
function check_required_forum_tags(array $parent,array $ids): void {if(!empty($parent['forum_require_tag'])&&!$ids)fail(400,'Choose at least one forum tag. If your app has no tag picker, update Gather first.');}
function migrate_forum_tags(): void {
 global $db;if((int)$db->query('PRAGMA user_version')->fetchColumn()>=67)return;
 transaction(function()use($db){if((int)$db->query('PRAGMA user_version')->fetchColumn()>=67)return;$db->exec('ALTER TABLE channels ADD COLUMN forum_tag_revision INTEGER NOT NULL DEFAULT 0;
 CREATE TABLE forum_tags(id TEXT PRIMARY KEY,channel_id TEXT NOT NULL REFERENCES channels(id) ON DELETE CASCADE,name TEXT NOT NULL,position INTEGER NOT NULL);
 CREATE INDEX forum_tags_channel ON forum_tags(channel_id,position);
 CREATE TABLE forum_post_tags(post_id TEXT NOT NULL REFERENCES channels(id) ON DELETE CASCADE,tag_id TEXT NOT NULL REFERENCES forum_tags(id) ON DELETE CASCADE,PRIMARY KEY(post_id,tag_id));
 CREATE INDEX forum_post_tags_tag ON forum_post_tags(tag_id); PRAGMA user_version=67;');});
}
function forum_tags(string $cid,?string $server=null): array {
 $tags=sql('SELECT id,name,moderated,emoji FROM forum_tags WHERE channel_id=? ORDER BY position,id',[$cid])->fetchAll();$available=$server?array_column(sql("SELECT id FROM media WHERE server_id=? AND slot LIKE 'emoji:%'",[$server])->fetchAll(),'id'):[];
 foreach($tags as &$tag){$tag['moderated']=(bool)$tag['moderated'];$emoji=json_decode($tag['emoji']??'null',true);unset($tag['emoji']);if($emoji){if($server&&str_starts_with($emoji['value'],'custom:'))$emoji['available']=in_array(substr($emoji['value'],7),$available,true);$tag['emoji']=$emoji;}}return $tags;
}
function forum_post_tag_ids(string $cid): array {return array_column(sql('SELECT tag_id FROM forum_post_tags WHERE post_id=? ORDER BY tag_id',[$cid])->fetchAll(),'tag_id');}
function forum_tag_revision(array $c,array $in,string $key='expected_revision'): void {
 if(!is_int($in[$key]??null)||$in[$key]<0)fail(400,'Invalid tag revision.');if($in[$key]!==$c['forum_tag_revision'])fail(409,'Tags changed. Refresh and reopen this form.');
}
function validated_forum_post_tags(array $parent,array $in,array $previous=[]): array {
 if(empty($parent['forum']))fail(400,'Choose a forum.');forum_tag_revision($parent,$in,'tag_revision');$ids=$in['tags']??null;
 if(!is_array($ids)||!array_is_list($ids)||count($ids)>5)fail(400,'Choose up to five forum tags.');$definitions=forum_tags($parent['id']);$allowed=array_column($definitions,'id');$seen=[];
 foreach($ids as $id){if(!is_string($id)||!in_array($id,$allowed,true)||in_array($id,$seen,true))fail(400,'Choose distinct tags from this forum.');$seen[]=$id;}if(!channel_capabilities($parent)['manage_threads'])foreach($definitions as $tag)if($tag['moderated']&&in_array($tag['id'],$seen,true)!==in_array($tag['id'],$previous,true))fail(403,'Only thread managers can add or remove manager-only tags.');check_required_forum_tags($parent,$seen);sort($seen);return $seen;
}
function can_edit_forum_post_tags(array $c): bool {return (bool)($c['parent_id']&&!$c['archived']&&empty($c['thread_private'])&&member($c['server_id'])['muted_until']<=time()&&(channel_capabilities($c)['manage_threads']||thread_creator_controls($c)));}
function migrate_forum_channels(): void {
 global $db;if((int)$db->query('PRAGMA user_version')->fetchColumn()>=66)return;
 transaction(function()use($db){if((int)$db->query('PRAGMA user_version')->fetchColumn()>=66)return;$db->exec('ALTER TABLE channels ADD COLUMN forum INTEGER NOT NULL DEFAULT 0 CHECK(forum IN (0,1)); PRAGMA user_version=66;');});
}
function migrate_thread_starters(): void {
 global $db;if((int)$db->query('PRAGMA user_version')->fetchColumn()>=65)return;
 transaction(function()use($db){if((int)$db->query('PRAGMA user_version')->fetchColumn()>=65)return;$db->exec('ALTER TABLE channels ADD COLUMN thread_client_id TEXT; CREATE UNIQUE INDEX thread_creation_retry ON channels(thread_creator_id,thread_client_id) WHERE thread_client_id IS NOT NULL; PRAGMA user_version=65;');});
}
function migrate_attachment_descriptions(): void {
 global $db;if((int)$db->query('PRAGMA user_version')->fetchColumn()>=53)return;
 transaction(function()use($db){if((int)$db->query('PRAGMA user_version')->fetchColumn()>=53)return;$db->exec("ALTER TABLE attachments ADD COLUMN description TEXT NOT NULL DEFAULT ''; PRAGMA user_version=53;");});
}
function migrate_attachment_spoilers(): void {
 global $db;if((int)$db->query('PRAGMA user_version')->fetchColumn()>=54)return;
 transaction(function()use($db){if((int)$db->query('PRAGMA user_version')->fetchColumn()>=54)return;$db->exec("ALTER TABLE attachments ADD COLUMN spoiler INTEGER NOT NULL DEFAULT 0; PRAGMA user_version=54;");});
}
function attachment_payload(array $in): array {
 $name=attachment_name($in);$encoded=$in['data']??null;if(!is_string($encoded)||strlen($encoded)>5592408)fail(413,'Files must be at most 4 MB.');$bytes=base64_decode($encoded,true);if($bytes===false||base64_encode($bytes)!==$encoded||strlen($bytes)<1||strlen($bytes)>4194304)fail(400,'Invalid file or size.');return [$name,$bytes,$encoded];
}
function store_attachment(string $cid,array $in,string $name,string $bytes,string $encoded): array {
 send_channel($cid,$in,'attach');send_channel($cid,$in);sql('DELETE FROM attachments WHERE message_id IS NULL AND created<?',[time()-86400]);$size=strlen($bytes);if((int)sql('SELECT COALESCE(sum(size),0) FROM attachments WHERE user_id=?',[uid()])->fetchColumn()+$size>104857600||(int)sql('SELECT COALESCE(sum(size),0) FROM attachments')->fetchColumn()+$size>536870912)fail(409,'Attachment storage is full. Delete old attachments to free space.');$id=random_id();$hash=hash('sha256',$bytes);sql('INSERT INTO attachments(id,channel_id,user_id,message_id,name,size,sha256,data,created) VALUES(?,?,?,?,?,?,?,?,?)',[$id,$cid,uid(),null,$name,$size,$hash,$encoded,time()]);return ['id'=>$id,'name'=>$name,'size'=>$size,'sha256'=>$hash];
}
function attachment_name(array $in): string {
 $name=text_field($in,'name',1,120);
 if(preg_match('/[\\\\\/:*?"<>|\x00-\x1F]/',$name)||str_ends_with($name,'.')||str_ends_with($name,' ')||!preg_match('/\.(png|jpe?g|webp|gif|pdf|txt|csv|md|json|zip|docx|xlsx|pptx|mp3|wav|ogg|mp4|webm)$/i',$name))fail(400,'Unsupported file name or type. Use images, documents, ZIP, audio or video.');
 return $name;
}
function migrate_thread_member_revision(): void {
 global $db;if((int)$db->query('PRAGMA user_version')->fetchColumn()>=43)return;
 transaction(function()use($db){if((int)$db->query('PRAGMA user_version')->fetchColumn()>=43)return;$db->exec('ALTER TABLE channels ADD COLUMN thread_membership_revision INTEGER NOT NULL DEFAULT 0; CREATE TRIGGER thread_member_added AFTER INSERT ON thread_members BEGIN UPDATE channels SET thread_membership_revision=thread_membership_revision+1 WHERE id=NEW.channel_id AND thread_private=1; END; CREATE TRIGGER thread_member_removed AFTER DELETE ON thread_members BEGIN UPDATE channels SET thread_membership_revision=thread_membership_revision+1 WHERE id=OLD.channel_id AND thread_private=1; END; PRAGMA user_version=43;');});
}
function private_thread_manager(string $id): array {
 $c=channel($id);if(!$c['parent_id']||empty($c['thread_private']))fail(400,'Choose a private thread.');if(!channel_capabilities($c)['manage_threads'])fail(403,'Manage Threads is required.');if($c['archived'])fail(403,'Reopen this thread before changing members.');if(member($c['server_id'])['muted_until']>time())fail(403,'You cannot change members while timed out.');return $c;
}
function migrate_private_threads(): void {
 global $db;if((int)$db->query('PRAGMA user_version')->fetchColumn()>=42)return;
 transaction(function()use($db){if((int)$db->query('PRAGMA user_version')->fetchColumn()>=42)return;$db->exec('ALTER TABLE channels ADD COLUMN thread_private INTEGER NOT NULL DEFAULT 0; PRAGMA user_version=42;');});
}
function migrate_thread_membership(): void {
 global $db;if((int)$db->query('PRAGMA user_version')->fetchColumn()>=41)return;
 transaction(function()use($db){if((int)$db->query('PRAGMA user_version')->fetchColumn()>=41)return;$db->exec('CREATE TABLE thread_members(channel_id TEXT NOT NULL REFERENCES channels(id) ON DELETE CASCADE,server_id TEXT NOT NULL,user_id INTEGER NOT NULL,joined INTEGER NOT NULL,PRIMARY KEY(channel_id,user_id),FOREIGN KEY(server_id,user_id) REFERENCES members(server_id,user_id) ON DELETE CASCADE); CREATE INDEX thread_members_server_user ON thread_members(server_id,user_id); PRAGMA user_version=41;');});
}
function join_thread(array $c): void {sql('INSERT OR IGNORE INTO thread_members(channel_id,server_id,user_id,joined) VALUES(?,?,?,?)',[$c['id'],$c['server_id'],uid(),time()]);}
function migrate_thread_creator(): void {
 global $db;if((int)$db->query('PRAGMA user_version')->fetchColumn()>=40)return;
 transaction(function()use($db){if((int)$db->query('PRAGMA user_version')->fetchColumn()>=40)return;$db->exec('ALTER TABLE channels ADD COLUMN thread_creator_id INTEGER REFERENCES users(id) ON DELETE SET NULL; PRAGMA user_version=40;');});
}
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
function thread_creator_controls(array $c): bool {
 return (bool)($c['parent_id']&&$c['thread_creator_id']===uid()&&!$c['locked']&&member($c['server_id'])['muted_until']<=time());
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
function channel_capabilities(array $c,bool $forThread=false): array {
 if($c['parent_id']){$p=channel_capabilities(one('SELECT * FROM channels WHERE id=?',[$c['parent_id']]),true);$p['send']=$p['send_in_threads'];if(!empty($c['thread_private'])&&!$p['manage_threads']&&!one('SELECT 1 FROM thread_members WHERE channel_id=? AND user_id=?',[$c['id'],uid()]))$p['view']=false;return $p;}
 if(!$c['server_id'])return ['view'=>true,'send'=>true,'connect'=>true,'attach'=>true,'pin_messages'=>true,'bypass_slowmode'=>true,'manage_threads'=>false,'create_private_threads'=>false,'create_public_threads'=>false,'send_in_threads'=>true,'add_reactions'=>true,'create_polls'=>true];
 $m=member($c['server_id']);if($m['role']==='owner')return ['view'=>true,'send'=>empty($c['forum'])||$forThread,'connect'=>empty($c['forum'])||$forThread,'attach'=>true,'pin_messages'=>true,'bypass_slowmode'=>true,'manage_threads'=>true,'create_private_threads'=>empty($c['forum']),'create_public_threads'=>true,'send_in_threads'=>true,'add_reactions'=>true,'create_polls'=>true];
 $roles=array_column(sql('SELECT role_id FROM member_roles WHERE server_id=? AND user_id=?',[$c['server_id'],uid()])->fetchAll(),'role_id');
 $visible=!$c['private']||one('SELECT 1 FROM channel_members WHERE channel_id=? AND user_id=?',[$c['id'],uid()])||one('SELECT 1 FROM channel_roles cr JOIN member_roles mr ON mr.role_id=cr.role_id WHERE cr.channel_id=? AND mr.user_id=?',[$c['id'],uid()]);
 $permissions=permissions_for($c['server_id']);$defaults=thread_defaults($c['server_id']);
 $p=['create_polls'=>in_array('create_polls',$permissions,true)?true:null,'add_reactions'=>in_array('add_reactions',$permissions,true)?true:null,'view'=>(bool)$visible,'send'=>!$c['readonly']||in_array('manage_messages',permissions_for($c['server_id']),true),'connect'=>true,'attach'=>true,'pin_messages'=>in_array('pin_messages',permissions_for($c['server_id']),true),'bypass_slowmode'=>in_array('bypass_slowmode',permissions_for($c['server_id']),true),'manage_threads'=>in_array('manage_threads',permissions_for($c['server_id']),true),'create_private_threads'=>in_array('create_private_threads',$permissions,true),'create_public_threads'=>in_array('create_public_threads',$permissions,true),'send_in_threads'=>in_array('send_in_threads',$permissions,true)?true:($defaults['thread_send_default']===-1?false:null)];
 $rows=sql('SELECT * FROM channel_overrides WHERE channel_id=?',[$c['id']])->fetchAll();
 // Discord-style precedence: everyone, combined roles (allow wins), then member.
 foreach(['everyone','role','member'] as $kind){$values=[];foreach($rows as $r){if($r['kind']!==$kind||($kind==='role'&&!in_array($r['subject'],$roles,true))||($kind==='member'&&$r['subject']!==(string)uid()))continue;$key=$r['permission'];$values[$key]=max($values[$key]??-1,(int)$r['value']);}foreach($values as $key=>$v)$p[$key]=$v===1;}
 $p['send_in_threads']??=$p['send'];
 // Preserve legacy creation restrictions unless a role or override explicitly sets Add Reactions.
 $p['create_polls']??=$forThread?$p['send_in_threads']:$p['send'];$p['add_reactions']??=$forThread?$p['send_in_threads']:$p['send'];if(!empty($c['forum'])){$p['create_private_threads']=false;if(!$forThread){$p['send']=false;$p['connect']=false;}}return $p;
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
 $version=$in['override_version']??1;if(!in_array($version,[1,2,3,4,5,6,7,8,9],true))fail(400,'Invalid override version.');
 foreach($sets as $rows)foreach($rows as $row){if($version<9&&$row['permission']==='create_polls')fail(409,'Update Gather before changing Create Polls overrides.');if($version<8&&$row['permission']==='add_reactions')fail(409,'Update Gather before changing Add Reactions overrides.');if($version<7&&$row['permission']==='create_private_threads')fail(409,'Update Gather before changing private thread creation overrides.');if($version<2&&$row['permission']==='pin_messages')fail(409,'Update Gather before changing overrides that include Pin Messages.');if($version<3&&$row['permission']==='bypass_slowmode')fail(409,'Update Gather before changing overrides that include Bypass Slowmode.');if($version<4&&$row['permission']==='manage_threads')fail(409,'Update Gather before changing overrides that include Manage Threads.');if($version<5&&$row['permission']==='create_public_threads')fail(409,'Update Gather before changing thread creation overrides.');if($version<6&&$row['permission']==='send_in_threads')fail(409,'Update Gather before changing thread sending overrides.');}
}
function override_input(string $sid,array $in): array {
 check_override_version($in);$keys=['view','send','connect','attach'];if(($in['override_version']??1)>=2)$keys[]='pin_messages';if(($in['override_version']??1)>=3)$keys[]='bypass_slowmode';if(($in['override_version']??1)>=4)$keys[]='manage_threads';if(($in['override_version']??1)>=5)$keys[]='create_public_threads';if(($in['override_version']??1)>=6)$keys[]='send_in_threads';if(($in['override_version']??1)>=7)$keys[]='create_private_threads';if(($in['override_version']??1)>=8)$keys[]='add_reactions';if(($in['override_version']??1)>=9)$keys[]='create_polls';
 $kind=$in['kind']??'';$subject=$in['subject']??'';$values=$in['values']??null;
 if(!is_string($subject)||!is_array($values)||count($values)!==count($keys))fail(400,'Invalid override.');
 if($kind==='everyone'){if($subject!=='*')fail(400,'Invalid everyone override.');}
 elseif($kind==='role'){if(!one('SELECT 1 FROM roles WHERE id=? AND server_id=?',[$subject,$sid]))fail(400,'Choose a server role.');}
 elseif($kind==='member'){if(!ctype_digit($subject)||!one('SELECT 1 FROM members WHERE user_id=? AND server_id=?',[$subject,$sid]))fail(400,'Choose a server member.');}
 else fail(400,'Invalid subject.');
 foreach($keys as $p)if(!isset($values[$p])||!in_array($values[$p],[-1,0,1],true))fail(400,'Invalid permission.');
 return [$kind,$subject,$values+['pin_messages'=>0,'bypass_slowmode'=>0,'manage_threads'=>0,'create_public_threads'=>0,'send_in_threads'=>0,'create_private_threads'=>0,'add_reactions'=>0,'create_polls'=>0]];
}
function save_channel_override(array $c,string $kind,string $subject,array $values): void {
 $before=['view'=>0,'send'=>0,'connect'=>0,'attach'=>0,'pin_messages'=>0,'bypass_slowmode'=>0,'manage_threads'=>0,'create_public_threads'=>0,'send_in_threads'=>0,'create_private_threads'=>0,'add_reactions'=>0,'create_polls'=>0];
 foreach(sql('SELECT permission,value FROM channel_overrides WHERE channel_id=? AND kind=? AND subject=?',[$c['id'],$kind,$subject])->fetchAll() as $row)$before[$row['permission']]=(int)$row['value'];
 foreach(['pin_messages','bypass_slowmode','manage_threads','create_public_threads','send_in_threads','create_private_threads','add_reactions','create_polls'] as $permission)if($values[$permission]>$before[$permission]&&!channel_capabilities($c)[$permission])fail(403,'You cannot broaden a permission you do not have.');
 sql('DELETE FROM channel_overrides WHERE channel_id=? AND kind=? AND subject=?',[$c['id'],$kind,$subject]);
 foreach($values as $p=>$v)if($v!==0)sql('INSERT INTO channel_overrides VALUES(?,?,?,?,?)',[$c['id'],$kind,$subject,$p,$v]);
 if(audit_changes($c['server_id'],'channel-access',channel_audit_label($c).' · '.$kind.' ['.$subject.'] · -1 deny / 0 inherit / 1 allow',$before,$values,['view'=>'View','send'=>'Send','connect'=>'Connect','attach'=>'Attach','pin_messages'=>'Pin messages','bypass_slowmode'=>'Bypass slowmode','manage_threads'=>'Manage threads','create_public_threads'=>'Create public threads','send_in_threads'=>'Send messages in threads','create_private_threads'=>'Create private threads','add_reactions'=>'Add reactions','create_polls'=>'Create polls']))sql('DELETE FROM calls WHERE channel_id=?',[$c['id']]);
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
 $subjects=[];foreach($preview['current'] as $row)$subjects[$row['kind'].':'.$row['subject']]=['view'=>0,'send'=>0,'connect'=>0,'attach'=>0,'pin_messages'=>0,'bypass_slowmode'=>0,'manage_threads'=>0,'create_public_threads'=>0,'send_in_threads'=>0,'create_private_threads'=>0,'add_reactions'=>0,'create_polls'=>0];
 foreach($preview['target'] as $row){$key=$row['kind'].':'.$row['subject'];$subjects[$key]??=['view'=>0,'send'=>0,'connect'=>0,'attach'=>0,'pin_messages'=>0,'bypass_slowmode'=>0,'manage_threads'=>0,'create_public_threads'=>0,'send_in_threads'=>0,'create_private_threads'=>0,'add_reactions'=>0,'create_polls'=>0];$subjects[$key][$row['permission']]=(int)$row['value'];}
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
    $before=['view'=>0,'send'=>0,'connect'=>0,'attach'=>0,'pin_messages'=>0,'bypass_slowmode'=>0,'manage_threads'=>0,'create_public_threads'=>0,'send_in_threads'=>0,'create_private_threads'=>0,'add_reactions'=>0,'create_polls'=>0];foreach($current['overrides'] as $row)if($row['kind']===$kind&&$row['subject']===$subject)$before[$row['permission']]=(int)$row['value'];
    foreach(['pin_messages','bypass_slowmode','manage_threads','create_public_threads','send_in_threads','create_private_threads','add_reactions','create_polls'] as $permission)if($values[$permission]>$before[$permission]&&!in_array($permission,permissions_for($sid),true))fail(403,'You need the server permission to broaden it in a category template.');
    sql('DELETE FROM category_overrides WHERE category_id=? AND kind=? AND subject=?',[$id,$kind,$subject]);foreach($values as $p=>$v)if($v!==0)sql('INSERT INTO category_overrides VALUES(?,?,?,?,?)',[$id,$kind,$subject,$p,$v]);
    foreach($affected as $c)save_channel_override($c,$kind,$subject,$values);
    audit_changes($sid,'category-permissions','Category '.$name.' ['.$id.'] · '.$kind.' ['.$subject.'] · -1 deny / 0 inherit / 1 allow',$before,$values,['view'=>'View','send'=>'Send','connect'=>'Connect','attach'=>'Attach','pin_messages'=>'Pin messages','bypass_slowmode'=>'Bypass slowmode','manage_threads'=>'Manage threads','create_public_threads'=>'Create public threads','send_in_threads'=>'Send messages in threads','create_private_threads'=>'Create private threads','add_reactions'=>'Add reactions','create_polls'=>'Create polls']);
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
 case 'thread_member_profile':
  $c=channel(text_field($in,'channel_id',32,32));if(!$c['parent_id']||!$c['server_id'])fail(400,'Choose a server thread.');$target=positive_id($in['user_id']??null);
  $m=one('SELECT u.*,m.nickname,m.role,m.server_avatar,m.server_banner,m.server_bio,m.server_pronouns,m.server_accent_color FROM thread_members t JOIN members m ON m.server_id=t.server_id AND m.user_id=t.user_id JOIN users u ON u.id=t.user_id WHERE t.channel_id=? AND t.server_id=? AND t.user_id=?',[$c['id'],$c['server_id'],$target]);
  if(!$m||blocked($target)||!can_view_as($c['id'],$target))fail(404,'This thread member is no longer available.');
  foreach(['avatar','banner','bio','pronouns','accent_color'] as $key)if($m['server_'.$key]!=='')$m[$key]=$m['server_'.$key];return ['member'=>public_user($m)+['nickname'=>$m['nickname'],'role'=>$m['role']]];
 case 'thread_members':
  $c=channel(text_field($in,'channel_id',32,32));if(!$c['parent_id']||!$c['server_id'])fail(400,'Choose a server thread.');$after=array_key_exists('after',$in)?$in['after']:0;if(!is_int($after)||$after<0||$after>9007199254740991)fail(400,'Invalid cursor.');
  $rows=sql("SELECT u.id,u.username,u.name,COALESCE(NULLIF(m.server_avatar,''),u.avatar) AS avatar,m.nickname,t.joined FROM thread_members t JOIN members m ON m.server_id=t.server_id AND m.user_id=t.user_id JOIN users u ON u.id=t.user_id WHERE t.channel_id=? AND t.server_id=? AND u.id>? AND NOT EXISTS(SELECT 1 FROM blocks b WHERE (b.user_id=? AND b.target=u.id) OR (b.target=? AND b.user_id=u.id)) ORDER BY u.id LIMIT 51",[$c['id'],$c['server_id'],$after,uid(),uid()])->fetchAll();
  $more=count($rows)>50;$rows=array_slice($rows,0,50);$last=end($rows);$people=[];foreach($rows as $row)if(can_view_as($c['id'],(int)$row['id'])){$row['id']=(int)$row['id'];$row['joined']=(int)$row['joined'];$people[]=$row;}return ['members'=>$people,'next'=>$more?(int)$last['id']:null];
 case 'thread_membership':
  $cid=text_field($in,'channel_id',32,32);if(!is_bool($in['joined']??null))fail(400,'Choose whether to join this thread.');
  transaction(function()use($cid,$in){$c=channel($cid);if(!$c['parent_id']||!$c['server_id'])fail(400,'Choose a server thread.');if($c['archived'])fail(403,'Reopen this thread before joining or leaving.');if(member($c['server_id'])['muted_until']>time())fail(403,'You cannot change thread membership while timed out.');if($in['joined'])join_thread($c);else sql('DELETE FROM thread_members WHERE channel_id=? AND user_id=?',[$cid,uid()]);});return ['ok'=>true];
 case 'private_thread_members':
  $cid=text_field($in,'channel_id',32,32);return transaction(function()use($cid){$c=private_thread_manager($cid);$rows=sql('SELECT u.id,u.username,u.name,m.nickname,EXISTS(SELECT 1 FROM thread_members t WHERE t.channel_id=? AND t.user_id=u.id) AS joined FROM members m JOIN users u ON u.id=m.user_id WHERE m.server_id=? ORDER BY u.name,u.id',[$cid,$c['server_id']])->fetchAll();foreach($rows as &$row){$row['id']=(int)$row['id'];$row['joined']=(bool)$row['joined'];$row['eligible']=!blocked($row['id'])&&can_view_as($c['parent_id'],$row['id']);}unset($row);return ['members'=>$rows,'revision'=>(int)$c['thread_membership_revision']];});
 case 'private_thread_members_save':
  $cid=text_field($in,'channel_id',32,32);$ids=$in['member_ids']??null;$revision=$in['expected_revision']??null;if(!is_int($revision)||$revision<0||$revision>9007199254740991)fail(400,'Invalid membership revision.');if(!is_array($ids)||count($ids)>100||count(array_unique($ids,SORT_REGULAR))!==count($ids))fail(400,'Choose different server members.');foreach($ids as $who)positive_id($who);
  return transaction(function()use($cid,$ids,$revision){$c=private_thread_manager($cid);if($revision!==(int)$c['thread_membership_revision'])fail(409,'Thread members changed elsewhere. Reload members before saving.');$old=array_map('intval',array_column(sql('SELECT user_id FROM thread_members WHERE channel_id=?',[$cid])->fetchAll(),'user_id'));$added=array_diff($ids,$old);$removed=array_diff($old,$ids);foreach($added as $who)if(blocked($who)||!can_view_as($c['parent_id'],$who))fail(403,'A selected member cannot access this channel.');if(!$added&&!$removed)return ['ok'=>true,'revision'=>$revision];foreach($removed as $who)sql('DELETE FROM thread_members WHERE channel_id=? AND user_id=?',[$cid,$who]);foreach($added as $who)sql('INSERT INTO thread_members VALUES(?,?,?,?)',[$cid,$c['server_id'],$who,time()]);hierarchy_audit($c['server_id'],'thread-members',0,thread_audit_label($c).' · Added '.count($added).' · Removed '.count($removed));return ['ok'=>true,'revision'=>(int)one('SELECT thread_membership_revision FROM channels WHERE id=?',[$cid])['thread_membership_revision']];});
 case 'private_thread_create':
  $cid=text_field($in,'channel_id',32,32);$name=text_field($in,'name',1,32);$ids=$in['members']??null;if(!is_array($ids)||count($ids)>20||count(array_unique($ids,SORT_REGULAR))!==count($ids))fail(400,'Choose up to 20 different members.');foreach($ids as $who)positive_id($who);rate('private-thread:'.uid(),10,60);
  return transaction(function()use($cid,$name,$ids){$parent=channel($cid);if(!$parent['server_id']||$parent['kind']!=='text'||$parent['parent_id'])fail(400,'Choose a server text channel.');if(!channel_capabilities($parent)['create_private_threads'])fail(403,'Create Private Threads is required.');if(member($parent['server_id'])['muted_until']>time())fail(403,'You cannot create a thread while timed out.');
   foreach($ids as $who)if(blocked($who)||!can_view_as($cid,$who))fail(403,'A selected member cannot access this channel.');
   $key=hash('sha256','thread-create:'.$cid.':'.uid());$now=time();if($parent['slowmode']>0&&!in_array('manage_messages',permissions_for($parent['server_id']),true)&&!channel_capabilities($parent)['bypass_slowmode']&&one('SELECT 1 FROM limits WHERE key=? AND window>?',[$key,$now-$parent['slowmode']]))fail(429,'Slow mode is active. Please wait before creating another thread in this channel.');
   require_thread_capacity($cid);$id=random_id();$now=time();sql('INSERT INTO channels(id,server_id,name,kind,private,thread_private,created,parent_id,slowmode,thread_activity,auto_archive_minutes,thread_creator_id) VALUES(?,?,?,?,?,?,?,?,?,?,?,?)',[$id,$parent['server_id'],$name,'text',1,1,$now,$cid,$parent['default_thread_slowmode'],$now,$parent['default_thread_auto_archive_minutes'],uid()]);foreach(array_unique(array_merge([uid()],$ids)) as $who)sql('INSERT INTO thread_members VALUES(?,?,?,?)',[$id,$parent['server_id'],$who,$now]);sql('INSERT INTO limits(key,window,hits) VALUES(?,?,1) ON CONFLICT(key) DO UPDATE SET window=excluded.window,hits=1',[$key,$now]);hierarchy_audit($parent['server_id'],'thread-create',0,thread_audit_label(one('SELECT * FROM channels WHERE id=?',[$id])));return ['channel_id'=>$id];});
 case 'forum_guidelines_save':
  $cid=text_field($in,'channel_id',32,32);if(array_diff(array_keys($in),['action','channel_id','guidelines','expected_revision'])||!is_int($in['expected_revision']??null)||$in['expected_revision']<0||$in['expected_revision']>9007199254740991)fail(400,'Invalid forum guidelines request.');$next=text_field($in,'guidelines',0,2000);
  return transaction(function()use($cid,$in,$next){$c=channel($cid);if(empty($c['forum'])||$c['parent_id']||$c['kind']!=='text')fail(400,'Choose a forum.');require_permission($c['server_id'],'manage_channels');if(member($c['server_id'])['muted_until']>time())fail(403,'You cannot change forum settings while timed out.');if($in['expected_revision']!==$c['forum_guidelines_revision'])fail(409,'The post guidelines changed. Refresh and reopen this form.');if($next===$c['forum_guidelines'])return ['ok'=>true];sql('UPDATE channels SET forum_guidelines=?,forum_guidelines_revision=forum_guidelines_revision+1 WHERE id=?',[$next,$cid]);hierarchy_audit($c['server_id'],'channel-edit',0,channel_audit_label($c)."\nPost guidelines updated (".preg_match_all('/./us',$c['forum_guidelines']).' → '.preg_match_all('/./us',$next).' characters)');return ['ok'=>true];});
 case 'forum_layout_save':
  $cid=text_field($in,'channel_id',32,32);if(array_diff(array_keys($in),['action','channel_id','layout','expected_revision'])||!in_array($in['layout']??null,['list','cards'],true)||!is_int($in['expected_revision']??null)||$in['expected_revision']<0)fail(400,'Invalid forum layout request.');
  return transaction(function()use($cid,$in){$c=channel($cid);if(empty($c['forum'])||$c['parent_id']||$c['kind']!=='text')fail(400,'Choose a forum.');require_permission($c['server_id'],'manage_channels');if(member($c['server_id'])['muted_until']>time())fail(403,'You cannot change forum settings while timed out.');if($in['expected_revision']!==$c['forum_layout_revision'])fail(409,'The forum default changed. Refresh and reopen this form.');$next=$in['layout'];if($next===$c['forum_default_layout'])return ['ok'=>true];sql('UPDATE channels SET forum_default_layout=?,forum_layout_revision=forum_layout_revision+1 WHERE id=?',[$next,$cid]);$labels=['list'=>'List','cards'=>'Cards'];audit_changes($c['server_id'],'channel-edit',channel_audit_label($c),['layout'=>$labels[$c['forum_default_layout']]],['layout'=>$labels[$next]],['layout'=>'Default post layout']);return ['ok'=>true];});
 case 'forum_sort_save':
  $cid=text_field($in,'channel_id',32,32);if(array_diff(array_keys($in),['action','channel_id','sort','expected_revision'])||!in_array($in['sort']??null,['activity','newest'],true)||!is_int($in['expected_revision']??null)||$in['expected_revision']<0)fail(400,'Invalid forum sort request.');
  return transaction(function()use($cid,$in){$c=channel($cid);if(empty($c['forum'])||$c['parent_id']||$c['kind']!=='text')fail(400,'Choose a forum.');require_permission($c['server_id'],'manage_channels');if(member($c['server_id'])['muted_until']>time())fail(403,'You cannot change forum settings while timed out.');if($in['expected_revision']!==$c['forum_sort_revision'])fail(409,'The forum default changed. Refresh and reopen this form.');$next=$in['sort'];if($next===$c['forum_default_sort'])return ['ok'=>true];sql('UPDATE channels SET forum_default_sort=?,forum_sort_revision=forum_sort_revision+1 WHERE id=?',[$next,$cid]);$labels=['activity'=>'Recent activity','newest'=>'Newest posts'];audit_changes($c['server_id'],'channel-edit',channel_audit_label($c),['sort'=>$labels[$c['forum_default_sort']]],['sort'=>$labels[$next]],['sort'=>'Default post sort']);return ['ok'=>true];});
 case 'forum_post_pin':
  $cid=text_field($in,'channel_id',32,32);if(array_diff(array_keys($in),['action','channel_id','pinned','expected_revision'])||!is_bool($in['pinned']??null)||!is_int($in['expected_revision']??null)||$in['expected_revision']<0)fail(400,'Invalid forum pin request.');
  return transaction(function()use($cid,$in){
   $c=channel($cid);$parent=$c['parent_id']?channel($c['parent_id']):null;if(!$parent||empty($parent['forum'])||$parent['parent_id']||$parent['kind']!=='text'||$c['server_id']!==$parent['server_id']||$c['kind']!=='text'||!empty($c['thread_private']))fail(400,'Choose a public forum post.');
   if(!can_pin_forum_post($c))fail(403,'Only thread managers can change pins on open forum posts.');$old=forum_pin($parent['id']);if($in['expected_revision']!==$old['revision'])fail(409,'The forum pin changed. Refresh and reopen this form.');$next=$in['pinned']?$cid:($old['post_id']===$cid?null:$old['post_id']);if($next===$old['post_id'])return ['ok'=>true];
   sql('INSERT INTO forum_pins(channel_id,post_id,revision) VALUES(?,?,1) ON CONFLICT(channel_id) DO UPDATE SET post_id=excluded.post_id,revision=forum_pins.revision+1',[$parent['id'],$next]);hierarchy_audit($c['server_id'],'thread-edit',0,thread_audit_label($c)."\nForum pinned post: ".($old['post_id']??'None').' → '.($next??'None'));return ['ok'=>true];
  });
 case 'forum_post_create_context':
  global $db;if(array_diff(array_keys($in),['action','channel_id','context_version'])||($in['context_version']??null)!==1)fail(400,'Unsupported new post context.');$cid=text_field($in,'channel_id',32,32);$db->exec('BEGIN');
  try{$c=channel($cid,true,'create_public_threads');if(empty($c['forum'])||$c['parent_id']||!$c['server_id']||$c['kind']!=='text')fail(400,'Choose a forum.');channel($cid,true,'send_in_threads');return ['version'=>1,'channel_id'=>$cid,'tag_revision'=>(int)$c['forum_tag_revision'],'tags'=>forum_tags($cid,$c['server_id']),'require_tag'=>(bool)$c['forum_require_tag'],'can_apply_moderated'=>channel_capabilities($c)['manage_threads']];}finally{$db->exec('ROLLBACK');}
 case 'forum_tag_context':
  global $db;if(array_diff(array_keys($in),['action','channel_id','context_version'])||($in['context_version']??null)!==1)fail(400,'Unsupported forum tag context.');$cid=text_field($in,'channel_id',32,32);$db->exec('BEGIN');
  try{$c=channel($cid);if(empty($c['forum'])||$c['parent_id'])fail(400,'Choose a forum.');require_permission($c['server_id'],'manage_channels');if(member($c['server_id'])['muted_until']>time())fail(403,'You cannot change tags while timed out.');$tags=forum_tags($cid,$c['server_id']);$emoji=sql("SELECT id,substr(slot,7) AS name FROM media WHERE server_id=? AND slot LIKE 'emoji:%' ORDER BY slot LIMIT 51",[$c['server_id']])->fetchAll();if(count($tags)>20||count($emoji)>50)fail(409,'Forum tag settings exceed current limits.');return ['version'=>1,'channel_id'=>$cid,'tag_revision'=>(int)$c['forum_tag_revision'],'tags'=>$tags,'require_tag'=>(bool)$c['forum_require_tag'],'emoji'=>$emoji];}finally{$db->exec('ROLLBACK');}
 case 'forum_tags_save':
  $cid=text_field($in,'channel_id',32,32);return transaction(function()use($cid,$in){
   $c=channel($cid);if(empty($c['forum'])||$c['parent_id'])fail(400,'Choose a forum.');require_permission($c['server_id'],'manage_channels');if(member($c['server_id'])['muted_until']>time())fail(403,'You cannot change tags while timed out.');forum_tag_revision($c,$in);
   $tags=$in['tags']??null;if(!is_array($tags)||!array_is_list($tags)||count($tags)>20)fail(400,'Use up to twenty forum tags.');$next=[];$ids=[];$names=[];
   foreach($tags as $tag){if(!is_array($tag)||array_diff(array_keys($tag),['id','name','moderated','emoji']))fail(400,'Invalid tag.');$id=text_field($tag,'id',32,32);$name=text_field($tag,'name',1,20);if(!preg_match('/^[a-f0-9]{32}$/D',$id)||preg_match('/[\x00-\x1F\x7F]/u',$name))fail(400,'Invalid tag name or ID.');$fold=message_casefold($name);if(in_array($id,$ids,true)||in_array($fold,$names,true))fail(400,'Tag names and IDs must be distinct.');$old=one('SELECT channel_id,moderated,emoji FROM forum_tags WHERE id=?',[$id]);if($old&&$old['channel_id']!==$cid)fail(400,'This tag belongs to another forum.');$moderated=array_key_exists('moderated',$tag)?$tag['moderated']:(bool)($old['moderated']??false);if(!is_bool($moderated))fail(400,'Invalid manager-only tag setting.');$ids[]=$id;$names[]=$fold;$nextTag=['id'=>$id,'name'=>$name,'moderated'=>$moderated];$emoji=validated_forum_tag_emoji($tag,$old?:null,$c['server_id']);if($emoji)$nextTag['emoji']=$emoji;$next[]=$nextTag;}
   $required=array_key_exists('require_tag',$in)?$in['require_tag']:(bool)$c['forum_require_tag'];if(!is_bool($required))fail(400,'Invalid required tag setting.');if($required&&!array_filter($next,fn($tag)=>!$tag['moderated']))fail(400,'Keep at least one tag everyone can choose or turn off Require a tag.');$before=forum_tags($cid);if($before===$next&&$required===(bool)$c['forum_require_tag'])return ['ok'=>true];foreach($before as $tag)if(!in_array($tag['id'],$ids,true))sql('DELETE FROM forum_tags WHERE id=?',[$tag['id']]);foreach($next as $position=>$tag)sql('INSERT INTO forum_tags(id,channel_id,name,position,moderated,emoji) VALUES(?,?,?,?,?,?) ON CONFLICT(id) DO UPDATE SET name=excluded.name,position=excluded.position,moderated=excluded.moderated,emoji=excluded.emoji',[$tag['id'],$cid,$tag['name'],$position,(int)$tag['moderated'],isset($tag['emoji'])?json_encode($tag['emoji'],JSON_UNESCAPED_UNICODE):null]);sql('UPDATE channels SET forum_require_tag=?,forum_tag_revision=forum_tag_revision+1 WHERE id=?',[(int)$required,$cid]);hierarchy_audit($c['server_id'],'channel-edit',0,channel_audit_label($c)."\nForum tags: ".implode(', ',array_map(fn($tag)=>$tag['name'].(isset($tag['emoji'])?' ['.$tag['emoji']['value'].']':'').($tag['moderated']?' [managers only]':''),$before)).' → '.implode(', ',array_map(fn($tag)=>$tag['name'].(isset($tag['emoji'])?' ['.$tag['emoji']['value'].']':'').($tag['moderated']?' [managers only]':''),$next))."\nRequire a tag: ".($c['forum_require_tag']?'On':'Off').' → '.($required?'On':'Off'));return ['ok'=>true];
  });
 case 'forum_post_tag_context':
  global $db;if(array_diff(array_keys($in),['action','channel_id','context_version'])||($in['context_version']??null)!==1)fail(400,'Unsupported post tag context.');$cid=text_field($in,'channel_id',32,32);$db->exec('BEGIN');
  try{$c=channel($cid);$parent=$c['parent_id']?channel($c['parent_id']):null;if(!$parent||empty($parent['forum']))fail(400,'Choose a forum post.');if(!can_edit_forum_post_tags($c))fail(403,'Only the creator or a thread manager can tag an open post.');return ['version'=>1,'channel_id'=>$cid,'parent_id'=>$parent['id'],'post_revision'=>(int)$c['forum_tag_revision'],'tag_revision'=>(int)$parent['forum_tag_revision'],'tag_ids'=>forum_post_tag_ids($cid),'tags'=>forum_tags($parent['id'],$parent['server_id']),'require_tag'=>(bool)$parent['forum_require_tag'],'can_apply_moderated'=>channel_capabilities($parent)['manage_threads']&&member($parent['server_id'])['muted_until']<=time()];}finally{$db->exec('ROLLBACK');}
 case 'forum_post_tags_save':
  $cid=text_field($in,'channel_id',32,32);return transaction(function()use($cid,$in){
   $c=channel($cid);$parent=$c['parent_id']?channel($c['parent_id']):null;if(!$parent||empty($parent['forum']))fail(400,'Choose a forum post.');if(!can_edit_forum_post_tags($c))fail(403,'Only the creator or a thread manager can tag an open post.');forum_tag_revision($c,$in);$old=forum_post_tag_ids($cid);$ids=validated_forum_post_tags($parent,$in,$old);if($old===$ids)return ['ok'=>true];sql('DELETE FROM forum_post_tags WHERE post_id=?',[$cid]);foreach($ids as $id)sql('INSERT INTO forum_post_tags VALUES(?,?)',[$cid,$id]);sql('UPDATE channels SET forum_tag_revision=forum_tag_revision+1 WHERE id=?',[$cid]);$names=array_column(forum_tags($parent['id']),'name','id');hierarchy_audit($c['server_id'],'thread-edit',0,thread_audit_label($c)."\nTags: ".implode(', ',array_intersect_key($names,array_flip($old))).' → '.implode(', ',array_intersect_key($names,array_flip($ids))));return ['ok'=>true];
  });
 case 'thread_start':
  $cid=text_field($in,'channel_id',32,32);$name=text_field($in,'name',1,32);$body=text_field($in,'body',1,4000);$client=text_field($in,'client_id',16,64);
  if(!preg_match('/^[a-zA-Z0-9_-]{16,64}$/D',$client))fail(400,'Invalid discussion retry key.');
  foreach(['attachments','reply_to','system_type','system_target','notify_everyone','notify_here'] as $field)if(array_key_exists($field,$in))fail(400,'Start a discussion with a title and text only. Add files and notifications after opening it.');
  return transaction(function()use($cid,$name,$body,$client,$in){
   $parent=channel($cid,true,'create_public_threads');if(!$parent['server_id']||$parent['kind']!=='text'||$parent['parent_id'])fail(400,'Start a discussion in a server text channel.');channel($cid,true,'send_in_threads');
   $old=one('SELECT * FROM channels WHERE thread_creator_id=? AND thread_client_id=?',[uid(),$client]);if($old){if($old['parent_id']!==$cid)fail(409,'This retry belongs to another channel.');channel($old['id']);return ['channel_id'=>$old['id']];}
   $opening=$in['opening_attachment']??null;$file=null;
   if(array_key_exists('opening_attachment',$in)){
    if(empty($parent['forum'])||!is_array($opening)||array_diff(array_keys($opening),['name','data','description','spoiler']))fail(400,'Choose one opening file for a forum post.');
    [$filename,$bytes,$encoded]=attachment_payload($opening);$description=text_field($opening,'description',0,1000);$spoiler=array_key_exists('spoiler',$opening)?$opening['spoiler']:false;if(!is_bool($spoiler))fail(400,'Invalid attachment spoiler choice.');$file=[$filename,$bytes,$encoded,$description,$spoiler];
   }
   $tags=array_key_exists('tags',$in)?validated_forum_post_tags($parent,$in):[];check_required_forum_tags($parent,$tags);
   $key=hash('sha256','thread-create:'.$cid.':'.uid());$now=time();
   if($parent['slowmode']>0&&!in_array('manage_messages',permissions_for($parent['server_id']),true)&&!channel_capabilities($parent)['bypass_slowmode']&&one('SELECT 1 FROM limits WHERE key=? AND window>?',[$key,$now-$parent['slowmode']]))fail(429,'Slow mode is active. Please wait before creating another thread in this channel.');
   require_thread_capacity($cid);
   $id=random_id();sql('INSERT INTO channels(id,server_id,name,kind,created,parent_id,slowmode,thread_activity,auto_archive_minutes,thread_creator_id,thread_client_id) VALUES(?,?,?,?,?,?,?,?,?,?,?)',[$id,$parent['server_id'],$name,'text',$now,$cid,$parent['default_thread_slowmode'],$now,$parent['default_thread_auto_archive_minutes'],uid(),$client]);
   $attachments=[];if($file){rate('attachment:'.uid(),20,3600);$uploaded=store_attachment($id,[], $file[0],$file[1],$file[2]);$attachments[]=$uploaded['id'];sql('UPDATE attachments SET description=?,spoiler=? WHERE id=?',[$file[3],(int)$file[4],$uploaded['id']]);}
   send_message(['channel_id'=>$id,'body'=>$body,'client_id'=>'thread-start:'.$id,'attachments'=>$attachments]);foreach($tags as $tag)sql('INSERT INTO forum_post_tags VALUES(?,?)',[$id,$tag]);
   sql('INSERT INTO limits(key,window,hits) VALUES(?,?,1) ON CONFLICT(key) DO UPDATE SET window=excluded.window,hits=1',[$key,$now]);hierarchy_audit($parent['server_id'],'thread-create',0,thread_audit_label(one('SELECT * FROM channels WHERE id=?',[$id])));return ['channel_id'=>$id];
  });
 case 'thread_create':
  $cid=text_field($in,'channel_id',32,32);$root=positive_id($in['message_id']??null);$name=text_field($in,'name',1,32);
  return transaction(function()use($cid,$root,$name){$parent=channel($cid);if(!$parent['server_id']||$parent['kind']!=='text'||$parent['parent_id'])fail(400,'Start a thread in a server text channel.');if(!one('SELECT 1 FROM messages WHERE id=? AND channel_id=? AND deleted=0',[$root,$parent['id']]))fail(404,'Message not found.');$old=one('SELECT id FROM channels WHERE root_message=?',[$root]);if($old)return ['channel_id'=>$old['id']];channel($cid,true,'create_public_threads');check_required_forum_tags($parent,[]);
   // Thread creation has its own sliding interval; only successful creates advance it.
   $key=hash('sha256','thread-create:'.$cid.':'.uid());$now=time();
   if($parent['slowmode']>0&&!in_array('manage_messages',permissions_for($parent['server_id']),true)&&!channel_capabilities($parent)['bypass_slowmode']&&one('SELECT 1 FROM limits WHERE key=? AND window>?',[$key,$now-$parent['slowmode']]))fail(429,'Slow mode is active. Please wait before creating another thread in this channel.');
   require_thread_capacity($parent['id']);$id=random_id();sql('INSERT INTO channels(id,server_id,name,kind,created,parent_id,root_message,slowmode,thread_activity,auto_archive_minutes,thread_creator_id) VALUES(?,?,?,?,?,?,?,?,?,?,?)',[$id,$parent['server_id'],$name,'text',$now,$parent['id'],$root,$parent['default_thread_slowmode'],$now,$parent['default_thread_auto_archive_minutes'],uid()]);sql('INSERT INTO limits(key,window,hits) VALUES(?,?,1) ON CONFLICT(key) DO UPDATE SET window=excluded.window,hits=1',[$key,$now]);join_thread(one('SELECT * FROM channels WHERE id=?',[$id]));hierarchy_audit($parent['server_id'],'thread-create',0,thread_audit_label(one('SELECT * FROM channels WHERE id=?',[$id])));return ['channel_id'=>$id];});
 case 'thread_settings':
  $cid=text_field($in,'channel_id',32,32);
  transaction(function()use($cid,$in){$c=channel($cid);if(!$c['parent_id'])fail(400,'Choose a thread.');$manager=channel_capabilities($c)['manage_threads'];if(!$manager){if(!thread_creator_controls($c)||($in['thread_version']??null)!==1)fail(403,'You cannot edit this thread.');if(array_key_exists('slowmode',$in)||array_key_exists('locked',$in))fail(403,'Only thread managers can change slow mode or locks.');}$name=text_field($in,'name',1,32);$slow=$manager?($in['slowmode']??null):(int)$c['slowmode'];if(!is_int($slow)||$slow<0||$slow>21600)fail(400,'Slow mode must be 0–21600 seconds.');
   check_thread_revision($c,$in);if(!$manager&&$c['archived'])fail(403,'Reopen this thread before editing it.');$locked=array_key_exists('locked',$in)?$in['locked']:(bool)$c['locked'];if(!is_bool($locked))fail(400,'Invalid thread lock state.');$auto=array_key_exists('auto_archive_minutes',$in)?$in['auto_archive_minutes']:$c['auto_archive_minutes'];if(!is_int($auto)||!in_array($auto,[0,60,1440,4320,10080],true))fail(400,'Choose a valid automatic archive interval.');$before=['name'=>$c['name'],'slowmode'=>(int)$c['slowmode'],'locked'=>(bool)$c['locked'],'auto_archive_minutes'=>(int)$c['auto_archive_minutes']];$after=['name'=>$name,'slowmode'=>$slow,'locked'=>$locked,'auto_archive_minutes'=>$auto];if($before===$after)return;sql('UPDATE channels SET name=?,slowmode=?,locked=?,auto_archive_minutes=?,thread_activity=?,thread_revision=thread_revision+1 WHERE id=?',[$name,$slow,(int)$locked,$auto,$auto!==(int)$c['auto_archive_minutes']?time():$c['thread_activity'],$cid]);if(!empty($c['thread_private']))hierarchy_audit($c['server_id'],'thread-edit',0,thread_audit_label($c));else audit_changes($c['server_id'],'thread-edit',thread_audit_label($c),$before,$after,['name'=>'Name','slowmode'=>'Slow mode (seconds)','locked'=>'Locked','auto_archive_minutes'=>'Auto archive (minutes; 0 is off)']);
  });return ['ok'=>true];
 case 'thread_archive':
  $cid=text_field($in,'channel_id',32,32);if(!is_bool($in['archived']??null))fail(400,'Invalid archive state.');$archived=$in['archived'];
  transaction(function()use($cid,$archived,$in){$c=channel($cid);if(!$c['parent_id'])fail(400,'Choose a thread.');$manager=channel_capabilities($c)['manage_threads'];if(!$manager){if(($in['thread_version']??null)!==1||($archived?!thread_creator_controls($c):(bool)$c['locked']))fail(403,'You cannot change this thread archive state.');if(!$archived)channel($c['parent_id'],true,'send_in_threads');}
   $modern=check_thread_revision($c,$in);if(array_key_exists('locked',$in))fail(400,'Change the lock in Thread settings.');
   // Old Archive buttons meant manager-only reopening; retain that protection.
   $locked=(bool)$c['locked']||(!$modern&&$archived);$before=['archived'=>(bool)$c['archived'],'locked'=>(bool)$c['locked']];$after=['archived'=>$archived,'locked'=>$locked];if($before===$after)return;sql('UPDATE channels SET archived=?,locked=?,thread_activity=?,thread_revision=thread_revision+1 WHERE id=?',[(int)$archived,(int)$locked,!$archived&&$c['archived']?time():$c['thread_activity'],$cid]);audit_changes($c['server_id'],$archived?'thread-archive':'thread-reopen',thread_audit_label($c),$before,$after,['archived'=>'Archived','locked'=>'Locked']);});return ['ok'=>true];
 case 'override_list':case 'override_save':
  $c=channel(text_field($in,'channel_id',32,32));if(!$c['server_id']||$c['parent_id'])fail(400,'Threads inherit their parent channel permissions.');require_permission($c['server_id'],'manage_channels');
  if($action==='override_save'){
   transaction(function()use($c,$in){$c=channel($c['id']);require_permission($c['server_id'],'manage_channels');[$kind,$subject,$values]=override_input($c['server_id'],$in);check_override_version($in,sql('SELECT permission FROM channel_overrides WHERE channel_id=? AND kind=? AND subject=?',[$c['id'],$kind,$subject])->fetchAll());save_channel_override($c,$kind,$subject,$values);set_channel_sync($c,null);});
  }return ['overrides'=>sql('SELECT * FROM channel_overrides WHERE channel_id=?',[$c['id']])->fetchAll()];
 case 'attachment_list':
  if(array_diff(array_keys($in),['action','channel_id','before','type','search']))fail(400,'Invalid attachment list request.');
  $cid=text_field($in,'channel_id',32,32);$c=channel($cid);if($c['kind']!=='text')fail(400,'Choose a text channel.');
  $extensions=attachment_type_extensions(array_key_exists('type',$in)?$in['type']:'all');
  $search=array_key_exists('search',$in)?text_field($in,'search',0,120):'';$filter='';$filterArgs=[];
  if($extensions){$filter.=' AND ('.implode(' OR ',array_fill(0,count($extensions),'lower(a.name) LIKE ?')).')';foreach($extensions as $extension)$filterArgs[]='%.'.$extension;}
  if($search!==''){register_casefold_search();$filter.=' AND instr(gather_casefold(a.name),?)>0';$filterArgs[]=message_casefold($search);}
  $where='m.channel_id=? AND m.deleted=0 AND EXISTS(SELECT 1 FROM attachments a WHERE a.message_id=m.id AND a.channel_id=m.channel_id'.$filter.') AND NOT EXISTS(SELECT 1 FROM blocks b WHERE (b.user_id=? AND b.target=m.user_id) OR (b.target=? AND b.user_id=m.user_id))';$args=array_merge([$c['server_id'],$cid],$filterArgs,[uid(),uid()]);
  if(array_key_exists('before',$in)){$before=positive_id($in['before']);if($before>9007199254740991)fail(400,'Invalid attachment cursor.');$where.=' AND m.id<?';$args[]=$before;}
  $rows=sql("SELECT m.id,m.created,u.name,COALESCE(mb.nickname,'') AS nickname FROM messages m JOIN users u ON u.id=m.user_id LEFT JOIN members mb ON mb.user_id=m.user_id AND mb.server_id=? WHERE $where ORDER BY m.id DESC LIMIT 31",$args)->fetchAll();$more=count($rows)>30;$rows=array_slice($rows,0,30);
  foreach($rows as &$row)$row['attachments']=sql('SELECT a.id,a.name,a.size,a.description,a.spoiler FROM attachments a WHERE a.message_id=? AND a.channel_id=?'.$filter.' ORDER BY a.created,a.id LIMIT 3',array_merge([$row['id'],$cid],$filterArgs))->fetchAll();unset($row);$last=end($rows);
  return ['messages'=>$rows,'next'=>$more?(int)$last['id']:null];
 case 'attachment_upload':
  $cid=text_field($in,'channel_id',32,32);$c=send_channel($cid,$in,'attach');if($c['kind']!=='text')fail(400,'Choose a text channel.');send_channel($cid,$in);rate('attachment:'.uid(),20,3600);[$name,$bytes,$encoded]=attachment_payload($in);
  return transaction(fn()=>store_attachment($cid,$in,$name,$bytes,$encoded));
 case 'attachment_rename':
  if(array_diff(array_keys($in),['action','id','name','expected_name','description','expected_description','spoiler','expected_spoiler']))fail(400,'Invalid rename request.');
  $id=text_field($in,'id',32,32);$name=attachment_name($in);$expected=text_field($in,'expected_name',1,120);$describing=array_key_exists('description',$in);if($describing!==array_key_exists('expected_description',$in))fail(400,'Include the current description.');$description=$describing?text_field($in,'description',0,1000):null;$expectedDescription=$describing?text_field($in,'expected_description',0,1000):null;
  $spoiling=array_key_exists('spoiler',$in);if($spoiling!==array_key_exists('expected_spoiler',$in)||($spoiling&&(!is_bool($in['spoiler'])||!is_bool($in['expected_spoiler']))))fail(400,'Include a valid current spoiler choice.');$spoiler=$spoiling?(int)$in['spoiler']:null;$expectedSpoiler=$spoiling?(int)$in['expected_spoiler']:null;
  return transaction(function()use($id,$name,$expected,$description,$expectedDescription,$spoiler,$expectedSpoiler){
   $a=one('SELECT id,channel_id,user_id,message_id,name,created,description,spoiler FROM attachments WHERE id=?',[$id]);if(!$a)fail(404,'Attachment not found.');$c=channel($a['channel_id']);
   if((int)$a['user_id']!==uid())fail(403,'Only your unsent attachments can be renamed.');if($a['message_id']||(int)$a['created']<time()-86400)fail(404,'This attachment is no longer in your draft.');
   $scope=['reopen_thread'=>(bool)$c['parent_id']];send_channel($a['channel_id'],$scope,'attach');send_channel($a['channel_id'],$scope);
   $nextDescription=$description??$a['description'];$nextSpoiler=$spoiler??(int)$a['spoiler'];if($name===$a['name']&&$nextDescription===$a['description']&&$nextSpoiler===(int)$a['spoiler'])return ['id'=>$id,'name'=>$name,'description'=>$nextDescription,'spoiler'=>$nextSpoiler];
   if($a['name']!==$expected||($description!==null&&$a['description']!==$expectedDescription)||($spoiler!==null&&(int)$a['spoiler']!==$expectedSpoiler))fail(409,'This attachment changed. Remove it and attach it again.');
   if(strtolower(pathinfo($name,PATHINFO_EXTENSION))!==strtolower(pathinfo($a['name'],PATHINFO_EXTENSION)))fail(400,'Keep the original file extension.');
   sql('UPDATE attachments SET name=?,description=?,spoiler=? WHERE id=?',[$name,$nextDescription,$nextSpoiler,$id]);return ['id'=>$id,'name'=>$name,'description'=>$nextDescription,'spoiler'=>$nextSpoiler];
  });
 case 'attachment_get':case 'attachment_preview':case 'attachment_discard':
  if($action==='attachment_preview'&&(array_diff(array_keys($in),['action','id','check_only','draft','forum_post_id'])||(array_key_exists('check_only',$in)&&!is_bool($in['check_only']))||(array_key_exists('draft',$in)&&!is_bool($in['draft']))))fail(400,'Invalid preview request.');
  if($action==='attachment_get'&&array_key_exists('draft',$in)&&!is_bool($in['draft']))fail(400,'Invalid attachment request.');
  $id=text_field($in,'id',32,32);$a=one('SELECT * FROM attachments WHERE id=?',[$id]);if(!$a)fail(404,'Attachment not found.');$c=channel($a['channel_id']);if($action!=='attachment_discard'&&!empty($in['draft'])&&$a['message_id'])fail(404,'This attachment is no longer in your draft.');if($a['message_id']){$message=one('SELECT user_id FROM messages WHERE id=? AND channel_id=? AND deleted=0',[$a['message_id'],$a['channel_id']]);if(!$message)fail(404,'Attachment no longer available.');if($action!=='attachment_discard'&&blocked((int)$message['user_id']))fail(403,'This attachment is not available.');}elseif((int)$a['user_id']!==uid())fail(403,'This attachment has not been shared.');
  if($action==='attachment_discard'){if((int)$a['user_id']!==uid()||$a['message_id'])fail(403,'Only your unsent attachments can be discarded.');sql('DELETE FROM attachments WHERE id=?',[$id]);return ['ok'=>true];}
  if(!$a['message_id']){if((int)$a['created']<time()-86400)fail(404,'This draft attachment has expired.');$scope=['reopen_thread'=>(bool)$c['parent_id']];send_channel($a['channel_id'],$scope,'attach');send_channel($a['channel_id'],$scope);}
  if($action==='attachment_preview'){
   if(array_key_exists('forum_post_id',$in)){
    $post=text_field($in,'forum_post_id',32,32);if(!empty($in['draft'])||$post!==$c['id']||!$c['parent_id'])fail(404,'Opening image no longer available.');channel($c['parent_id']);if((forum_images([$post])[$post]??null)!==$id)fail(404,'Opening image no longer available.');
   }
   if(!empty($in['check_only']))return ['ok'=>true];
   if(!preg_match('/\.(png|jpe?g|webp|gif)$/iD',$a['name'])||$a['size']<1||$a['size']>4194304)fail(400,'This attachment cannot be previewed. Download it instead.');
   $bytes=base64_decode($a['data'],true);$info=$bytes===false?false:@getimagesizefromstring($bytes);
   if(!$info||!in_array($info['mime']??'', ['image/png','image/jpeg','image/webp','image/gif'],true)||$info[0]<1||$info[1]<1||$info[0]>8192||$info[1]>8192||$info[0]*$info[1]>16777216)fail(400,'Preview requires a supported image up to 8192 pixels per side and 16 megapixels.');
   return array_intersect_key($a,array_flip(['name','size','sha256','data','description']))+['type'=>$info['mime'],'width'=>$info[0],'height'=>$info[1]];
  }return array_intersect_key($a,array_flip(['name','size','sha256','data','description']));
 }fail(404,'Unknown conversation action.');
}
function thread_message_locked(array $c): bool {return (bool)($c['parent_id']&&!empty($c['locked'])&&!channel_capabilities($c)['manage_threads']);}
function require_thread_message_write(array $c): void {if(thread_message_locked($c))fail(403,'This thread is locked. Only thread managers can send or edit messages.');}
function send_channel(string $cid,array $in,string $permission='send'): array {
 $reopen=array_key_exists('reopen_thread',$in)?$in['reopen_thread']:false;if(!is_bool($reopen))fail(400,'Invalid thread reopening choice.');
 if(!$reopen){$c=channel($cid,true,$permission);require_thread_message_write($c);return $c;}
 $c=channel($cid);if(!$c['parent_id'])fail(400,'Only threads can be reopened while sending.');
 channel($c['parent_id'],true,'send_in_threads');if($permission!=='send')channel($c['parent_id'],true,$permission);
 require_thread_message_write($c);return $c;
}
function send_message(array $in,?array $poll=null): array {
 if(array_key_exists('system_type',$in)||array_key_exists('system_target',$in))fail(400,'System notice fields are reserved.');
 $cid=text_field($in,'channel_id',32,32);$c=send_channel($cid,$in);if($poll)send_channel($cid,$in,'create_polls');if($c['kind']!=='text')fail(400,'Use a text channel.');rate('send:'.uid(),30,60);$ids=$in['attachments']??[];if(!is_array($ids)||count($ids)>3||count(array_unique($ids,SORT_REGULAR))!==count($ids))fail(400,'Attach up to 3 files.');foreach($ids as $id)if(!is_string($id)||!preg_match('/^[a-f0-9]{32}$/',$id))fail(400,'Invalid attachment.');$body=text_field($in,'body',$ids?0:1,4000);$client=text_field($in,'client_id',16,64);$reply=isset($in['reply_to'])?positive_id($in['reply_to']):null;if(str_starts_with($client,'system-pin:'))fail(400,'System notice fields are reserved.');
 if(array_key_exists('notify_everyone',$in)&&!is_bool($in['notify_everyone']))fail(400,'Invalid everyone mention choice.');
 if(array_key_exists('notify_here',$in)&&!is_bool($in['notify_here']))fail(400,'Invalid here mention choice.');
 if(!empty($in['notify_here'])&&!empty($in['notify_everyone']))fail(400,'Choose one notification audience.');
 $broadcast=!empty($in['notify_everyone'])?'everyone':(!empty($in['notify_here'])?'here':null);
 $save=function()use($cid,$in,$ids,$body,$client,$reply,$broadcast,$poll){$c=send_channel($cid,$in);if($poll)send_channel($cid,$in,'create_polls');$old=one('SELECT id,channel_id FROM messages WHERE user_id=? AND client_id=?',[uid(),$client]);if($old){if($poll&&!one('SELECT 1 FROM polls WHERE message_id=?',[$old['id']]))fail(409,'This retry belongs to a different message.');if($old['channel_id']!==$cid)fail(409,'Message retry belongs to another channel.');return ['id'=>(int)$old['id']];}
  if($poll){$body='Poll: '.$poll['question'];foreach($poll['answer_emoji'] as $i=>$emoji){if($emoji!==null){$media=$c['server_id']?one("SELECT id,slot FROM media WHERE id=? AND server_id=? AND slot LIKE 'emoji:%'",[$emoji,$c['server_id']]):null;if(!$media)fail(400,'Choose an emoji from this server.');$poll['answer_emoji'][$i]=['id'=>$media['id'],'name'=>substr($media['slot'],6)];}$body.="\n".($i+1).'. '.($emoji!==null?':'.$poll['answer_emoji'][$i]['name'].': ':'').$poll['answers'][$i];}}
  if($reply&&!one('SELECT 1 FROM messages WHERE id=? AND channel_id=? AND deleted=0',[$reply,$cid]))fail(400,'Reply must reference a message in this conversation.');
  if($c['server_id']&&$c['slowmode']>0&&!in_array('manage_messages',permissions_for($c['server_id']),true)&&!channel_capabilities($c)['bypass_slowmode']&&one("SELECT 1 FROM messages WHERE channel_id=? AND user_id=? AND system_type='' AND created>?",[$cid,uid(),time()-$c['slowmode']]))fail(429,'Slow mode is active. Please wait before sending another message.');
  if($ids)send_channel($cid,$in,'attach');foreach($ids as $id)if(!one('SELECT 1 FROM attachments WHERE id=? AND user_id=? AND channel_id=? AND message_id IS NULL',[$id,uid(),$cid]))fail(403,'Choose your own unsent attachment from this conversation.');
  $reopened=(bool)($c['parent_id']&&$c['archived']);if($reopened){sql('UPDATE channels SET archived=0,thread_revision=thread_revision+1 WHERE id=?',[$cid]);audit_changes($c['server_id'],'thread-reopen',thread_audit_label($c),['archived'=>true],['archived'=>false],['archived'=>'Archived']);}
  sql('INSERT INTO messages(channel_id,user_id,body,created,client_id,reply_to) VALUES(?,?,?,?,?,?)',[$cid,uid(),$body,time(),$client,$reply]);global $db;$id=(int)$db->lastInsertId();if($poll)sql('INSERT INTO polls(message_id,question,answers,multiple,expires,answer_emoji) VALUES(?,?,?,?,?,?)',[$id,$poll['question'],json_encode($poll['answers']),$poll['multiple']?1:0,time()+$poll['duration'],json_encode($poll['answer_emoji'])]);if($c['parent_id']){join_thread($c);sql('UPDATE channels SET thread_activity=? WHERE id=?',[time(),$cid]);}foreach($ids as $a)sql('UPDATE attachments SET message_id=? WHERE id=?',[$id,$a]);sql('DELETE FROM typing WHERE user_id=? AND channel_id=?',[uid(),$cid]);notify_message($id,$c,$body,$reply,$broadcast);return ['id'=>$id,'thread_reopened'=>$reopened];
 };
 global $writeTransaction;return !empty($writeTransaction)?$save():transaction($save);
}
function migrate_polls(): void {
 global $db;if((int)$db->query('PRAGMA user_version')->fetchColumn()>=56)return;
 transaction(function()use($db){if((int)$db->query('PRAGMA user_version')->fetchColumn()>=56)return;$db->exec('CREATE TABLE polls(message_id INTEGER PRIMARY KEY REFERENCES messages(id) ON DELETE CASCADE,question TEXT NOT NULL,answers TEXT NOT NULL,multiple INTEGER NOT NULL,expires INTEGER NOT NULL,closed INTEGER NOT NULL DEFAULT 0); CREATE TABLE poll_votes(message_id INTEGER NOT NULL REFERENCES polls(message_id) ON DELETE CASCADE,user_id INTEGER NOT NULL REFERENCES users(id) ON DELETE CASCADE,choice INTEGER NOT NULL,PRIMARY KEY(message_id,user_id,choice)); CREATE INDEX poll_answer_votes ON poll_votes(message_id,choice,user_id); PRAGMA user_version=56;');});
}
function migrate_poll_emoji(): void {
 global $db;if((int)$db->query('PRAGMA user_version')->fetchColumn()>=58)return;
 transaction(function()use($db){if((int)$db->query('PRAGMA user_version')->fetchColumn()>=58)return;$db->exec("ALTER TABLE polls ADD COLUMN answer_emoji TEXT NOT NULL DEFAULT '[]'; PRAGMA user_version=58;");});
}
function poll_access(int $id,bool $write=false): array {
 $m=one('SELECT * FROM messages WHERE id=? AND deleted=0',[$id]);$p=one('SELECT * FROM polls WHERE message_id=?',[$id]);if(!$m||!$p)fail(404,'Poll not found.');$c=channel($m['channel_id'],$write,'view');if(blocked((int)$m['user_id']))fail(403,'This poll is unavailable.');if($write)require_thread_message_write($c);return [$m,$p];
}
function message_polls(array $messages): array {
 // Batch poll rows and votes for already-authorized message pages.
 $authors=[];foreach($messages as $m)if($m['has_poll']&&!blocked((int)$m['user_id']))$authors[(int)$m['id']]=(int)$m['user_id'];if(!$authors)return [];
 $ids=array_keys($authors);$slots=implode(',',array_fill(0,count($ids),'?'));$polls=[];$emojiIds=[];$servers=[];
 foreach(sql("SELECT p.*,c.server_id FROM polls p JOIN messages m ON m.id=p.message_id JOIN channels c ON c.id=m.channel_id WHERE p.message_id IN ($slots)",$ids)->fetchAll() as $p){$id=(int)$p['message_id'];$answers=json_decode($p['answers'],true);$emojis=json_decode($p['answer_emoji'],true)?:array_fill(0,count($answers),null);$servers[$id]=$p['server_id'];foreach($emojis as $e)if($e)$emojiIds[$e['id']]=$e['id'];$polls[$id]=['id'=>$id,'question'=>$p['question'],'answers'=>$answers,'answer_emoji'=>$emojis,'counts'=>array_fill(0,count($answers),0),'multiple'=>(bool)$p['multiple'],'expires'=>(int)$p['expires'],'closed'=>(bool)$p['closed']||time()>=(int)$p['expires'],'creator'=>$authors[$id]===uid(),'choices'=>[]];}
 if($emojiIds){$media=[];$emojiSlots=implode(',',array_fill(0,count($emojiIds),'?'));foreach(sql("SELECT id,server_id FROM media WHERE id IN ($emojiSlots) AND slot LIKE 'emoji:%'",array_values($emojiIds))->fetchAll() as $e)$media[$e['id']]=$e['server_id'];foreach($polls as $id=>&$poll)foreach($poll['answer_emoji'] as &$e)if($e)$e['available']=isset($media[$e['id']])&&$media[$e['id']]===$servers[$id];unset($e,$poll);}
 foreach(sql("SELECT v.message_id,v.choice,count(*) AS n FROM poll_votes v WHERE v.message_id IN ($slots) AND NOT EXISTS(SELECT 1 FROM blocks b WHERE (b.user_id=? AND b.target=v.user_id) OR (b.target=? AND b.user_id=v.user_id)) GROUP BY v.message_id,v.choice",[...$ids,uid(),uid()])->fetchAll() as $v)if(isset($polls[$v['message_id']]))$polls[$v['message_id']]['counts'][(int)$v['choice']]=(int)$v['n'];
 foreach(sql("SELECT message_id,choice FROM poll_votes WHERE message_id IN ($slots) AND user_id=? ORDER BY message_id,choice",[...$ids,uid()])->fetchAll() as $v)if(isset($polls[$v['message_id']]))$polls[$v['message_id']]['choices'][]=(int)$v['choice'];return $polls;
}
function poll_result(int $id): array {
 [$m]=poll_access($id);return message_polls([['id'=>$id,'user_id'=>$m['user_id'],'has_poll'=>true]])[$id]??throw new ApiError(404,'Poll not found.');
}
function poll_action(string $action,array $in): array {
 if($action==='poll_create'){
  $question=text_field($in,'question',1,300);$raw=$in['answers']??null;if(!is_array($raw)||!array_is_list($raw)||count($raw)<2||count($raw)>10)fail(400,'Use 2–10 answers.');$answers=[];foreach($raw as $answer)$answers[]=text_field(['answer'=>$answer],'answer',1,100);if(count(array_unique($answers))!==count($answers))fail(400,'Answers must be different.');
  $emojis=array_key_exists('answer_emoji',$in)?$in['answer_emoji']:array_fill(0,count($answers),null);if(!is_array($emojis)||!array_is_list($emojis)||count($emojis)!==count($answers))fail(400,'Choose emoji for the matching answers.');foreach($emojis as $e)if($e!==null&&(!is_string($e)||!preg_match('/^[a-f0-9]{32}$/D',$e)))fail(400,'Choose a valid answer emoji.');
  $multiple=$in['multiple']??null;$duration=$in['duration']??null;if(!is_bool($multiple)||!in_array($duration,[3600,14400,28800,86400,259200,604800],true))fail(400,'Choose a valid poll duration and voting mode.');
  if(!empty($in['attachments'])||isset($in['reply_to'])||!empty($in['notify_here'])||!empty($in['notify_everyone']))fail(400,'Post a poll without attachments, replies or broadcast mentions.');
  $body='Poll: '.$question;foreach($answers as $i=>$answer)$body.="\n".($i+1).'. '.$answer;
  return send_message([...$in,'body'=>$body],['question'=>$question,'answers'=>$answers,'answer_emoji'=>$emojis,'multiple'=>$multiple,'duration'=>$duration]);
 }
 $id=positive_id($in['id']??null);
 if($action==='poll_get')return poll_result($id);
 if($action==='poll_voters'){
  [, $p]=poll_access($id);$choice=$in['choice']??null;$after=$in['after']??0;if(!is_int($choice)||$choice<0||$choice>=count(json_decode($p['answers'],true))||!is_int($after)||$after<0||$after>9007199254740991)fail(400,'Choose an answer and valid cursor.');
  $rows=sql('SELECT u.id,u.name,u.username FROM poll_votes v JOIN users u ON u.id=v.user_id WHERE v.message_id=? AND v.choice=? AND u.id>? AND NOT EXISTS(SELECT 1 FROM blocks b WHERE (b.user_id=? AND b.target=u.id) OR (b.target=? AND b.user_id=u.id)) ORDER BY u.id LIMIT 51',[$id,$choice,$after,uid(),uid()])->fetchAll();$more=count($rows)>50;$rows=array_slice($rows,0,50);return ['members'=>$rows,'next'=>$more?(int)end($rows)['id']:null];
 }
 rate('message-action:'.uid(),60,60);
 transaction(function()use($action,$id,$in){[$m,$p]=poll_access($id,true);
  if($action==='poll_close'){if((int)$m['user_id']!==uid())fail(403,'Only the creator can end this poll.');sql('UPDATE polls SET closed=1 WHERE message_id=?',[$id]);return;}
  if($p['closed']||time()>=(int)$p['expires'])fail(409,'This poll has ended.');$choices=$in['choices']??null;$size=count(json_decode($p['answers'],true));if(!is_array($choices)||!array_is_list($choices)||count($choices)>($p['multiple']?$size:1)||count(array_unique($choices,SORT_REGULAR))!==count($choices))fail(400,'Choose valid answers.');foreach($choices as $choice)if(!is_int($choice)||$choice<0||$choice>=$size)fail(400,'Choose valid answers.');
  sql('DELETE FROM poll_votes WHERE message_id=? AND user_id=?',[$id,uid()]);foreach($choices as $choice)sql('INSERT INTO poll_votes VALUES(?,?,?)',[$id,uid(),$choice]);
 });return poll_result($id);
}
