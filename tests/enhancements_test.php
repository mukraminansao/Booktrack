<?php
/** Isolated reading, media, migration and recovery regression tests. */
if(PHP_SAPI!=='cli'){http_response_code(404);exit;}
$runtime=__DIR__.'/runtime/unit-'.bin2hex(random_bytes(5));mkdir($runtime,0700,true);
$mysql=getenv('BOOKTRACK_TEST_MYSQL')==='1';$server=null;$testDatabase=null;
if($mysql){
 define('APP_ROOT',dirname(__DIR__));require APP_ROOT.'/includes/load_config.php';$GLOBALS['__config']=load_config(APP_ROOT);
 function config(string $key,$default=null){$value=$GLOBALS['__config'];foreach(explode('.',$key) as $part){if(!is_array($value)||!array_key_exists($part,$value))return $default;$value=$value[$part];}return $value;}
 require APP_ROOT.'/includes/installer.php';$server=installer_server_pdo(config('db'));$testDatabase='booktrack_enhancements_test_'.bin2hex(random_bytes(4));install_database($server,$testDatabase);$GLOBALS['__config']['db']['name']=$testDatabase;
 foreach(['db','helpers','auth','holds','library','reviews','reading','demo','operations','security','two_factor'] as $include)require_once APP_ROOT.'/includes/'.$include.'.php';library_gate();reading_ensure_schema();two_factor_ensure_schema();two_factor_secret_schema();
}else{
 $path=$runtime.'/unit.sqlite';passthru(escapeshellarg(PHP_BINARY).' '.escapeshellarg(__DIR__.'/make_sqlite.php').' '.escapeshellarg($path),$exit);if($exit)exit($exit);putenv('BOOKTRACK_SQLITE='.$path);require __DIR__.'/../includes/bootstrap.php';
}
require_once APP_ROOT.'/includes/backups.php';require __DIR__.'/reading_fixtures.php';
$GLOBALS['__config']['backups']=['directory'=>$runtime.'/backups','upload_root'=>$runtime.'/uploads','max_mb'=>32];
foreach(['covers','pdfs','audio'] as $folder)mkdir($runtime.'/uploads/'.$folder,0700,true);
reading_test_pdf($runtime.'/uploads/pdfs/test.pdf');reading_test_audio($runtime.'/uploads/audio/test.wav');
q('UPDATE books SET cover=NULL,pdf=NULL');q('UPDATE books SET pdf=? WHERE id=7',['uploads/pdfs/test.pdf']);reading_upsert('book_media',['book_id'=>7],['audio_file'=>'uploads/audio/test.wav','narrator'=>'Test narrator']);
$pass=$fail=0;
function check(string $name,bool $ok):void{global $pass,$fail;$ok?$pass++:$fail++;echo ($ok?'PASS ':'FAIL ').$name.PHP_EOL;}
function rejects(callable $fn):bool{try{$fn();return false;}catch(DomainException $e){return true;}}
function edited_archive(string $source,callable $edit):string{$path=dirname($source).'/variant-'.bin2hex(random_bytes(5)).'.tar';copy($source,$path);$tar=new PharData($path);$edit($tar);unset($tar);return $path;}
function edit_database(PharData $tar,callable $edit):void{$tables=json_decode($tar['database.json']->getContent(),true);$edit($tables);$json=json_encode($tables);$manifest=json_decode($tar['manifest.json']->getContent(),true);$manifest['database_sha256']=hash('sha256',$json);$tar['database.json']=$json;$tar['manifest.json']=json_encode($manifest);}
try{
 $count=(int)q_val('SELECT COUNT(*) FROM books');reading_ensure_schema();check('additive migration is repeatable and keeps catalogue rows',(int)q_val('SELECT COUNT(*) FROM books')===$count);
 $p=save_reading_progress(2,7,'ebook',2,3,false);check('PDF page is saved',(int)$p['position']===2&&!(int)$p['completed']);
 check('reading position is account scoped',progress_for(3,7,'ebook')===null);
 $p=save_reading_progress(2,7,'ebook',20,3,false);check('page is clamped to document length',(int)$p['position']===3);
 check('negative progress rejected',rejects(fn()=>save_reading_progress(2,7,'ebook',-1,3,false)));
 check('non-finite progress rejected',rejects(fn()=>save_reading_progress(2,7,'audio',NAN,12,false)));
 check('zero page count rejected',rejects(fn()=>save_reading_progress(2,7,'ebook',2,0,false)));
 check('non-finite playback rate rejected',rejects(fn()=>save_reading_progress(2,7,'audio',2,12,false,NAN)));
 check('unknown format rejected',rejects(fn()=>save_reading_progress(2,7,'video',1,2,false)));
 check('missing book rejected',rejects(fn()=>save_reading_progress(2,99999,'ebook',1,2,false)));
 check('book without PDF rejected',rejects(fn()=>save_reading_progress(2,6,'ebook',1,2,false)));
 $p=save_reading_progress(2,7,'ebook',2,3,true);check('finish status saved',(int)$p['completed']===1);check('finished books leave Continue reading',continue_reading(2,$runtime)===null);
 save_reading_progress(2,7,'ebook',2,3,false);check('unfinished book is resumed',(int)continue_reading(2,$runtime)['id']===7);
 $p=save_reading_progress(2,7,'audio',5.75,12,false,1.5);check('audio keeps exact position and speed',abs((float)$p['position']-5.75)<.001&&(float)$p['rate']===1.5);
 $p=save_reading_progress(2,7,'audio',5,12,false,10);check('playback speed bounded',(float)$p['rate']===2.0);
 q('UPDATE books SET pdf=? WHERE id=6',['uploads/pdfs/unavailable.pdf']);save_reading_progress(2,6,'ebook',1,3,false);q('UPDATE reading_progress SET updated_at=? WHERE book_id=6',['2099-01-01 00:00:00']);
 check('resume skips a newer missing file',(int)continue_reading(2,$runtime)['id']===7);
 q('UPDATE books SET pdf=? WHERE id=7',['uploads/pdfs/unavailable.pdf']);q('UPDATE book_media SET audio_file=? WHERE book_id=7',['uploads/audio/unavailable.wav']);check('resume is empty when no saved format is available',continue_reading(2,$runtime)===null);
 q('UPDATE books SET pdf=NULL WHERE id=6');q('DELETE FROM reading_progress WHERE book_id=6');q('UPDATE books SET pdf=? WHERE id=7',['uploads/pdfs/test.pdf']);q('UPDATE book_media SET audio_file=? WHERE book_id=7',['uploads/audio/test.wav']);
 save_reading_bookmark(2,7,'ebook',2,'Page 2');save_reading_bookmark(2,7,'ebook',2,'My passage');check('same-position bookmarks update instead of duplicate',count(bookmarks_for(2,7,'ebook'))===1&&bookmarks_for(2,7,'ebook')[0]['label']==='My passage');
 check('bookmarks are private to the member',bookmarks_for(3,7,'ebook')===[]);
 check('empty bookmark label rejected',rejects(fn()=>save_reading_bookmark(2,7,'ebook',1,'')));
 check('invalid bookmark position rejected',rejects(fn()=>save_reading_bookmark(2,7,'ebook',0,'No page')));
 check('audio clock parses correctly',chapter_position('1:03:45','audio')===3825.0);
 check('long minute timestamps are accepted',chapter_position('90:00','audio')===5400.0);
 check('long audiobook clocks do not wrap after 24 hours',reading_clock(90061)==='25:01:01');
 check('chapter clocks retain hours minutes and seconds',reading_clock(5400)==='01:30:00');
 check('overflowing clock minutes are rejected',rejects(fn()=>chapter_position('1:60:00','audio')));
 check('page fractions rejected',rejects(fn()=>chapter_position('1.5','ebook')));
 check('duplicate chapter times rejected',rejects(fn()=>validate_chapters(['A','B'],['0','0'],'audio')));
 $titles=array_fill(0,200,'Chapter');$positions=array_map('strval',range(1,200));check('exactly 200 chapter markers are supported',count(validate_chapters($titles,$positions,'ebook'))===200);
 check('more than 200 markers are rejected',rejects(fn()=>validate_chapters([...$titles,'Extra'],[...$positions,'201'],'ebook')));
 $rows=validate_chapters(['Later','Earlier'],['6','0'],'audio');replace_chapters(7,'audio',$rows);check('chapters sorted in playback order',chapters_for(7,'audio')[0]['title']==='Earlier');
 $archive=backup_create();$data=backup_validate($archive);check('backup has database and original attachments',($data['counts']['books']??0)===16&&count($data['manifest']['files'])===2);
 check('backup files have verified checksums',strlen($data['manifest']['files'][0]['sha256'])===64);
 $expired='pending-'.bin2hex(random_bytes(16)).'.tar';$fresh='pending-'.bin2hex(random_bytes(16)).'.tar';
 file_put_contents(backup_directory().'/'.$expired,'expired');touch(backup_directory().'/'.$expired,time()-1900);file_put_contents(backup_directory().'/'.$fresh,'current');
 backup_cleanup_reviews();check('expired restore reviews are removed',!is_file(backup_directory().'/'.$expired));check('active reviews and saved backups are retained',is_file(backup_directory().'/'.$fresh)&&is_file($archive));
 backup_discard_review('../'.basename($archive));check('review cancellation cannot remove saved backups',is_file($archive));backup_discard_review($fresh);check('review cancellation removes only its temporary file',!is_file(backup_directory().'/'.$fresh));
 check('traversal paths rejected',!backup_media_name('uploads/pdfs/../config.php'));
 check('executable files rejected',!backup_media_name('uploads/covers/evil.php'));
 $bad=edited_archive($archive,fn($tar)=>$tar->addFromString('config.php','<?php'));check('unexpected archive entries rejected',rejects(fn()=>backup_validate($bad)));
 $bad=edited_archive($archive,fn($tar)=>$tar->addFromString('uploads/audio/test.wav','changed'));check('corrupt attachments rejected',rejects(fn()=>backup_validate($bad)));
 $bad=edited_archive($archive,function($tar){$m=json_decode($tar['manifest.json']->getContent(),true);$m['files'][0]['path']='uploads/pdfs/../../config.php';$tar['manifest.json']=json_encode($m);});check('manifest traversal rejected',rejects(fn()=>backup_validate($bad)));
 $bad=edited_archive($archive,function($tar){edit_database($tar,function(&$tables){$tables['users']['rows']=array_values(array_filter($tables['users']['rows'],fn($u)=>$u['role']!=='admin'));});});check('restore requires a usable librarian account',rejects(fn()=>backup_validate($bad)));
 q("UPDATE books SET title='Changed before restore' WHERE id=7");file_put_contents($runtime.'/uploads/pdfs/test.pdf','changed');
 $safety=backup_restore($archive);check('restore recovers database rows',q_val('SELECT title FROM books WHERE id=7')==='Atomic Habits');check('restore recovers attachment bytes',str_starts_with(file_get_contents($runtime.'/uploads/pdfs/test.pdf'),'%PDF-'));
 check('restore creates a safety backup',$safety!==null&&is_file($safety));check('safety backup is valid',backup_validate($safety)['counts']['books']===16);
 check('restore invalidates earlier account sessions',strlen(library_setting('session_generation'))===48);
 check('restore recovers reading progress',(int)progress_for(2,7,'ebook')['position']===2);
 check('restore recovers bookmarks',bookmarks_for(2,7,'ebook')[0]['label']==='My passage');
 $bad=edited_archive($archive,function($tar){edit_database($tar,function(&$tables){$tables['loans']['rows'][0]['user_id']=999999;});});
 $before=q_val('SELECT COUNT(*) FROM loans');$failed=false;try{backup_restore($bad,false);}catch(Throwable $e){$failed=true;}
 check('failed restore rolls back all database changes',$failed&&q_val('SELECT COUNT(*) FROM loans')===$before&&q_val('SELECT title FROM books WHERE id=7')==='Atomic Habits');
 q('UPDATE books SET pdf=? WHERE id=7',['uploads/pdfs/missing.pdf']);check('backup creation refuses missing referenced attachments',rejects(fn()=>backup_create()));
 q('DELETE FROM books WHERE id=7');check('book removal cascades progress, bookmarks, chapters and media',!q_val('SELECT COUNT(*) FROM reading_progress WHERE book_id=7')&&!q_val('SELECT COUNT(*) FROM reading_bookmarks WHERE book_id=7')&&!q_val('SELECT COUNT(*) FROM book_chapters WHERE book_id=7')&&!q_val('SELECT COUNT(*) FROM book_media WHERE book_id=7'));
}finally{
 if($server&&$testDatabase&&preg_match('/^booktrack_enhancements_test_[a-f0-9]{8}$/',$testDatabase))$server->exec('DROP DATABASE `'.$testDatabase.'`');
}
echo "$pass passed, $fail failed (".($mysql?'MySQL/MariaDB':'SQLite').")\n";exit($fail?1:0);
