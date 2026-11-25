<?php
/** Streams a book's PDF to signed-in users only (the uploads/pdfs folder is not public). */
require __DIR__ . '/includes/bootstrap.php';
require_login();

$book = q_one('SELECT title, pdf FROM books WHERE id = ?', [input_int('id')]);
$path = $book ? private_media_path($book['pdf'],'pdfs') : null;

if (!$path) {
    http_response_code(404);
    exit('PDF not found.');
}

$name = preg_replace('/[^A-Za-z0-9 _-]+/', '', $book['title']) ?: 'book';
// The page-level security policy would stop the browser's built-in PDF viewer from loading.
header_remove('Content-Security-Policy');
header('Content-Disposition: inline; filename="' . $name . '.pdf"');
stream_private_file($path,'application/pdf');
