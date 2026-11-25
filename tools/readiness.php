<?php
/** Read-only checks. This command neither enables production nor sends email. */
if(PHP_SAPI!=='cli'){http_response_code(404);exit;}
define('APP_ROOT',dirname(__DIR__));require APP_ROOT.'/includes/load_config.php';$settings=load_config(APP_ROOT);
$publicDemo=!empty($settings['demo']['enabled'])&&($settings['environment']??'')!=='production';
$checks=[];$add=function(string $name,bool $ok,string $detail)use(&$checks){$checks[]=['check'=>$name,'ok'=>$ok,'detail'=>$detail];};
$add('deployment profile',$publicDemo||($settings['environment']??'')==='production','Use the public demo profile for disposable demo records, or the production profile for a real library.');
$url=parse_url($settings['app_url']);$add('HTTPS canonical address',($url['scheme']??'')==='https'&&!empty($url['host'])&&!str_ends_with($url['host'],'.example.com'),'Configure the real HTTPS app_url and certificate on the host.');
$add('error details hidden',!$settings['debug'],'Production must hide debug output.');
$add('account access profile',$publicDemo||(!$settings['auth']['show_demo_logins']&&!$settings['demo']['enabled']),'One-click accounts are intentional in the public demo; they must be disabled for a real library.');
$db=$settings['db'];$web=realpath($settings['security']['web_root']??APP_ROOT);$outsideWeb=function($path)use($web){$real=realpath($path);return $real&&$web&&!str_starts_with(strtolower(str_replace('\\','/',$real)),strtolower(str_replace('\\','/',$web)).'/');};
$databasePrivate=$db['driver']==='sqlite'?(is_file($db['sqlite_path'])&&$outsideWeb($db['sqlite_path'])):(strtolower($db['user'])!=='root'&&strlen($db['pass'])>=16&&!str_starts_with($db['pass'],'REPLACE-'));
$add('private database configuration',$databasePrivate,'Use a SQLite file outside the web root, or a separate restricted MySQL account. Host file permissions/database grants require separate inspection.');
$smtp=$settings['mail']['smtp']??[];$add('mail policy',($publicDemo&&$settings['mail']['driver']==='off')||($settings['mail']['driver']==='smtp'&&!empty($smtp['host'])&&!empty($smtp['username'])&&!empty($smtp['password'])&&in_array($smtp['encryption']??'',['tls','ssl'],true)&&($smtp['verify_peer']??true)),'Public demo email stays off. A real library requires SMTP and confirmed recipient delivery; this command sends nothing.');
$key=$settings['security']['key_file']??'';$resolved=$key!==''?realpath($key):false;
$environmentKey=base64_decode((string)getenv('BOOKTRACK_ENCRYPTION_KEY'),true);
$add('separate encryption key',($environmentKey!==false&&strlen($environmentKey)===32)||($resolved&&is_file($resolved)&&filesize($resolved)===32&&$web&&!str_starts_with(strtolower(str_replace('\\','/',$resolved)),strtolower(str_replace('\\','/',$web)).'/')),'Supply a 32-byte base64 BOOKTRACK_ENCRYPTION_KEY or keep BOOKTRACK_KEY_FILE outside the web root. Keep a separate secure key copy.');
$copy=$settings['backups']['copy_directory']??'';$add('backup policy',$publicDemo||($copy!==''&&is_dir($copy)),'Disposable demo records reset on restart. A real library needs an off-server copy and recovery drill.');
try{
    $pdo=new PDO($db['driver']==='sqlite'?'sqlite:'.$db['sqlite_path']:sprintf('mysql:host=%s;port=%d;dbname=%s;charset=utf8mb4',$db['host'],$db['port'],$db['name']),$db['user'],$db['pass'],[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION]);
    $unsafe=[];foreach($pdo->query('SELECT username,password_hash FROM users WHERE is_active=1') as $user)foreach(['admin123','member123','password123'] as $password)if(password_verify($password,$user['password_hash'])){$unsafe[]=$user['username'];break;}
    $add('account credential policy',$publicDemo||!$unsafe,$publicDemo?'Known one-click credentials are intentional; use only disposable demo records.':($unsafe?'Change or deactivate seeded accounts: '.implode(', ',$unsafe):'No known seeded password found.'));
    $missing=(int)$pdo->query("SELECT COUNT(*) FROM users u WHERE u.role='admin' AND u.is_active=1 AND NOT EXISTS(SELECT 1 FROM two_factor t WHERE t.user_id=u.id)")->fetchColumn();
    $add('librarian two-step sign-in',$publicDemo||($missing===0&&$settings['auth']['require_2fa_for_librarians']),'The shared demo librarian is intentionally accessible. Every real-library librarian must enroll before enforcement.');
}catch(Throwable $e){$add('database inspection',false,'Could not complete read-only account checks. Check database connectivity and schema.');}
$ok=!array_filter($checks,fn($check)=>!$check['ok']);echo json_encode(['ready'=>$ok,'purpose'=>$publicDemo?'public-demo':'real-library','production_library_ready'=>$ok&&!$publicDemo,'checks'=>$checks,'external_validation'=>$publicDemo?['HTTPS/site headers','Browser acceptance','Disposable demo records only']:['HTTPS/certificate and web access restrictions','SMTP delivery to a real recipient','Off-server backup restore and key recovery','Host permissions, monitoring and multi-browser acceptance']],JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES).PHP_EOL;exit($ok?0:2);
