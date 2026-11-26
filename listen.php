<?php
require __DIR__ . '/includes/bootstrap.php'; require_login();
$book=find_book(input_int('id')); $media=$book ? media_for((int)$book['id']) : null;
if (!$book || !private_media_path($media['audio_file'] ?? null,'audio')) { flash('error','An audiobook recording has not been added for this title yet.');redirect($book?'book.php':'discover.php',$book?['id'=>$book['id']]:[]); }
$title='Listening to '.$book['title'];$active='discover';$bodyClass='listening-page';require __DIR__.'/includes/partials/header.php';
?>
<?= navigation_back_html('book.php?id='.(int)$book['id'],'Back to book') ?>
<header class="page-head"><div class="page-head__title"><h1><?= e($book['title']) ?></h1><p><?= e($book['author']) ?><?= $media['narrator'] ? ' · Narrated by '.e($media['narrator']) : '' ?></p></div></header>
<?= audiobook_html($book,true) ?>
<?php require __DIR__.'/includes/partials/footer.php'; ?>
