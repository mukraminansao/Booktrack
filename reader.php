<?php
require __DIR__ . '/includes/bootstrap.php';
$user=require_login(); $book=find_book(input_int('id'));
if (!$book || !private_media_path($book['pdf'] ?? null,'pdfs')) { flash('error','This e-book is not available yet.'); redirect($book ? 'book.php' : 'discover.php',$book ? ['id'=>$book['id']] : []); }
$progress=progress_for((int)$user['id'],(int)$book['id'],'ebook');
$ebookChapters=chapters_for((int)$book['id'],'ebook');
$title='Reading ' . $book['title']; $active='discover'; $bodyClass='reader-page';
require __DIR__ . '/includes/partials/header.php';
?>
<link rel="stylesheet" href="<?= e(asset('vendor/pdfjs/web/pdf_viewer.css')) ?>">
<link rel="stylesheet" href="<?= e(asset('css/reader.css')) ?>">
<?= navigation_back_html('book.php?id='.(int)$book['id'],'Back to book') ?>
<header class="page-head"><div class="page-head__title"><h1><?= e($book['title']) ?></h1><p><?= e($book['author']) ?> · Your place is saved to your account.</p></div><a class="btn btn--outline" href="<?= e(url('read.php',['id'=>$book['id']])) ?>" target="_blank" rel="noopener">Open original PDF</a></header>
<section class="reader" data-reader data-progress-version="<?= e(progress_version((int)$user['id'],(int)$book['id'],'ebook')) ?>" data-revision="<?= e(reading_revision((int)$book['id'],'ebook')) ?>" data-book-id="<?= (int)$book['id'] ?>" data-pdf="<?= e(url('read.php',['id'=>$book['id']])) ?>" data-api="<?= e(url('api/reading.php')) ?>" data-page="<?= max(1,(int)($progress['position'] ?? 1)) ?>" data-completed="<?= !empty($progress['completed']) ? '1':'0' ?>">
  <div class="reader-tools" role="group" aria-label="Reading options">
    <label class="reader-zoom-label">Zoom <select class="select" data-reader-zoom disabled><option value="page">Fit page</option><option value="fit">Fit width</option><option value=".8">80%</option><option value="1">100%</option><option value="1.25">125%</option><option value="1.5">150%</option></select></label>
    <button class="btn btn--outline" type="button" data-reader-bookmark disabled><?= icon('bookmark') ?> Bookmark</button>
    <button class="btn btn--outline" type="button" data-reader-finish disabled><?= !empty($progress['completed']) ? 'Read again' : 'Mark finished' ?></button>
  </div>
  <p class="reader-status" data-reader-status role="status" aria-live="polite">Loading your book…</p>
  <p class="reader-status is-error" data-reader-warning role="status" hidden></p>
  <div class="reader-toolbar" role="group" aria-label="Reading controls">
    <button class="btn btn--outline" type="button" data-page-prev disabled aria-label="Previous page"><?= icon('arrow-left') ?></button>
    <label class="reader-page-label"><span class="reader-page-word">Page</span><input class="input" type="number" min="1" value="1" data-page-number disabled aria-label="Page number"><span data-page-total>of —</span></label>
    <button class="btn btn--outline" type="button" data-page-next disabled aria-label="Next page"><?= icon('arrow-right') ?></button>
  </div>
  <div class="reader-layout">
    <aside class="reader-navigation" aria-label="Reading navigation">
      <details <?= $ebookChapters ? 'open' : '' ?>><summary>Chapters</summary><ol class="reader-chapters">
      <?php foreach ($ebookChapters as $ch): ?><li><button type="button" data-reader-jump="<?= (int)$ch['position'] ?>"><?= e($ch['title']) ?><span>Page <?= (int)$ch['position'] ?></span></button></li><?php endforeach; ?>
      <?php if (!$ebookChapters): ?><li class="muted">Chapter navigation will appear when the library adds it.</li><?php endif; ?>
      </ol></details>
      <details open><summary>Your bookmarks</summary><ul class="reader-bookmarks" data-reading-bookmarks><li class="muted">Loading bookmarks…</li></ul></details>
    </aside>
    <div class="reader-view">
    <div class="reader-stage" data-reader-stage tabindex="0" aria-label="Book page; use left and right arrow keys to turn pages">
      <div class="reader-paper" data-reader-paper><canvas data-reader-canvas aria-hidden="true"></canvas><div class="textLayer" data-reader-text></div></div>
    </div>
    <div class="reader-page-foot" role="group" aria-label="Page navigation below the book">
      <button class="btn btn--ghost" type="button" data-page-prev disabled aria-label="Previous page"><?= icon('arrow-left') ?><span>Previous</span></button>
      <span data-reader-foot-page aria-hidden="true">—</span>
      <button class="btn btn--ghost" type="button" data-page-next disabled aria-label="Next page"><span>Next</span><?= icon('arrow-right') ?></button>
    </div>
    </div>
  </div>
  <noscript><p class="alert">Enable JavaScript to save reading progress, or use “Open original PDF” above.</p></noscript>
</section>
<script src="<?= e(asset('js/reader.mjs')) ?>" type="module"></script>
<?php require __DIR__ . '/includes/partials/footer.php'; ?>
