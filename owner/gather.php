<?php
declare(strict_types=1);
require __DIR__.'/lib/owner_auth.php';
owner_require();
header('Cache-Control: no-store');header('X-Robots-Tag: noindex, nofollow');header('X-Content-Type-Options: nosniff');header("Content-Security-Policy: default-src 'none'; style-src 'self'; form-action 'self'; frame-ancestors 'none'; base-uri 'none'");
$dir=dirname(__DIR__,2).'/gather-private';$path=$dir.'/config.php';$config=is_file($path)?require $path:[];$notice='';
if($_SERVER['REQUEST_METHOD']==='POST'){
 owner_csrf_require();
 try{
  $id=trim((string)($_POST['key_id']??''));$token=trim((string)($_POST['token']??''));$owners=strtolower(trim((string)($_POST['owners']??'')));
  if($id!==''&&!preg_match('/^[a-zA-Z0-9_-]{16,128}$/',$id))throw new RuntimeException('Invalid TURN key ID.');
  if($token!==''&&(strlen($token)<20||strlen($token)>512||preg_match('/\s/',$token)))throw new RuntimeException('Invalid TURN key API token.');
  $list=$owners===''?[]:array_map('trim',explode(',',$owners));foreach($list as $owner)if($owner!=='*'&&!preg_match('/^[a-z0-9_]{3,24}$/',$owner))throw new RuntimeException('Use Gather usernames separated by commas, or * for every server owner.');
  if($id!=='')$config['cloudflare_key_id']=$id;if($token!=='')$config['cloudflare_token']=$token;$config['relay_owners']=$list;
  if(!is_dir($dir)&&!mkdir($dir,0700,true))throw new RuntimeException('Cannot create private storage.');
  $tmp=$path.'.'.bin2hex(random_bytes(6)).'.tmp';$content="<?php\nreturn ".var_export($config,true).";\n";
  if(file_put_contents($tmp,$content,LOCK_EX)!==strlen($content)||!chmod($tmp,0600)||!rename($tmp,$path))throw new RuntimeException('Could not save relay settings.');
  $notice='Settings saved. Secrets are stored outside public_html and are never included in the executable.';
 }catch(Throwable $e){$notice=$e->getMessage();}
}
?>
<!doctype html><html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>Gather · private host settings</title><link rel="stylesheet" href="/owner/gather.css"></head><body><main><h1>Gather host settings</h1><p>This private page has no link from your website. Gather chat and accounts use your existing hosting; Cloudflare relays call media.</p><p role="status"><?=oe($notice)?></p><p>Relay key: <?=empty($config['cloudflare_token'])?'not configured':'saved (hidden)'?></p><form method="post"><?=owner_csrf_field()?><label for="key_id">Cloudflare TURN key ID</label><input id="key_id" name="key_id" maxlength="128" value="<?=oe((string)($config['cloudflare_key_id']??''))?>"><label for="token">TURN key API token — leave blank to preserve the saved token</label><input id="token" type="password" name="token" autocomplete="off" maxlength="512"><label for="owners">Approved server owners (Gather usernames, comma-separated)</label><input id="owners" name="owners" value="<?=oe(implode(', ',$config['relay_owners']??[]))?>"><p>Only servers owned by these users can use the relay. Leave empty to disable new calls; * enables all owners. This controls access, not Cloudflare billing. Monitor usage in Cloudflare; no spending cap is enforced here.</p><button type="submit">Save privately</button></form><p>Use your existing owner-console login and 2FA. Never put the relay token in GitHub, the chat app, or a URL.</p></main></body></html>
