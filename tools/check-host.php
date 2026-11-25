<?php
/** Explicit-target, read-only deployment acceptance. Never submits forms or sends mail. */
if(PHP_SAPI!=='cli'){http_response_code(404);exit;}
$base=rtrim($argv[1]??'','/');$url=parse_url($base);$local=in_array(strtolower($url['host']??''),['localhost','127.0.0.1','::1'],true);
if(!$url||!in_array($url['scheme']??'',['http','https'],true)||empty($url['host'])||(isset($url['user'])||isset($url['pass']))||isset($url['query'])||isset($url['fragment'])||(($url['scheme']??'')!=='https'&&!($local&&in_array('--allow-local',$argv,true)))){fwrite(STDERR,"Usage: php tools/check-host.php https://your-host/booktrack [--allow-local for loopback HTTP]\n");exit(1);}
if(!extension_loaded('curl')){fwrite(STDERR,"PHP curl is required.\n");exit(1);}
function probe(string $url):array{
    $headers=[];$body='';$curl=curl_init($url);
    curl_setopt_array($curl,[CURLOPT_FOLLOWLOCATION=>false,CURLOPT_CONNECTTIMEOUT=>5,CURLOPT_TIMEOUT=>15,CURLOPT_SSL_VERIFYPEER=>true,CURLOPT_SSL_VERIFYHOST=>2,CURLOPT_PROTOCOLS=>CURLPROTO_HTTP|CURLPROTO_HTTPS,CURLOPT_USERAGENT=>'BookTrack-Host-Check/1',CURLOPT_HEADERFUNCTION=>function($c,$line)use(&$headers){if(str_contains($line,':')){[$key,$value]=explode(':',$line,2);$headers[strtolower(trim($key))]=trim($value);}return strlen($line);},CURLOPT_WRITEFUNCTION=>function($c,$chunk)use(&$body){if(strlen($body)+strlen($chunk)>262144)return 0;$body.=$chunk;return strlen($chunk);}]);
    curl_exec($curl);$result=['status'=>(int)curl_getinfo($curl,CURLINFO_RESPONSE_CODE),'milliseconds'=>(int)round(curl_getinfo($curl,CURLINFO_TOTAL_TIME)*1000),'transport_ok'=>curl_errno($curl)===0,'headers'=>$headers,'body'=>$body];curl_close($curl);return $result;
}
$checks=[];$add=function(string $check,bool $ok,array $evidence=[])use(&$checks){$checks[]=['check'=>$check,'ok'=>$ok]+$evidence;};
$login=probe($base.'/login.php');$add('sign-in page',$login['transport_ok']&&$login['status']===200&&str_contains($login['body'],'BookTrack'),['status'=>$login['status'],'milliseconds'=>$login['milliseconds']]);
$healthy=probe($base.'/health.php');$add('database health',$healthy['transport_ok']&&$healthy['status']===200&&(json_decode($healthy['body'],true)['status']??'')==='ok',['status'=>$healthy['status'],'milliseconds'=>$healthy['milliseconds']]);
foreach(['x-content-type-options'=>'nosniff','x-frame-options'=>'SAMEORIGIN'] as $key=>$value)$add('header '.$key,strcasecmp($login['headers'][$key]??'',$value)===0);
$add('content security policy',str_contains($login['headers']['content-security-policy']??'',"default-src 'self'"));
if(!$local)$add('HSTS',str_contains($login['headers']['strict-transport-security']??'','max-age='));
foreach(['config.php','config.local.php','config.production.example.php','database/booktrack.sql','storage/','includes/bootstrap.php','tools/readiness.php','tests/form_versions.py','deploy/booktrack-upload.zip','visual-review/hardening-readiness.json','_backups/'] as $path){$result=probe($base.'/'.$path);$add('protected '.$path,$result['transport_ok']&&in_array($result['status'],[403,404],true),['status'=>$result['status']]);}
$ok=!array_filter($checks,fn($c)=>!$c['ok']);echo json_encode(['passed'=>$ok,'target'=>$base,'checks'=>$checks,'limits'=>['A 403/404 on a missing file does not prove directory rules for every file.','TLS is verified by curl for this connection; host certificate renewal and external monitoring remain host responsibilities.','SMTP delivery and off-server recovery are separate checks.']],JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES).PHP_EOL;exit($ok?0:2);
