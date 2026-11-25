<?php
/** Failure, security and real multi-process races; every database is disposable. */
if(PHP_SAPI!=='cli'){http_response_code(404);exit;}
$runtime=__DIR__.'/runtime/hardening-'.bin2hex(random_bytes(5));mkdir($runtime,0700,true);$mysql=getenv('BOOKTRACK_TEST_MYSQL')==='1';$server=null;$name=null;
if($mysql){
    require __DIR__.'/../includes/load_config.php';require __DIR__.'/../includes/installer.php';$settings=load_config(dirname(__DIR__));$server=installer_server_pdo($settings['db']);$name='booktrack_hardening_test_'.bin2hex(random_bytes(4));install_database($server,$name);putenv('BOOKTRACK_DB_NAME='.$name);putenv('BOOKTRACK_SQLITE');
}else{$path=$runtime.'/test.sqlite';passthru(escapeshellarg(PHP_BINARY).' '.escapeshellarg(__DIR__.'/make_sqlite.php').' '.escapeshellarg($path),$code);if($code)exit($code);putenv('BOOKTRACK_SQLITE='.$path);}
putenv('BOOKTRACK_MAIL_DRIVER=off');require __DIR__.'/../includes/bootstrap.php';require __DIR__.'/../includes/backups.php';require __DIR__.'/../includes/backup_encryption.php';require __DIR__.'/reading_fixtures.php';
$GLOBALS['__config']['security']['key_file']=$runtime.'/installation.key';$GLOBALS['__config']['backups']=['directory'=>$runtime.'/backups','upload_root'=>$runtime.'/uploads','max_mb'=>32];
foreach(['covers','pdfs','audio'] as $folder)mkdir($runtime.'/uploads/'.$folder,0700,true);
reading_test_pdf($runtime.'/uploads/pdfs/test.pdf');reading_test_audio($runtime.'/uploads/audio/test.wav');q('UPDATE books SET cover=NULL,pdf=NULL');q('UPDATE books SET pdf=? WHERE id=7',['uploads/pdfs/test.pdf']);reading_upsert('book_media',['book_id'=>7],['audio_file'=>'uploads/audio/test.wav','narrator'=>'Test']);
$pass=$fail=0;
function check(string $name,bool $ok):void{global $pass,$fail;$ok?$pass++:$fail++;echo ($ok?'PASS ':'FAIL ').$name.PHP_EOL;}
function rejected(callable $fn):bool{try{$fn();return false;}catch(Throwable $error){return true;}}
function launch(array $payload):array{
    $pipes=[];$process=proc_open([PHP_BINARY,__DIR__.'/hardening_worker.php',json_encode($payload)], [0=>['pipe','r'],1=>['pipe','w'],2=>['pipe','w']],$pipes,dirname(__DIR__));fclose($pipes[0]);return [$process,$pipes];
}
function finish(array $worker):array{[$process,$pipes]=$worker;$out=stream_get_contents($pipes[1]);$err=stream_get_contents($pipes[2]);fclose($pipes[1]);fclose($pipes[2]);$code=proc_close($process);if($code!==0)throw new RuntimeException('Worker failed: '.$err);return json_decode($out,true,32,JSON_THROW_ON_ERROR);}
function race(array $actions):array{
    global $runtime;$group=$runtime.'/race-'.bin2hex(random_bytes(3));mkdir($group);$workers=[];
    foreach($actions as $i=>$payload)$workers[]=launch($payload+['ready'=>$group.'/'.$i,'go'=>$group.'/go']);
    $end=microtime(true)+10;do{$ready=count(glob($group.'/*')?:[]);if($ready===count($workers))break;usleep(10000);}while(microtime(true)<$end);
    file_put_contents($group.'/go','go');return array_map('finish',$workers);
}
function successes(array $results):int{return count(array_filter($results,fn($result)=>$result['ok']));}
try{
    $version=edit_version('books',7);q('UPDATE books SET description=? WHERE id=7',['changed by another librarian']);$_POST=['version'=>$version];check('stale book edits are rejected',rejected(fn()=>check_edit_version('books',7)));$_POST=[];
    $version=progress_version(2,7,'ebook');save_reading_progress(2,7,'ebook',2,3,false);check('progress version changes after a save',$version!==progress_version(2,7,'ebook'));
    save_reading_bookmark(2,7,'ebook',2,'Old edition');replace_chapters(7,'ebook',[['title'=>'Old','position'=>2]]);remember_media_bound(7,'ebook',3);
    transaction(function(){reading_replace_file(7,'ebook');q('UPDATE books SET pdf=? WHERE id=7',['uploads/pdfs/replacement.pdf']);});
    check('replacement resets old progress bookmarks chapters and bounds',progress_for(2,7,'ebook')===null&&bookmarks_for(2,7,'ebook')===[]&&chapters_for(7,'ebook')===[]&&media_bound(7,'ebook')===null);q('UPDATE books SET pdf=? WHERE id=7',['uploads/pdfs/test.pdf']);
    check('PDF chapters cannot exceed actual page count',rejected(fn()=>chapters_within_file([['position'=>4]],'ebook',3)));
    check('audio chapters cannot start at or beyond the end',rejected(fn()=>chapters_within_file([['position'=>12]],'audio',12)));
    check('chapter markers require verified file metadata',rejected(fn()=>chapters_within_file([['position'=>1]],'ebook',null)));
    $secret=totp_new_secret();two_factor_enable(2,$secret,totp_code($secret,intdiv(time(),TOTP_STEP)));$stored=q_val('SELECT secret FROM two_factor WHERE user_id=2');
    check('TOTP secret is encrypted in the database',str_starts_with($stored,'enc1:')&&!str_contains($stored,$secret));check('encrypted secret decrypts for verification',two_factor_for(2)['secret']===$secret);
    check('encrypted secrets are bound to their account',rejected(fn()=>secret_open($stored,'totp:3')));
    q("DELETE FROM app_settings WHERE setting_key='totp_encryption_schema'");q('UPDATE two_factor SET secret=? WHERE user_id=2',[$secret]);two_factor_encrypt_legacy();check('upgrade encrypts all previously stored TOTP secrets',str_starts_with(q_val('SELECT secret FROM two_factor WHERE user_id=2'),'enc1:'));
    $damaged=$stored;$damaged[strlen($damaged)-4]=$damaged[strlen($damaged)-4]==='A'?'B':'A';check('tampered secrets are rejected',rejected(fn()=>secret_open($damaged,'totp:2')));
    q('UPDATE two_factor SET secret=? WHERE user_id=2',[$secret]);two_factor_for(2);check('legacy plaintext secrets migrate on access',str_starts_with(q_val('SELECT secret FROM two_factor WHERE user_id=2'),'enc1:'));
    sign_in_as(2);$old=$_SESSION;revoke_user_sessions(2,true);check('password changes can keep the current session',current_user(true)!==null);$_SESSION=$old;check('older sessions are revoked',current_user(true)===null);
    two_factor_begin(2);revoke_user_sessions(2);check('revocation also cancels pending two-step sign-in',two_factor_pending()===null);
    sign_in_as(3);$resetToken=bin2hex(random_bytes(32));q('INSERT INTO password_resets (user_id,token_hash,ip,created_at,expires_at) VALUES (?,?,?,?,?)',[3,hash('sha256',$resetToken),'test',date('Y-m-d H:i:s'),date('Y-m-d H:i:s',time()+1800)]);$errors=complete_password_reset($resetToken,'reset-test-password','reset-test-password');check('email reset completion revokes an existing session',$errors===[]&&current_user(true)===null);
    $localSnapshot=$GLOBALS['__config'];$oldEnvironment=getenv('BOOKTRACK_ENV');putenv('BOOKTRACK_ENV=production');$production=load_config(APP_ROOT);putenv($oldEnvironment===false?'BOOKTRACK_ENV':'BOOKTRACK_ENV='.$oldEnvironment);check('production profile enforces security without changing local defaults',!$production['debug']&&!$production['auth']['show_demo_logins']&&!$production['demo']['enabled']&&$production['security']['require_https']&&$production['auth']['require_2fa_for_librarians']&&$GLOBALS['__config']===$localSnapshot);
    $archive=backup_create();$encrypted=$runtime.'/archive.btenc';$restored=$runtime.'/decrypted.tar';$password='test-only backup password 1234';backup_crypt_file($archive,$encrypted,$password);backup_crypt_file($encrypted,$restored,$password,true);
    check('encrypted backup round trip preserves every byte',hash_file('sha256',$archive)===hash_file('sha256',$restored));
    check('wrong password rejects backup and removes partial output',rejected(fn()=>backup_crypt_file($encrypted,$runtime.'/wrong.tar','wrong test-only password',true))&&!file_exists($runtime.'/wrong.tar'));
    $truncated=$runtime.'/truncated.btenc';file_put_contents($truncated,substr(file_get_contents($encrypted),0,-32));check('truncated encrypted backup cannot decrypt',rejected(fn()=>backup_crypt_file($truncated,$runtime.'/truncated.tar',$password,true))&&!file_exists($runtime.'/truncated.tar'));
    check('encryption never overwrites an existing destination',rejected(fn()=>backup_crypt_file($archive,$encrypted,$password))&&is_file($encrypted));
    mkdir($runtime.'/separate-copy');$copy=backup_copy_encrypted($encrypted,$runtime.'/separate-copy');check('separate backup copy is byte verified',hash_file('sha256',$copy)===hash_file('sha256',$encrypted));check('separate copy failure retains the local encrypted archive',rejected(fn()=>backup_copy_encrypted($encrypted,$runtime.'/missing-copy'))&&is_file($encrypted));
    $large=$runtime.'/multi-chunk.bin';file_put_contents($large,random_bytes(2097203));backup_crypt_file($large,$runtime.'/multi.btenc',$password);backup_crypt_file($runtime.'/multi.btenc',$runtime.'/multi.bin',$password,true);check('multi-chunk backup round trip preserves chunk boundaries',hash_file('sha256',$large)===hash_file('sha256',$runtime.'/multi.bin'));
    $wrongKey=$runtime.'/wrong-installation.key';$GLOBALS['__config']['security']['key_file']=$wrongKey;check('restore refuses encrypted TOTP data with a different key',rejected(fn()=>backup_validate($archive)));$GLOBALS['__config']['security']['key_file']=$runtime.'/installation.key';
    $safety=backup_create('safety');touch($archive,time()-86400*60);touch($safety,time()-86400*60);$fresh=backup_create();$plan=backup_retention_plan(1,30);
    check('retention includes old manual archives and protects safety copies',in_array($archive,$plan,true)&&!in_array($safety,$plan,true)&&!in_array($fresh,$plan,true));
    file_put_contents($runtime.'/uploads/pdfs/test.pdf','Before failed recovery');unlink($runtime.'/uploads/audio/test.wav');mkdir($runtime.'/uploads/audio/test.wav');$titleBefore=q_val('SELECT title FROM books WHERE id=7');$restoreFailed=rejected(fn()=>backup_restore($archive,false));
    check('attachment copy failure rolls back database and verifies original file bytes',$restoreFailed&&q_val('SELECT title FROM books WHERE id=7')===$titleBefore&&file_get_contents($runtime.'/uploads/pdfs/test.pdf')==='Before failed recovery');rmdir($runtime.'/uploads/audio/test.wav');reading_test_audio($runtime.'/uploads/audio/test.wav');reading_test_pdf($runtime.'/uploads/pdfs/test.pdf');
    $oldTitle=q_val('SELECT title FROM books WHERE id=7');$trigger=$mysql?"CREATE TRIGGER hardening_log BEFORE INSERT ON activity_log FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='test log failure'":"CREATE TRIGGER hardening_log BEFORE INSERT ON activity_log BEGIN SELECT RAISE(ABORT,'test log failure'); END";db()->exec($trigger);
    $failed=rejected(fn()=>transaction(function(){q("UPDATE books SET title='Uncommitted' WHERE id=7");log_activity('book.updated','fault');}));db()->exec('DROP TRIGGER hardening_log');check('failed activity logging rolls back the database save',$failed&&q_val('SELECT title FROM books WHERE id=7')===$oldTitle);
    // Fresh circulation state: children execute the same application rules in separate processes.
    q('DELETE FROM fines');q('DELETE FROM holds');q('DELETE FROM loans');q('UPDATE books SET copies=1');
    $results=race([['action'=>'loan','user'=>2,'book'=>7],['action'=>'loan','user'=>3,'book'=>7]]);check('two workers cannot borrow the same final copy',successes($results)===1&&(int)q_val("SELECT COUNT(*) FROM loans WHERE book_id=7 AND status='borrowed'")===1);
    q('DELETE FROM loans');create_loan(2,1);create_loan(2,2);$results=race([['action'=>'loan','user'=>2,'book'=>7],['action'=>'loan','user'=>2,'book'=>8]]);check('member loan limit survives different-book races',successes($results)===1&&active_loan_count(2)===3);
    $loan=(int)q_val('SELECT id FROM loans WHERE user_id=2 ORDER BY id DESC LIMIT 1');$due=find_loan($loan)['due_at'];$results=race([['action'=>'renew','loan'=>$loan,'user'=>2],['action'=>'renew','loan'=>$loan,'user'=>2]]);check('concurrent renewals extend only once',successes($results)===1&&(int)find_loan($loan)['renewals']===1&&find_loan($loan)['due_at']===date('Y-m-d',strtotime($due.' +7 days')));
    $results=race([['action'=>'return','loan'=>$loan,'user'=>2],['action'=>'return','loan'=>$loan,'user'=>2]]);check('concurrent return requests record only one transition',successes($results)===1);
    $results=race([['action'=>'undo','loan'=>$loan,'user'=>2],['action'=>'checkin','loan'=>$loan]]);check('check-in wins over a competing undo safely',find_loan($loan)['status']==='returned'&&$results[1]['ok']);
    $book=9;create_loan(3,$book);$results=race([['action'=>'hold','user'=>4,'book'=>$book],['action'=>'hold','user'=>4,'book'=>$book]]);check('concurrent holds cannot duplicate a queue entry',successes($results)===1&&(int)q_val("SELECT COUNT(*) FROM holds WHERE user_id=4 AND book_id=? AND status='waiting'",[$book])===1);
    $hold=(int)q_val('SELECT id FROM holds WHERE user_id=4 AND book_id=?',[$book]);$results=race([['action'=>'cancel','user'=>4,'hold'=>$hold],['action'=>'cancel','user'=>4,'hold'=>$hold]]);check('concurrent hold cancellations commit only once',successes($results)===1);
    q('DELETE FROM loans WHERE user_id=5');q('UPDATE books SET copies=2 WHERE id=10');$results=race([['action'=>'copies','book'=>10,'copies'=>1],['action'=>'loan','user'=>5,'book'=>10],['action'=>'loan','user'=>6,'book'=>10]]);
    check('copy edits cannot reduce stock below concurrent borrowing',(int)q_val('SELECT copies FROM books WHERE id=10')>=(int)q_val('SELECT COUNT(*) FROM loans WHERE book_id=10'));
    $results=race([['action'=>'delete','book'=>11],['action'=>'loan','user'=>5,'book'=>11]]);check('delete versus borrowing preserves referential and stock rules',successes($results)===1&&(!find_book(11)?!(int)q_val('SELECT COUNT(*) FROM loans WHERE book_id=11'):(int)q_val('SELECT COUNT(*) FROM loans WHERE book_id=11')===1));
    $gate=$GLOBALS['__bt_gate'];flock($gate,LOCK_UN);$go=$runtime.'/recovery-go';$ready=$runtime.'/recovery-ready';$exclusive=launch(['action'=>'exclusive','ready'=>$ready,'go'=>$go]);$end=microtime(true)+5;while(!is_file($ready)&&microtime(true)<$end)usleep(10000);
    $waitingReady=$runtime.'/writer-ready';$waiting=launch(['action'=>'noop','ready'=>$waitingReady,'go'=>$go]);usleep(200000);$blocked=!file_exists($waitingReady);file_put_contents($go,'go');finish($exclusive);finish($waiting);operation_flock($gate,LOCK_SH);check('recovery gate prevents normal requests from entering mid-restore',$blocked);
}finally{if($server&&$name&&preg_match('/^booktrack_hardening_test_[a-f0-9]{8}$/',$name))$server->exec('DROP DATABASE `'.$name.'`');}
echo "$pass passed, $fail failed (".($mysql?'MySQL/MariaDB':'SQLite').")\n";exit($fail?1:0);
