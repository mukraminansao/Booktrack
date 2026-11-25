<?php
/** Reference-led dashboard. Titles and availability always use live catalogue data. */
$firstName = explode(' ', trim($user['name']))[0];
$bookUrl = fn(array $b) => url('book.php', ['id' => (int)$b['id']]);
$continueUrl = !empty($resumeBook) ? reading_url($resumeBook,$resumeBook['medium']) : ($featured && private_media_path($featured['pdf'],'pdfs') ? reading_url($featured) : ($featured ? $bookUrl($featured) : url('discover.php', ['view' => 'all'])));
$fullAudio = $audioBook && private_media_path(media_for((int)$audioBook['id'])['audio_file'] ?? null,'audio');
$fullChapters = $fullAudio ? chapters_for((int)$audioBook['id'],'audio') : [];
$collectionUrl = !empty($homeCollection) ? url('collection.php',['id'=>$homeCollection['id']]) : url('discover.php',['view'=>'all']);
$collectionShelf = !empty($homeCollection) ? $collectionBooks : $arrivals;
$previewChapters = $audioBook ? [
    ['title' => 'A little about ' . $audioBook['title'], 'text' => $audioBook['description']],
    ['title' => 'Your next chapter starts here', 'text' => $audioBook['title'] . ' by ' . $audioBook['author'] . '. ' . ((int)$audioBook['available'] > 0 ? plural((int)$audioBook['available'], 'copy', 'copies') . ' available to borrow. Open the book details to start reading.' : 'All copies are currently on loan. Open the book details to place a hold.')],
] : [];
?>
<div class="reading-layout">
  <section class="reading-hero" aria-labelledby="reading-greeting">
    <div class="reading-greeting">
      <h1 id="reading-greeting"><span class="reading-greeting-opening">Happy reading</span><span class="reading-greeting-name"><?= e($firstName) ?></span></h1>
      <?php if ($block && $block['code'] === 'overdue'): ?>
      <p><a href="<?= e(url('loans.php')) ?>">Borrowing is paused. <?= e($block['message']) ?> Manage your loans.</a></p>
      <?php else: ?>
      <p><span class="reading-intro-wide">A little escape. A new perspective. Your next favourite story is waiting on our shelves. Make yourself at home, and turn a page.</span><span class="reading-intro-compact">Discover, search, and find your next favourite book in our library collection.</span></p>
      <?php endif; ?>
      <a class="reading-cta" href="<?= e($continueUrl) ?>"><?= !empty($resumeBook) ? ($resumeBook['medium']==='audio' ? 'Continue listening' : 'Continue reading') : ($featured ? 'Start reading' : 'Explore books') ?> <?= icon('arrow-right') ?></a>
    </div>
    <div class="reading-art"><picture><source type="image/webp" srcset="<?= e(asset('img/open-book-reference-640.webp')) ?> 640w, <?= e(asset('img/open-book-reference-1280.webp')) ?> 1280w" sizes="(max-width:767px) 55vw, (max-width:1199px) 48vw, (max-width:1439px) 330px, 360px"><img src="<?= e(asset('img/open-book-reference.png')) ?>" alt="An open book with curved pages, a growth chart on the left and reading passages on the right" width="1512" height="1040" fetchpriority="high" decoding="async"></picture></div>
    <div class="reading-feature">
      <?php if ($featured): ?>
      <a class="reading-feature-link" href="<?= e($bookUrl($featured)) ?>" aria-label="View featured book"><?= icon('chevron-right') ?></a>
      <h2><a href="<?= e($bookUrl($featured)) ?>"><?= e($featured['title']) ?></a></h2>
      <p class="reading-meta"><?php if (!empty($resumeBook)): ?><?= $resumeBook['medium']==='ebook' ? 'Your place: page '.(int)$resumeBook['position'] : 'Your listening place: '.reading_clock((float)$resumeBook['position']) ?><?php else: ?><?= (int)$featured['available'] ?> / <?= (int)$featured['copies'] ?> copies available<?php endif; ?></p>
      <p class="reading-excerpt"><?= e($featured['description'] ?: 'A new story to discover. Explore this title in the BookTrack catalogue.') ?></p>
      <p class="reading-signature">- <?= e($featured['author']) ?></p>
      <?php else: ?><h2>Your next<br>great read</h2><p class="reading-meta">The shelves are waiting.</p><p class="reading-excerpt">New books will appear here when the library adds them.</p><?php endif; ?>
    </div>
  </section>

  <section class="reading-popular" aria-labelledby="popular-heading">
    <div class="reading-section-heading"><h2 id="popular-heading">Popular Now</h2><a href="<?= e(url('discover.php', ['view' => 'all'])) ?>" aria-label="See all books">See all <?= icon('chevron-right') ?></a></div>
    <div class="reading-covers">
      <?php foreach ($popular as $b): ?>
      <a class="reading-book" href="<?= e($bookUrl($b)) ?>" title="<?= e($b['title']) ?>"><span class="reading-jacket"><?= cover_html($b, 'reading-cover') ?></span><span><?= e($b['title']) ?></span></a>
      <?php endforeach; ?>
      <?php if (!$popular): ?><p class="reading-empty">No books on the shelves yet. Please check back soon.</p><?php endif; ?>
    </div>
  </section>

  <aside class="reading-utilities" aria-label="Listening and chapters">
    <section class="reading-audio" aria-labelledby="audio-heading"<?= !$fullAudio ? ' data-reading-player' : '' ?>>
      <div class="reading-section-heading"><h2 id="audio-heading">Audio Book</h2><?php if ($audioBook): ?><a href="<?= e($bookUrl($audioBook)) ?>" aria-label="View audio preview book details"><?= icon('chevron-right') ?></a><?php endif; ?></div>
      <?php if ($fullAudio): ?>
      <?= audiobook_html($audioBook) ?>
      <?php elseif ($audioBook): ?>
      <div class="reading-player">
        <a class="reading-player-jacket" href="<?= e($bookUrl($audioBook)) ?>" aria-label="View <?= e($audioBook['title']) ?>"><?= cover_html($audioBook, 'reading-utility-cover') ?></a>
        <div class="reading-player-body">
          <a class="reading-player-title" href="<?= e($bookUrl($audioBook)) ?>"><?= e($audioBook['title']) ?></a>
          <p class="reading-player-author"><?= e($audioBook['author']) ?></p>
          <div class="reading-player-progress"><label class="sr-only" for="preview-position">Preview position</label><input type="range" id="preview-position" min="0" max="100" value="0" data-preview-position disabled></div>
          <div class="reading-player-bottom">
            <span class="reading-player-time" data-preview-time>0:00</span>
            <div class="reading-player-controls">
              <button type="button" data-preview-prev aria-label="Previous chapter" disabled><svg class="icon" viewBox="0 0 24 24" aria-hidden="true"><path d="M11 5v14l-8-7zM21 5v14l-8-7z"/></svg></button>
              <button type="button" class="reading-player-play" data-preview-play aria-label="Play book preview" aria-pressed="false" disabled><svg class="icon preview-play-icon" viewBox="0 0 24 24" aria-hidden="true"><path d="m9 5 11 7-11 7z"/></svg><svg class="icon preview-pause-icon" viewBox="0 0 24 24" aria-hidden="true"><path d="M8 5h3v14H8zM15 5h3v14h-3z"/></svg></button>
              <button type="button" data-preview-next aria-label="Next chapter" disabled><svg class="icon" viewBox="0 0 24 24" aria-hidden="true"><path d="M3 5v14l8-7zM13 5v14l8-7z"/></svg></button>
            </div>
            <span class="reading-player-kind">Read-aloud preview</span>
          </div>
        </div>
      </div>
      <p class="reading-player-status" data-preview-status role="status">Enable JavaScript to listen to this catalogue preview.</p>
      <?php else: ?><p class="reading-empty">Book previews will appear when titles are added.</p><?php endif; ?>
    </section>
    <section class="reading-updates" aria-labelledby="chapters-heading">
      <div class="reading-section-heading"><h2 id="chapters-heading">Chapters</h2><a href="<?= e($audioBook ? $bookUrl($audioBook) : url('discover.php', ['view' => 'all'])) ?>" aria-label="Explore the preview book"><span aria-hidden="true">···</span></a></div>
      <ol class="reading-timeline">
        <?php if ($fullAudio): ?>
        <?php foreach (array_slice($fullChapters,0,2) as $chapter): ?><li><button type="button" class="reading-chapter" data-audio-seek="<?= (float)$chapter['position'] ?>" data-audio-book="<?= (int)$audioBook['id'] ?>"><strong><?= e($chapter['title']) ?></strong><span class="reading-update-description">Listen to this chapter</span><span class="reading-update-status"><?= reading_clock((float)$chapter['position']) ?></span></button></li><?php endforeach; ?>
        <?php if (!$fullChapters): ?><li><a class="link" href="<?= e(reading_url($audioBook,'audio')) ?>">Open the audiobook and save a listening bookmark.</a></li><?php endif; ?>
        <?php else: ?>
        <?php foreach ($previewChapters as $i => $chapter): ?>
        <li><button type="button" class="reading-chapter" data-preview-chapter="<?= $i ?>" data-preview-text="<?= e($chapter['text']) ?>" disabled><strong><?= e($chapter['title']) ?></strong><span class="reading-update-description"><?= e($chapter['text']) ?></span><span class="reading-update-status">Preview <?= $i + 1 ?></span></button></li>
        <?php endforeach; ?>
        <?php if (!$previewChapters): ?><li class="reading-empty">Your next chapter is on its way.</li><?php endif; ?>
        <?php endif; ?>
      </ol>
    </section>
  </aside>

  <section class="reading-collection" aria-labelledby="arrivals-heading">
    <div class="reading-section-heading"><h2 id="arrivals-heading"><?= e($homeCollection['title'] ?? 'New series collection') ?></h2><a href="<?= e($collectionUrl) ?>" aria-label="Browse collection">See all <?= icon('chevron-right') ?></a></div>
    <?php if ($collectionShelf): ?>
    <a class="reading-collection-item" href="<?= e($collectionUrl) ?>">
      <span class="reading-mini-stack"><?php foreach (array_slice($collectionShelf, 0, 2) as $b): ?><?= cover_html($b, 'reading-mini-cover') ?><?php endforeach; ?></span>
      <span><strong><?= e(($homeCollection['subtitle'] ?? '') ?: 'Fresh stories for your shelf') ?></strong><em><?= e(plural(count($collectionShelf),!empty($homeCollection)?'book':'recent arrival')) ?> to discover</em></span><span class="reading-collection-count"><?= !empty($homeCollection) ? count($collectionShelf) : $total ?> books</span>
    </a>
    <?php else: ?><p class="reading-empty">New arrivals will appear here.</p><?php endif; ?>
  </section>
</div>
<script src="<?= e(asset('js/reading-player.js')) ?>" defer></script>
