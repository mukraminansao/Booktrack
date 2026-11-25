<?php
require __DIR__.'/includes/bootstrap.php';require_login();
$collection=q_one('SELECT * FROM collections WHERE id=? AND is_active=1',[input_int('id')]);
if (!$collection) {flash('error','This collection is not available.');redirect('discover.php');}
$books=q_all('SELECT '.book_columns().' FROM '.books_from().' JOIN collection_books cb ON cb.book_id=b.id WHERE cb.collection_id=? ORDER BY cb.position,b.title',[(int)$collection['id']]);
$title=$collection['title'];$active='discover';$bodyClass='collection-page';require __DIR__.'/includes/partials/header.php';
?>
<a class="back-link" href="<?= e(url('discover.php')) ?>"><?= icon('arrow-left') ?> Back to Discover</a>
<header class="page-head"><div class="page-head__title"><h1><?= e($collection['title']) ?></h1><p><?= e($collection['subtitle']) ?> · <?= e(plural(count($books),'book')) ?></p></div></header>
<?php if ($books): ?><div class="book-grid"><?php foreach ($books as $book): require __DIR__.'/includes/partials/book_card.php'; endforeach; ?></div><?php else: ?><div class="empty"><?= empty_art('shelf') ?><h2>A collection in the making</h2><p>New titles will appear here when the librarian adds them.</p></div><?php endif; ?>
<?php require __DIR__.'/includes/partials/footer.php'; ?>
