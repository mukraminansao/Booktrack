<?php
require __DIR__ . '/../includes/bootstrap.php';
$user = require_login();
$bookId = input_int('book_id'); $medium = input('medium','ebook');
if (!find_book($bookId) || !in_array($medium,['ebook','audio'],true)) json_out(['ok'=>false,'error'=>'Book or format not found.'],404);
if (is_post()) {
    verify_csrf();
    try {
        if(input('revision')===''||!hash_equals(reading_revision($bookId,$medium),input('revision')))json_out(['ok'=>false,'error'=>'This book file changed or the reader is outdated. Refresh before continuing.'],409);
        if(in_array(input('action'),['progress','bookmark'],true)){
            $book=find_book($bookId);$relative=$medium==='ebook'?$book['pdf']:(media_for($bookId)['audio_file']??null);
            if(!private_media_path($relative,$medium==='ebook'?'pdfs':'audio'))throw new DomainException('This file is unavailable. Open the book details or contact the library.');
        }
        if (input('action') === 'bookmark') save_reading_bookmark((int)$user['id'],$bookId,$medium,input_int('position'),input('label'));
        elseif (input('action') === 'remove_bookmark') q('DELETE FROM reading_bookmarks WHERE id=? AND user_id=? AND book_id=? AND medium=?',[input_int('bookmark_id'),(int)$user['id'],$bookId,$medium]);
        elseif (input('action') === 'progress') {
            if(input('progress_version')===''||!hash_equals(progress_version((int)$user['id'],$bookId,$medium),input('progress_version')))json_out(['ok'=>false,'error'=>'Your reading position changed in another tab or device. Refresh to use the latest saved place.'],409);
            foreach (['position','total','rate'] as $key) if (!is_numeric(input($key, $key==='rate' ? '1' : '0'))) throw new DomainException('Invalid reading position.');
            $bound=media_bound($bookId,$medium);
            if($bound!==null&&abs((float)input('total')-$bound)>($medium==='ebook'?0:1))throw new DomainException('The file length changed. Refresh to load the current file.');
            save_reading_progress((int)$user['id'],$bookId,$medium,(float)input('position'),(float)input('total'),input('completed')==='1',(float)input('rate','1'));
        } else throw new DomainException('Choose a valid reading action.');
    } catch (DomainException $e) { json_out(['ok'=>false,'error'=>$e->getMessage()],422); }
} elseif (($_SERVER['REQUEST_METHOD'] ?? '') !== 'GET') json_out(['ok'=>false,'error'=>'Method not allowed.'],405);
header('Cache-Control: no-store');
json_out(with_library_write(fn()=>['ok'=>true,'revision'=>reading_revision($bookId,$medium),'progress_version'=>progress_version((int)$user['id'],$bookId,$medium),'progress'=>progress_for((int)$user['id'],$bookId,$medium),'bookmarks'=>bookmarks_for((int)$user['id'],$bookId,$medium)]));
