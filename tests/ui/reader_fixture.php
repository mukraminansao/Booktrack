<?php
/** Original PDF for UI checks, restricted to the disposable UI database. */
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
require __DIR__ . '/../reading_fixtures.php';
$root=dirname(__DIR__,2);
$db=realpath(getenv('BOOKTRACK_SQLITE')?:'');
$runtime=realpath(__DIR__.'/../runtime');
if(!$db||!$runtime||!str_starts_with($db,$runtime.DIRECTORY_SEPARATOR)||!str_ends_with($db,'.sqlite'))throw new RuntimeException('An isolated UI database is required.');
$pdo=new PDO('sqlite:'.$db);$pdo->setAttribute(PDO::ATTR_ERRMODE,PDO::ERRMODE_EXCEPTION);
if(($argv[1]??'')==='cleanup'){
    $state=json_decode($argv[2]??'',true,512,JSON_THROW_ON_ERROR);
    $file=$state['file']??'';
    if(!preg_match('~^uploads/pdfs/ui-reader-[a-f0-9]{20}\.pdf$~',$file))throw new RuntimeException('Not an owned UI fixture.');
    $query=$pdo->prepare('UPDATE books SET pdf=? WHERE id=15 AND pdf=?');$query->execute([$state['previous']??null,$file]);
    if(is_file($root.'/'.$file))unlink($root.'/'.$file);
    exit;
}
$previous=$pdo->query('SELECT pdf FROM books WHERE id=15')->fetchColumn();
$file='uploads/pdfs/ui-reader-'.bin2hex(random_bytes(10)).'.pdf';
if(!is_dir($root.'/uploads/pdfs'))mkdir($root.'/uploads/pdfs',0775,true);
reading_test_pdf($root.'/'.$file,3);
$query=$pdo->prepare('UPDATE books SET pdf=? WHERE id=15');$query->execute([$file]);
echo json_encode(['file'=>$file,'previous'=>$previous]);
