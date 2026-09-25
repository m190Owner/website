<?php
// CLI only; uses a unique temporary DB and never reads production settings.
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
$tmp = sys_get_temp_dir().'/m190-broadcast-'.bin2hex(random_bytes(6));
mkdir($tmp);
define('BC_TEST_DB',$tmp.'/test.sqlite'); define('BC_TEST_UPLOAD_DIR',$tmp);
require __DIR__.'/../owner/lib/broadcast.php';
function check(bool $ok, string $message): void { if (!$ok) throw new RuntimeException($message); }
function rejects(callable $fn): void { try { $fn(); } catch (RuntimeException $e) { return; } throw new RuntimeException('Expected rejection'); }
$id = str_repeat('a',32);
rejects(fn()=>bc_validate('shell',['id'=>$id]));
rejects(fn()=>bc_validate('stop',['id'=>'../config.php']));
check(bc_validate('interrupt',['sessionId'=>$id,'itemId'=>$id,'clipId'=>$id])['clipId']===$id,'valid command');
$command = bc_enqueue('stop',['id'=>$id]);
$reply = bc_exchange(['sessions'=>[], 'clips'=>[], 'csrf'=>'must-not-store'], []);
check(count($reply['commands'])===1,'command delivered');
check(count(bc_exchange([],[])['commands'])===0,'command never replayed');
check(!str_contains(bc_db()->query('SELECT payload FROM state')->fetchColumn(),'must-not-store'),'secret field excluded');
bc_exchange([], [['id'=>$command,'ok'=>true,'message'=>'Stopped']]);
check(bc_db()->query("SELECT status FROM commands WHERE id='$command'")->fetchColumn()==='done','result acknowledged');
$expired=bc_enqueue('stop',['id'=>$id]); bc_db()->exec("UPDATE commands SET expires=0 WHERE id='$expired'");
check(count(bc_exchange([],[])['commands'])===0,'stale commands expire');
rejects(fn()=>bc_upload_begin('too-big.mp4',BC_MAX_BYTES+1));
$upload=bc_upload_begin('clip.mp4',6);
rejects(fn()=>bc_upload_chunk($upload,3,'abc'));
check(bc_upload_chunk($upload,0,'abc')===3,'first chunk accepted');
rejects(fn()=>bc_upload_finish($upload));
check(bc_upload_chunk($upload,3,'def')===6,'second chunk accepted');
$uploadCommand=bc_upload_finish($upload);
rejects(fn()=>bc_upload_finish($upload));
$reply=bc_exchange([],[]); check($reply['commands'][0]['args']['uploadId']===$upload,'upload queued');
bc_exchange([], [['id'=>$uploadCommand,'ok'=>true,'message'=>'Clip ready']]);
check(!is_file(bc_upload_path($upload)),'staging bytes removed after acknowledgement');
echo "PASS: validation, at-most-once delivery, expiry, result acknowledgement, chunk ordering, upload completion and cleanup\n";
// Files are confined to the test's freshly created temporary directory.
register_shutdown_function(function() use($tmp) { foreach(glob($tmp.'/*') ?: [] as $f) @unlink($f); @rmdir($tmp); });
