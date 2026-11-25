<?php
/** Portable, data-only backups. No SQL or executable code from an archive is run. */
function backup_tables(): array
{
    return ['users','books','collections','app_settings','book_media','book_chapters','collection_books','loans','favorites','chat_messages','messages','activity_log','login_attempts','holds','reviews','reminder_log','fines','two_factor','password_resets','reading_progress','reading_bookmarks'];
}
function backup_directory(): string
{
    $dir=(string)config('backups.directory',APP_ROOT.'/storage/backups');
    if(!is_dir($dir)&&!mkdir($dir,0700,true))throw new RuntimeException('Could not create the backup folder.');
    return $dir;
}
/** Only temporary review uploads are eligible; saved/safety backups are never pruned. */
function backup_discard_review(string $name): void
{
    if(!preg_match('/^pending-[a-f0-9]{32}\.tar$/D',$name))return;
    $path=backup_directory().'/'.$name;
    if(is_file($path)&&!is_link($path)&&!unlink($path))throw new RuntimeException('Could not remove the temporary review.');
}
function backup_cleanup_reviews(?int $now=null): void
{
    $cutoff=($now??time())-1800;
    foreach(glob(backup_directory().'/pending-*.tar')?:[] as $path){
        if(!preg_match('/^pending-[a-f0-9]{32}\.tar$/D',basename($path))||is_link($path)||!is_file($path))continue;
        if(filemtime($path)<=$cutoff)@unlink($path);
    }
}
function backup_columns(string $table): array
{
    if(!in_array($table,backup_tables(),true))throw new DomainException('Unknown backup table.');
    try{return db_is_mysql()?array_column(q_all("SHOW COLUMNS FROM `$table`"),'Field'):array_column(q_all("PRAGMA table_info(\"$table\")"),'name');}
    catch(PDOException $e){return [];}
}
function backup_media_name(string $path): bool
{
    return (bool)preg_match('~^uploads/(?:covers/[A-Za-z0-9_-]+\.(?:jpg|jpeg|png|webp)|pdfs/[A-Za-z0-9_-]+\.pdf|audio/[A-Za-z0-9_-]+\.(?:mp3|m4a|ogg|wav))$~D',$path);
}
function backup_media_path(string $relative): string
{
    if(!backup_media_name($relative))throw new DomainException('Invalid attachment path.');
    $base=rtrim((string)config('backups.upload_root',APP_ROOT.'/uploads'),'/\\');$target=$base.'/'.substr($relative,8);
    $root=realpath($base);$parent=realpath(dirname($target));
    if(!$root||is_link($base)||is_link(dirname($target))||is_link($target)||($parent&&dirname($parent)!==$root))throw new DomainException('The attachment folder is not safe for recovery.');
    return $target;
}
function backup_create(string $purpose='manual'): string
{
    return function_exists('with_library_write')?with_library_write(fn()=>backup_create_locked($purpose)):backup_create_locked($purpose);
}
function backup_create_locked(string $purpose):string
{
    @set_time_limit(300);
    $path=backup_directory().'/backup-'.date('Ymd-His').'-'.bin2hex(random_bytes(6)).'.tar';
    $tables=transaction(function(){ $tables=[];foreach(backup_tables() as $table){$columns=backup_columns($table);if($columns)$tables[$table]=['columns'=>$columns,'rows'=>q_all("SELECT * FROM `$table`")];}return $tables; });
    $database=json_encode($tables,JSON_THROW_ON_ERROR|JSON_UNESCAPED_UNICODE|JSON_INVALID_UTF8_SUBSTITUTE);
    if(strlen($database)>50*1024*1024)throw new RuntimeException('The database is too large for this backup tool.');
    try{
        $tar=new PharData($path);$tar->addFromString('database.json',$database);$files=[];$bytes=strlen($database);
        foreach(['covers','pdfs','audio'] as $folder){
            foreach(glob(rtrim((string)config('backups.upload_root',APP_ROOT.'/uploads'),'/\\').'/'.$folder.'/*') ?: [] as $full){
                $relative='uploads/'.$folder.'/'.basename($full);
                if(!is_file($full)||is_link($full)||!backup_media_name($relative))continue;
                $bytes+=filesize($full);
                if(count($files)>=20000||$bytes>(int)config('backups.max_mb',512)*1024*1024)throw new RuntimeException('This backup exceeds the configured size or file-count limit.');
                $files[]=['path'=>$relative,'bytes'=>filesize($full),'sha256'=>hash_file('sha256',$full)];$tar->addFile($full,$relative);
            }
        }
        $included=array_fill_keys(array_column($files,'path'),true);
        foreach(['books'=>['cover','pdf'],'book_media'=>['audio_file']] as $table=>$columns)foreach($tables[$table]['rows']??[] as $row)foreach($columns as $column){$file=$row[$column];if($file!==null&&$file!==''&&!isset($included[$file]))throw new DomainException('A library attachment is missing. Re-upload or remove its reference before creating a backup.');}
        $manifest=['format'=>'booktrack-data','version'=>1,'purpose'=>$purpose,'created_at'=>date(DATE_ATOM),'database_sha256'=>hash('sha256',$database),'files'=>$files];
        if(array_filter($tables['two_factor']['rows']??[],fn($row)=>str_starts_with($row['secret'],'enc1:')))$manifest['encryption_key_id']=installation_key_id();
        $tar->addFromString('manifest.json',json_encode($manifest,JSON_THROW_ON_ERROR));unset($tar);
        return $path;
    }catch(Throwable $e){unset($tar);if(is_file($path))unlink($path);throw $e;}
}
function backup_validate(string $path): array
{
    if(!is_file($path)||filesize($path)>(int)config('backups.max_mb',512)*1024*1024+4*1024*1024)throw new DomainException('The backup is missing or too large.');
    try{$tar=new PharData($path);}catch(Throwable $e){throw new DomainException('Choose a valid BookTrack TAR backup.');}
    if(!isset($tar['manifest.json'],$tar['database.json'])||$tar['manifest.json']->isLink()||$tar['database.json']->isLink()||!$tar['manifest.json']->isFile()||!$tar['database.json']->isFile()||$tar['manifest.json']->getSize()>5*1024*1024||$tar['database.json']->getSize()>50*1024*1024)throw new DomainException('The backup is missing its manifest or database.');
    try{$manifest=json_decode($tar['manifest.json']->getContent(),true,64,JSON_THROW_ON_ERROR);$database=$tar['database.json']->getContent();$tables=json_decode($database,true,64,JSON_THROW_ON_ERROR);}catch(Throwable $e){throw new DomainException('The backup metadata is damaged.');}
    if(($manifest['format']??'')!=='booktrack-data'||($manifest['version']??0)!==1||!is_array($manifest['files']??null)||!is_array($tables)||!hash_equals((string)($manifest['database_sha256']??''),hash('sha256',$database)))throw new DomainException('The backup is incompatible or its checksum does not match.');
    if(isset($manifest['encryption_key_id'])&&!hash_equals($manifest['encryption_key_id'],installation_key_id()))throw new DomainException('This backup requires its original installation encryption key. Restore that key separately before recovery.');
    if(count($manifest['files'])>20000)throw new DomainException('Too many files in this backup.');
    $allowed=['manifest.json'=>true,'database.json'=>true];$bytes=strlen($database);
    foreach($manifest['files'] as $file){
        if(!is_array($file)||!is_string($file['path']??null)||!backup_media_name($file['path'])||isset($allowed[$file['path']]))throw new DomainException('The backup contains an invalid file path.');
        $relative=$file['path'];$allowed[$relative]=true;
        if(!isset($tar[$relative])||$tar[$relative]->isLink()||!$tar[$relative]->isFile()||$tar[$relative]->getSize()!==($file['bytes']??-1))throw new DomainException('A backup file is missing or damaged.');
        $bytes+=$file['bytes'];if($bytes>(int)config('backups.max_mb',512)*1024*1024)throw new DomainException('The expanded backup is too large.');
        if(!hash_equals((string)($file['sha256']??''),hash_file('sha256',$tar[$relative]->getPathName())))throw new DomainException('A backup file checksum does not match.');
    }
    $prefix='phar://'.str_replace('\\','/',$tar->getPath()).'/';
    foreach(new RecursiveIteratorIterator($tar) as $item){$name=str_replace('\\','/',$item->getPathName());if(!str_starts_with($name,$prefix)||$item->isLink()||!isset($allowed[substr($name,strlen($prefix))]))throw new DomainException('The archive contains unexpected files.');}
    $counts=[];
    foreach($tables as $table=>$data){
        if(!is_string($table)||!in_array($table,backup_tables(),true)||!is_array($data)||!is_array($data['columns']??null)||!is_array($data['rows']??null))throw new DomainException('Invalid database backup.');
        $columns=backup_columns($table);
        if(!$columns||$columns!==$data['columns'])throw new DomainException('The backup database schema is not compatible with this installation.');
        if(count($data['rows'])>250000)throw new DomainException('The backup contains too many records.');
        foreach($data['rows'] as $row){if(!is_array($row)||array_keys($row)!==$columns)throw new DomainException('A database row is malformed.');foreach($row as $value)if(!is_null($value)&&!is_scalar($value))throw new DomainException('A database field is malformed.');}
        $counts[$table]=count($data['rows']);
    }
    foreach(backup_tables() as $table)if(backup_columns($table)&&!isset($tables[$table]))throw new DomainException('The backup does not include every required table.');
    if(!array_filter($tables['users']['rows']??[],fn($u)=>$u['role']==='admin'&&(int)$u['is_active']===1))throw new DomainException('The backup must contain an active librarian account.');
    foreach(['books'=>['cover','pdf'],'book_media'=>['audio_file']] as $table=>$columns)foreach($tables[$table]['rows']??[] as $row)foreach($columns as $column){$file=$row[$column];if($file!==null&&$file!==''&&(!backup_media_name($file)||!isset($allowed[$file])))throw new DomainException('A book attachment is missing from this backup.');}
    return ['manifest'=>$manifest,'tables'=>$tables,'counts'=>$counts,'bytes'=>$bytes];
}
function backup_restore(string $path, bool $createSafety = true): ?string
{
    return function_exists('with_library_exclusive')?with_library_exclusive(fn()=>with_library_write(fn()=>backup_restore_locked($path,$createSafety))):backup_restore_locked($path,$createSafety);
}
function backup_restore_locked(string $path,bool $createSafety):?string
{
    @set_time_limit(300);$data=backup_validate($path);$safety=$createSafety?backup_create('safety'):null;
    $tar=new PharData($path);$staging=backup_directory().'/stage-'.bin2hex(random_bytes(8));mkdir($staging,0700,true);$changed=[];$keepStage=false;
    try{
        foreach($data['manifest']['files'] as $file){$relative=$file['path'];$temp=$staging.'/'.$relative;if(!is_dir(dirname($temp)))mkdir(dirname($temp),0700,true);if(!@copy($tar[$relative]->getPathName(),$temp))throw new RuntimeException('Could not stage a backup file.');}
        transaction(function()use($data,$staging,&$changed){
            foreach(array_reverse(backup_tables()) as $table)if(backup_columns($table))q("DELETE FROM `$table`");
            foreach(backup_tables() as $table){if(!isset($data['tables'][$table]))continue;$content=$data['tables'][$table];$columns=implode(',',array_map(fn($c)=>'`'.$c.'`',$content['columns']));$sql="INSERT INTO `$table` ($columns) VALUES (".implode(',',array_fill(0,count($content['columns']),'?')).')';foreach($content['rows'] as $row)q($sql,array_values($row));}
            foreach($data['manifest']['files'] as $file){
                $relative=$file['path'];$target=backup_media_path($relative);
                if(is_file($target)&&hash_file('sha256',$target)===$file['sha256'])continue;
                $old=null;$oldHash=null;if(is_file($target)){$oldHash=hash_file('sha256',$target);$old=$staging.'/rollback/'.$relative;if(!is_dir(dirname($old)))mkdir(dirname($old),0700,true);if(!@copy($target,$old)||hash_file('sha256',$old)!==$oldHash)throw new RuntimeException('Could not preserve an existing attachment.');}
                if(!is_dir(dirname($target)))mkdir(dirname($target),0755,true);$changed[]=['target'=>$target,'old'=>$old,'hash'=>$oldHash];
                if(!@copy($staging.'/'.$relative,$target)||hash_file('sha256',$target)!==$file['sha256'])throw new RuntimeException('Could not restore and verify an attachment.');
            }
            reading_upsert('app_settings',['setting_key'=>'session_generation'],['setting_value'=>bin2hex(random_bytes(24))]);
        });
    }catch(Throwable $e){foreach(array_reverse($changed) as $file){if($file['old']){if(!@copy($file['old'],$file['target'])||@hash_file('sha256',$file['target'])!==$file['hash'])$keepStage=true;}elseif(is_file($file['target'])&&!@unlink($file['target']))$keepStage=true;}
        if($keepStage){error_log('BookTrack recovery material retained: '.$staging.'; safety: '.($safety??'not requested'));throw new RuntimeException('Recovery could not roll back every file. Recovery material is retained; contact the administrator.',0,$e);}throw $e;}
    finally { if(!$keepStage)backup_remove_stage($staging); }
    return $safety;
}
function backup_remove_stage(string $path): void
{
    $root=realpath(backup_directory());$resolved=realpath($path);
    if(!$resolved||!$root||dirname($resolved)!==$root||!preg_match('/^stage-[a-f0-9]{16}$/',basename($resolved)))throw new RuntimeException('Invalid staging path.');
    foreach(new RecursiveIteratorIterator(new RecursiveDirectoryIterator($resolved,FilesystemIterator::SKIP_DOTS),RecursiveIteratorIterator::CHILD_FIRST) as $item){if($item->isDir()&&!$item->isLink())rmdir($item->getPathname());else unlink($item->getPathname());}rmdir($resolved);
}
