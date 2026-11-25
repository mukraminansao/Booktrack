<?php
/** Rehearse recovery into another installation with a separately restored key. No live data. */
if(PHP_SAPI!=='cli'){http_response_code(404);exit;}
$targetMode=($argv[1]??'')==='target';
if($targetMode){
    $payloadPath=realpath($argv[2]??'');$testRoot=realpath(__DIR__.'/runtime');if(!$payloadPath||!$testRoot||!str_starts_with(str_replace('\\','/',$payloadPath),str_replace('\\','/',$testRoot).'/release-recovery-'))exit(2);
    $payload=json_decode(file_get_contents($payloadPath),true,32,JSON_THROW_ON_ERROR);putenv('BOOKTRACK_SQLITE='.$payload['database']);
}else{
    $runtime=__DIR__.'/runtime/release-recovery-'.bin2hex(random_bytes(5));mkdir($runtime,0700,true);
    foreach(['source','target'] as $installation){foreach(['covers','pdfs','audio'] as $folder)mkdir($runtime.'/'.$installation.'/uploads/'.$folder,0700,true);passthru(escapeshellarg(PHP_BINARY).' '.escapeshellarg(__DIR__.'/make_sqlite.php').' '.escapeshellarg($runtime.'/'.$installation.'.sqlite'),$code);if($code)exit($code);}
    putenv('BOOKTRACK_SQLITE='.$runtime.'/source.sqlite');
}
putenv('BOOKTRACK_MAIL_DRIVER=off');require __DIR__.'/../includes/bootstrap.php';require __DIR__.'/../includes/backups.php';require __DIR__.'/../includes/backup_encryption.php';require __DIR__.'/reading_fixtures.php';
$checks=[];function verify_drill(string $name,bool $ok):void{global $checks;$checks[]=['check'=>$name,'ok'=>$ok];}
q('UPDATE books SET cover=NULL,pdf=NULL');
if($targetMode){
    $GLOBALS['__config']['security']['key_file']=$payload['key'];$GLOBALS['__config']['backups']=['directory'=>$payload['root'].'/backups','upload_root'=>$payload['root'].'/uploads','max_mb'=>32];
    $plain=$payload['root'].'/recovery.tar';backup_crypt_file($payload['encrypted'],$plain,$payload['password'],true);
    $before=q_val('SELECT title FROM books WHERE id=7');$refused=false;try{backup_validate($plain);}catch(DomainException $error){$refused=true;}
    verify_drill('wrong installation key is refused before records change',$refused&&q_val('SELECT title FROM books WHERE id=7')===$before);
    copy($payload['source_key'],$payload['key']);$safety=backup_restore($plain);
    verify_drill('matching separately restored key permits recovery',q_val('SELECT title FROM books WHERE id=7')===$payload['title']);
    verify_drill('target creates a safety archive before replacement',is_file($safety)&&backup_validate($safety)['counts']['users']===6);
    $pdf=private_media_path('uploads/pdfs/drill.pdf','pdfs',$payload['root']);$audio=private_media_path('uploads/audio/drill.wav','audio',$payload['root']);
    verify_drill('PDF bytes recover into the other installation',$pdf&&hash_file('sha256',$pdf)===$payload['pdf_hash']);
    verify_drill('recording bytes recover into the other installation',$audio&&hash_file('sha256',$audio)===$payload['audio_hash']);
    verify_drill('member reading progress is recovered',(int)progress_for(2,7,'ebook')['position']===2);
    verify_drill('member bookmarks and chapters are recovered',count(bookmarks_for(2,7,'ebook'))===1&&count(chapters_for(7,'ebook'))===1);
    verify_drill('restored authenticator decrypts with the restored key',hash('sha256',two_factor_for(2)['secret'])===$payload['secret_hash']);
    verify_drill('restored one-time recovery code signs in once',two_factor_verify(2,$payload['recovery_code'])==='recovery'&&two_factor_verify(2,$payload['recovery_code'])===null);
    sign_in_as(2);verify_drill('restored member can establish a current session',(int)current_user(true)['id']===2);
    unlink($plain);echo json_encode($checks);exit(array_filter($checks,fn($c)=>!$c['ok'])?1:0);
}
$GLOBALS['__config']['security']['key_file']=$runtime.'/source-installation.key';$GLOBALS['__config']['backups']=['directory'=>$runtime.'/source/backups','upload_root'=>$runtime.'/source/uploads','max_mb'=>32];
$pdf=$runtime.'/source/uploads/pdfs/drill.pdf';$audio=$runtime.'/source/uploads/audio/drill.wav';reading_test_pdf($pdf);reading_test_audio($audio);
$title='Recovery drill '.bin2hex(random_bytes(6));q('UPDATE books SET title=?,pdf=? WHERE id=7',[$title,'uploads/pdfs/drill.pdf']);reading_upsert('book_media',['book_id'=>7],['audio_file'=>'uploads/audio/drill.wav','narrator'=>'Original fixture']);save_reading_progress(2,7,'ebook',2,3,false);save_reading_bookmark(2,7,'ebook',2,'Recovery passage');replace_chapters(7,'ebook',[['title'=>'Opening','position'=>1]]);
$secret=totp_new_secret();$codes=two_factor_enable(2,$secret,totp_code($secret,intdiv(time(),TOTP_STEP)));$archive=backup_create();$password=bin2hex(random_bytes(24));$encrypted=$runtime.'/source/export.btenc';backup_crypt_file($archive,$encrypted,$password);$copy=backup_copy_encrypted($encrypted,$runtime.'/target');
verify_drill('encrypted transfer copy is byte verified',hash_file('sha256',$encrypted)===hash_file('sha256',$copy));
$tar=new PharData($archive);$keyIncluded=false;foreach(new RecursiveIteratorIterator($tar) as $entry)if(str_ends_with($entry->getFilename(),'.key'))$keyIncluded=true;unset($tar);verify_drill('installation key is excluded from the data archive',!$keyIncluded);
$payload=['database'=>$runtime.'/target.sqlite','root'=>$runtime.'/target','key'=>$runtime.'/target-installation.key','source_key'=>$runtime.'/source-installation.key','encrypted'=>$copy,'password'=>$password,'title'=>$title,'pdf_hash'=>hash_file('sha256',$pdf),'audio_hash'=>hash_file('sha256',$audio),'secret_hash'=>hash('sha256',$secret),'recovery_code'=>$codes[0]];
$payloadPath=$runtime.'/payload.json';file_put_contents($payloadPath,json_encode($payload));$pipes=[];$worker=proc_open([PHP_BINARY,__FILE__,'target',$payloadPath],[0=>['pipe','r'],1=>['pipe','w'],2=>['pipe','w']],$pipes);fclose($pipes[0]);$output=stream_get_contents($pipes[1]);$error=stream_get_contents($pipes[2]);fclose($pipes[1]);fclose($pipes[2]);$code=proc_close($worker);unlink($payloadPath);
if($code){fwrite(STDERR,'Recovery worker failed: '.$error.PHP_EOL);exit(1);}
$checks=array_merge($checks,json_decode($output,true,32,JSON_THROW_ON_ERROR));
if(PHP_OS_FAMILY==='Windows'){
    // Real filesystem denial, confined to disposable files. A failed second copy makes
    // the already changed first destination read-only before rollback attempts it.
    file_put_contents($pdf,'Original bytes before rollback denial');unlink($audio);mkdir($audio);$denied=false;$retained=false;
    q('UPDATE books SET title=? WHERE id=7',['Before denied rollback']);
    set_error_handler(function($severity,$message)use(&$denied,$pdf){if(!$denied&&str_contains($message,'copy(')){$denied=chmod($pdf,0444);return true;}return false;});
    try{backup_restore($archive,false);}catch(RuntimeException $error){$retained=str_contains($error->getMessage(),'could not roll back every file');}
    finally{restore_error_handler();chmod($pdf,0644);}
    $stages=glob($runtime.'/source/backups/stage-*')?:[];
    verify_drill('OS write denial reports incomplete rollback and retains recovery material',$denied&&$retained&&count($stages)===1&&is_file($stages[0].'/rollback/uploads/pdfs/drill.pdf'));
    verify_drill('retained rollback material preserves original bytes and database state',count($stages)===1&&file_get_contents($stages[0].'/rollback/uploads/pdfs/drill.pdf')==='Original bytes before rollback denial'&&q_val('SELECT title FROM books WHERE id=7')==='Before denied rollback');
}
$failed=0;foreach($checks as $check){echo ($check['ok']?'PASS ':'FAIL ').$check['check'].PHP_EOL;if(!$check['ok'])$failed++;}echo (count($checks)-$failed)." passed, $failed failed\n";exit($failed?1:0);
