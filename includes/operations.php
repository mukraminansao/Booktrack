<?php
/** Request gate for recovery; serialized writes for this small-library application. */
class LibraryBusy extends DomainException {}
function operation_identity(): string
{
    return substr(hash('sha256',config('db.driver').':'.(config('db.driver')==='sqlite'?config('db.sqlite_path'):config('db.host').':'.config('db.port').':'.config('db.name'))),0,32);
}
function operation_file(string $kind)
{
    $dir=(string)config('operations.lock_directory',APP_ROOT.'/storage/locks');
    if(!is_dir($dir)&&!mkdir($dir,0700,true))throw new RuntimeException('The library operation lock is unavailable.');
    $file=fopen($dir.'/'.operation_identity().'-'.$kind.'.lock','c');
    if(!$file)throw new RuntimeException('The library operation lock is unavailable.');
    return $file;
}
function operation_flock($file,int $mode):void
{
    $until=microtime(true)+(float)config('operations.wait_seconds',10);
    do{if(flock($file,$mode|LOCK_NB))return;usleep(20000);}while(microtime(true)<$until);
    throw new LibraryBusy('The library is completing another operation. Please try again shortly.');
}
function library_gate(bool $exclusive=false):void
{
    if(isset($GLOBALS['__bt_gate']))return;
    $file=operation_file('recovery');operation_flock($file,$exclusive?LOCK_EX:LOCK_SH);
    $GLOBALS['__bt_gate']=$file;$GLOBALS['__bt_gate_exclusive']=$exclusive;
}
function with_library_exclusive(callable $fn)
{
    if(!empty($GLOBALS['__bt_gate_exclusive']))return $fn();
    if(!empty($GLOBALS['__bt_write_depth']))throw new DomainException('Start recovery from the dedicated restore command or page.');
    $file=$GLOBALS['__bt_gate']??operation_file('recovery');flock($file,LOCK_UN);
    try{operation_flock($file,LOCK_EX);$GLOBALS['__bt_gate']=$file;$GLOBALS['__bt_gate_exclusive']=true;return $fn();}
    finally{flock($file,LOCK_UN);$GLOBALS['__bt_gate_exclusive']=false;operation_flock($file,LOCK_SH);$GLOBALS['__bt_gate']=$file;}
}
function library_write_enter():void
{
    $depth=(int)($GLOBALS['__bt_write_depth']??0);if($depth){$GLOBALS['__bt_write_depth']=$depth+1;return;}
    $file=operation_file('writes');operation_flock($file,LOCK_EX);
    try{
        if(db_is_mysql()){
            $statement=db()->prepare('SELECT GET_LOCK(?,10)');$statement->execute(['booktrack:'.operation_identity()]);
            if((int)$statement->fetchColumn()!==1)throw new LibraryBusy('The library is completing another operation. Please try again shortly.');
        }
        $GLOBALS['__bt_write_file']=$file;$GLOBALS['__bt_write_depth']=1;
    }catch(Throwable $e){flock($file,LOCK_UN);fclose($file);throw $e;}
}
function library_write_leave():void
{
    $depth=(int)($GLOBALS['__bt_write_depth']??0);if(!$depth)return;
    if($depth>1){$GLOBALS['__bt_write_depth']=$depth-1;return;}
    try{if(db_is_mysql()){ $statement=db()->prepare('SELECT RELEASE_LOCK(?)');$statement->execute(['booktrack:'.operation_identity()]); }}
    catch(Throwable $e){error_log('BookTrack lock release: '.$e->getMessage());}
    $GLOBALS['__bt_write_depth']=0;flock($GLOBALS['__bt_write_file'],LOCK_UN);fclose($GLOBALS['__bt_write_file']);unset($GLOBALS['__bt_write_file']);
}
function with_library_write(callable $fn)
{
    library_write_enter();try{return $fn();}finally{library_write_leave();}
}
register_shutdown_function(function():void{
    while(!empty($GLOBALS['__bt_write_depth']))library_write_leave();
    if(isset($GLOBALS['__bt_gate'])){flock($GLOBALS['__bt_gate'],LOCK_UN);fclose($GLOBALS['__bt_gate']);}
});
