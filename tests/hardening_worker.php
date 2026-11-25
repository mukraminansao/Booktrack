<?php
if(PHP_SAPI!=='cli'){http_response_code(404);exit;}
$payload=json_decode($argv[1]??'',true,32,JSON_THROW_ON_ERROR);$sqlite=getenv('BOOKTRACK_SQLITE');$mysql=getenv('BOOKTRACK_DB_NAME');
if(!($sqlite&&str_starts_with(str_replace('\\','/',realpath($sqlite)?:''),str_replace('\\','/',__DIR__).'/runtime/'))&&!preg_match('/^booktrack_hardening_test_[a-f0-9]{8}$/',(string)$mysql))exit(2);
require __DIR__.'/../includes/bootstrap.php';
if(($payload['action']??'')==='exclusive'){
    with_library_exclusive(function()use($payload){file_put_contents($payload['ready'],'ready');$end=microtime(true)+5;while(!is_file($payload['go'])&&microtime(true)<$end)usleep(10000);});echo json_encode(['ok'=>true]);exit;
}
file_put_contents($payload['ready'],'ready');$end=microtime(true)+10;while(!is_file($payload['go'])&&microtime(true)<$end)usleep(10000);
try{
    $result=match($payload['action']){
        'loan'=>create_loan($payload['user'],$payload['book']),
        'renew'=>renew_loan($payload['loan'],$payload['user']),
        'return'=>(function()use($payload){request_return($payload['loan'],$payload['user']);return true;})(),
        'undo'=>(function()use($payload){cancel_return($payload['loan'],$payload['user']);return true;})(),
        'hold'=>place_hold($payload['user'],$payload['book']),
        'cancel'=>(function()use($payload){cancel_hold($payload['hold'],$payload['user']);return true;})(),
        'checkin'=>complete_return($payload['loan']),
        'copies'=>(function()use($payload){$book=q_one('SELECT * FROM books WHERE id=?',[$payload['book']]);update_catalogue_book($payload['book'],[$book['title'],$book['author'],$book['category'],$book['isbn'],$book['published_year'],$payload['copies'],$book['description']],$book['cover'],$book['pdf']);return true;})(),
        'delete'=>delete_catalogue_book($payload['book']),
        default=>true,
    };echo json_encode(['ok'=>true,'result'=>$result]);
}catch(DomainException $error){echo json_encode(['ok'=>false,'error'=>$error->getMessage()]);}
