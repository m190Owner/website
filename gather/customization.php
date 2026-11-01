<?php
declare(strict_types=1);
function migrate_customization(): void {
 global $db;
 if((int)$db->query('PRAGMA user_version')->fetchColumn()>=2)return;
 transaction(function()use($db){
  if((int)$db->query('PRAGMA user_version')->fetchColumn()>=2)return;
  foreach(['users'=>['avatar','banner','bio','pronouns'],'servers'=>['icon','banner','description','owner_color','moderator_color','member_color'],'members'=>['nickname'],'channels'=>['topic']] as $table=>$columns)
   foreach($columns as $column)$db->exec("ALTER TABLE $table ADD COLUMN $column TEXT NOT NULL DEFAULT ''");
  $db->exec("CREATE TABLE media(id TEXT PRIMARY KEY,user_id INTEGER REFERENCES users(id),server_id TEXT REFERENCES servers(id) ON DELETE CASCADE,slot TEXT NOT NULL,data TEXT NOT NULL,UNIQUE(user_id,slot),UNIQUE(server_id,slot)); PRAGMA user_version=2;");
 });
}
function color_field(array $in,string $key): string {
 $color=$in[$key]??'';if(!is_string($color)||($color!==''&&!preg_match('/^#[0-9a-fA-F]{6}$/D',$color)))fail(400,'Choose a valid role color.');return $color;
}
function customization(string $action,array $in): array {
 switch($action){
 case 'profile':
  global $user;
  sql('UPDATE users SET name=?,bio=?,pronouns=? WHERE id=?',[text_field($in,'name',1,32),text_field($in+['bio'=>$user['bio']],'bio',0,190),text_field($in+['pronouns'=>$user['pronouns']],'pronouns',0,40),uid()]);return ['ok'=>true];
 case 'nickname':
  $sid=text_field($in,'server_id',32,32);member($sid);sql('UPDATE members SET nickname=? WHERE server_id=? AND user_id=?',[text_field($in,'nickname',0,32),$sid,uid()]);return ['ok'=>true];
 case 'server_settings':
  $sid=text_field($in,'server_id',32,32);require_permission($sid,'manage_server');
  sql('UPDATE servers SET name=?,description=?,owner_color=?,moderator_color=?,member_color=? WHERE id=?',[text_field($in,'name',1,40),text_field($in,'description',0,300),color_field($in,'owner_color'),color_field($in,'moderator_color'),color_field($in,'member_color'),$sid]);return ['ok'=>true];
 case 'channel_settings':
  $c=channel(text_field($in,'channel_id',32,32));if(!$c['server_id'])fail(400,'Choose a server channel.');require_permission($c['server_id'],'manage_channels');
  transaction(function()use($in,$c){
   $slow=$in['slowmode']??(int)$c['slowmode'];if(!is_int($slow)||$slow<0||$slow>21600)fail(400,'Slow mode must be 0–21600 seconds.');
   $private=$in['private']??(bool)$c['private'];$readonly=$in['readonly']??(bool)$c['readonly'];if(!is_bool($private)||!is_bool($readonly))fail(400,'Invalid channel access.');
   sql('UPDATE channels SET name=?,topic=?,category=?,slowmode=?,private=?,readonly=? WHERE id=?',[text_field($in,'name',1,32),text_field($in,'topic',0,500),text_field($in+['category'=>$c['category']],'category',0,40),$slow,(int)$private,(int)$readonly,$c['id']]);
   foreach(['members','roles'] as $kind)if(array_key_exists($kind,$in)){$values=$in[$kind];if(!is_array($values)||count($values)>100)fail(400,'Invalid access list.');if($kind==='members'){sql('DELETE FROM channel_members WHERE channel_id=?',[$c['id']]);foreach(array_unique([...$values,uid()]) as $id){positive_id($id);if(!one('SELECT 1 FROM members WHERE server_id=? AND user_id=?',[$c['server_id'],$id]))fail(400,'Choose server members.');sql('INSERT INTO channel_members VALUES(?,?)',[$c['id'],$id]);}}else{sql('DELETE FROM channel_roles WHERE channel_id=?',[$c['id']]);foreach(array_unique($values) as $id){if(!is_string($id)||!one('SELECT 1 FROM roles WHERE server_id=? AND id=?',[$c['server_id'],$id]))fail(400,'Choose server roles.');sql('INSERT INTO channel_roles VALUES(?,?)',[$c['id'],$id]);}}}
   sql('DELETE FROM calls WHERE channel_id=?',[$c['id']]);
  });return ['ok'=>true];
 case 'emoji':
  $sid=text_field($in,'server_id',32,32);member($sid);return ['emoji'=>sql("SELECT id,substr(slot,7) AS name FROM media WHERE server_id=? AND slot LIKE 'emoji:%' ORDER BY slot",[$sid])->fetchAll()];
 case 'media':
  $id=text_field($in,'id',32,32);$asset=one('SELECT * FROM media WHERE id=?',[$id]);if(!$asset)fail(404,'Image no longer available.');
  if($asset['server_id'])member($asset['server_id']);
  elseif((int)$asset['user_id']!==uid()&&!friends((int)$asset['user_id'])&&!one('SELECT 1 FROM members a JOIN members b ON a.server_id=b.server_id WHERE a.user_id=? AND b.user_id=?',[uid(),$asset['user_id']])&&!one('SELECT 1 FROM channel_members a JOIN channel_members b ON a.channel_id=b.channel_id JOIN channels c ON c.id=a.channel_id WHERE c.server_id IS NULL AND a.user_id=? AND b.user_id=?',[uid(),$asset['user_id']]))fail(403,'This image is private.');
  return ['data'=>$asset['data']];
 case 'upload_image':
  $slot=text_field($in,'slot',1,40);$sid=$in['server_id']??null;$owner=$sid===null?uid():null;
  if($sid!==null){$sid=text_field($in,'server_id',32,32);require_permission($sid,'manage_server');if(!in_array($slot,['icon','banner'],true)&&!preg_match('/^emoji:[a-z0-9_]{2,32}$/D',$slot))fail(400,'Use an emoji name with 2–32 lowercase letters, numbers or underscores.');}
  elseif(!in_array($slot,['avatar','banner'],true))fail(400,'Invalid profile image.');
  rate('image-upload:'.uid(),60,3600);$data=$in['data']??null;
  if(!is_string($data))fail(400,'Invalid image.');
  if($data!==''){
   if(strlen($data)>700000||!preg_match('#^data:image/(png|jpeg|webp|gif);base64,([A-Za-z0-9+/=]+)$#D',$data,$match))fail(400,'Choose a PNG, JPEG, WebP or GIF image up to 512 KB.');
   $bytes=base64_decode($match[2],true);$info=$bytes===false?false:@getimagesizefromstring($bytes);
   if(!$info||strlen($bytes)>524288||($info['mime']??'')!=='image/'.$match[1]||$info[0]<1||$info[1]<1||$info[0]>2048||$info[1]>2048||$info[0]*$info[1]>4194304)fail(400,'Choose an image up to 2048 × 2048 and 512 KB.');
  }
  return transaction(function()use($sid,$owner,$slot,$data){
   $existing=one('SELECT id FROM media WHERE user_id IS ? AND server_id IS ? AND slot=?',[$owner,$sid,$slot]);
   if($data!==''&&str_starts_with($slot,'emoji:')&&!$existing&&sql("SELECT count(*) FROM media WHERE server_id=? AND slot LIKE 'emoji:%'",[$sid])->fetchColumn()>=50)fail(409,'This server has reached its 50 custom emoji limit.');
   if($existing)sql('DELETE FROM media WHERE id=?',[$existing['id']]);
   $id=$data!==''?random_id():'';if($id)sql('INSERT INTO media VALUES(?,?,?,?,?)',[$id,$owner,$sid,$slot,$data]);
   if(!str_starts_with($slot,'emoji:')){if($sid)sql("UPDATE servers SET $slot=? WHERE id=?",[$id,$sid]);else sql("UPDATE users SET $slot=? WHERE id=?",[$id,uid()]);}
   return ['id'=>$id];
  });
 }
 fail(404,'Unknown customization action.');
}
