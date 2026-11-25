<?php
if(PHP_SAPI!=='cli'){http_response_code(404);exit;}
require __DIR__.'/../includes/load_config.php';require __DIR__.'/../includes/health.php';
$runtime=__DIR__.'/runtime/release-health-'.bin2hex(random_bytes(5));mkdir($runtime,0700,true);$path=$runtime.'/health.sqlite';
passthru(escapeshellarg(PHP_BINARY).' '.escapeshellarg(__DIR__.'/make_sqlite.php').' '.escapeshellarg($path),$code);if($code)exit($code);
$settings=load_config(dirname(__DIR__));putenv('BOOKTRACK_SQLITE='.$path);$before=hash_file('sha256',$path);$pass=$fail=0;
function verify_health(string $label,bool $ok):void{global $pass,$fail;$ok?$pass++:$fail++;echo ($ok?'PASS ':'FAIL ').$label.PHP_EOL;}
verify_health('installed database reports healthy',booktrack_health($settings));verify_health('health check leaves database bytes unchanged',hash_file('sha256',$path)===$before);
$database=new PDO('sqlite:'.$path);$database->exec('DROP TABLE users');$database=null;verify_health('missing application schema reports unavailable',!booktrack_health($settings));
putenv('BOOKTRACK_SQLITE='.$runtime.'/missing.sqlite');verify_health('missing database reports unavailable without creating a file',!booktrack_health($settings)&&!file_exists($runtime.'/missing.sqlite'));
$source=file_get_contents(__DIR__.'/../health.php');verify_health('health endpoint does not load session or mutation bootstrap',!str_contains($source,"includes/bootstrap.php")&&!str_contains($source,'session_start'));
echo "$pass passed, $fail failed\n";exit($fail?1:0);
