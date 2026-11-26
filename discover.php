<?php
require __DIR__ . '/includes/bootstrap.php';
$user = require_role('member');

$search   = input('q');
$category = input('category');
$sort     = input('sort', 'new');
$onlyAvailable = input('available') === '1';
$browseAll = input('view') === 'all';
$filtering = $search !== '' || $category !== '' || $onlyAvailable || $browseAll;

$where = [];
$params = [];
if ($search !== '') {
    $where[] = '(b.title LIKE ? OR b.author LIKE ? OR b.category LIKE ? OR b.isbn LIKE ?)';
    array_push($params, "%$search%", "%$search%", "%$search%", "%$search%");
}
if ($category !== '') {
    $where[] = 'b.category = ?';
    $params[] = $category;
}
if ($onlyAvailable) $where[] = '(b.copies - COALESCE(al.active, 0)) > 0';
$whereSql = $where ? 'WHERE ' . implode(' AND ', $where) : '';

$orders = ['new' => 'b.created_at DESC, b.id DESC', 'title' => 'b.title ASC', 'author' => 'b.author ASC, b.title ASC',
           'rating' => 'COALESCE(rv.avg_rating, 0) DESC, COALESCE(rv.n_reviews, 0) DESC, b.title ASC'];
$orderSql = $orders[$sort] ?? $orders['new'];

$total = (int)q_val('SELECT COUNT(*) FROM ' . books_from() . " $whereSql", $params);
$p = paginate($total, 18);
$showFeature = !$filtering && $p['page'] === 1;
$books = $showFeature ? [] : q_all('SELECT ' . book_columns() . ' FROM ' . books_from() . " $whereSql ORDER BY $orderSql LIMIT {$p['per']} OFFSET {$p['offset']}", $params);
$cats = $showFeature ? [] : categories();

// The "New on the shelf" feature: the newest book, preferring one with a free copy.
$featured = $showFeature
    ? q_one('SELECT ' . book_columns() . ' FROM ' . books_from() . ' ORDER BY ((b.copies - COALESCE(al.active, 0)) > 0) DESC, b.created_at DESC, b.id DESC LIMIT 1')
    : null;
$block = borrow_block_reason((int)$user['id']);

// The editorial home only fetches the rows it renders. Filtered catalogue
// pages retain the existing category, availability, sort and pagination flow.
$popular = $arrivals = $audioChoices = [];
$audioBook = null;
if ($showFeature) {
    $resumeBook = continue_reading((int)$user['id']);
    $chosenHero = (int)library_setting('featured_book');
    if ($resumeBook) $featured=$resumeBook;
    elseif ($chosenHero && ($chosen=find_book($chosenHero))) $featured=$chosen;
    $popular = q_all('SELECT ' . book_columns() . ' FROM ' . books_from()
        . ' LEFT JOIN (SELECT book_id, COUNT(*) AS n FROM loans GROUP BY book_id) pop ON pop.book_id = b.id'
        . ' ORDER BY COALESCE(pop.n, 0) DESC, b.created_at DESC, b.id DESC LIMIT 4');
    $arrivals = q_all('SELECT ' . book_columns() . ' FROM ' . books_from()
        . ' ORDER BY b.created_at DESC, b.id DESC LIMIT 12');
    // A catalogue summary can be listened to without pretending we host a full audiobook.
    $audioBook = $popular[0] ?? $featured;
    $audioChoices = q_all('SELECT b.id,b.title,b.author,bm.audio_file FROM books b LEFT JOIN book_media bm ON bm.book_id=b.id ORDER BY b.title,b.id');
    $chosenAudio=(int)library_setting('featured_audio');
    $recorded=null;
    foreach($audioChoices as &$candidate){
        $candidate['has_audio']=(bool)private_media_path($candidate['audio_file'],'audio');
        if(!$recorded&&$candidate['has_audio'])$recorded=$candidate;
    }
    unset($candidate);
    if ($chosenAudio && ($chosen=find_book($chosenAudio))) $audioBook=$chosen;
    elseif ($recorded) $audioBook=find_book((int)$recorded['id']);
    $remembered=$_COOKIE['bt_listening_'.(int)$user['id']]??'';
    if(is_string($remembered)&&ctype_digit($remembered)&&($rememberedBook=find_book((int)$remembered)))$audioBook=$rememberedBook;
    if (input('listen') !== '') {
        $selected=find_book(input_int('listen'));
        if (!$selected) {flash('error','That book is no longer available. Choose another title to listen to.');redirect('discover.php');}
        $audioBook=$selected;
        setcookie('bt_listening_'.(int)$user['id'],(string)$selected['id'],['expires'=>time()+2592000,'path'=>base_url(),'secure'=>!empty($_SERVER['HTTPS'])&&$_SERVER['HTTPS']!=='off','samesite'=>'Lax']);
    }
    $homeCollection=q_one('SELECT * FROM collections WHERE is_active=1 ORDER BY sort_order,id LIMIT 1');
    $collectionBooks=$homeCollection ? q_all('SELECT '.book_columns().' FROM '.books_from().' JOIN collection_books cb ON cb.book_id=b.id WHERE cb.collection_id=? ORDER BY cb.position,b.title',[(int)$homeCollection['id']]) : [];
}

$title = 'Discover';
$active = 'discover';
$bodyClass = $showFeature ? 'reading-home' : '';
require __DIR__ . '/includes/partials/header.php';

if ($showFeature) {
    require __DIR__ . '/includes/partials/reading_home.php';
    require __DIR__ . '/includes/partials/footer.php';
    return;
}

$chipUrl = fn(string $c) => url('discover.php', array_filter(['category' => $c, 'view' => $c === '' ? 'all' : '', 'q' => $search, 'sort' => $sort !== 'new' ? $sort : '', 'available' => $onlyAvailable ? '1' : '']));
?>
<header class="page-head page-head--discover">
  <div class="page-head__title">
    <h1><?= $search !== '' ? 'Results for “' . e($search) . '”' : ($category !== '' ? e($category) : ($browseAll ? 'All books' : 'Discover')) ?></h1>
    <p><?= $filtering ? ($p['total'] ? plural($p['total'], 'book') : 'No books') . ($onlyAvailable ? ', available now' : '') . '.' : 'Browse the catalogue, borrow a book and keep track of your loans.' ?></p>
  </div>
  <form class="search" method="get" role="search" action="<?= e(url('discover.php')) ?>">
    <?= icon('search') ?>
    <label class="sr-only" for="q">Search the catalogue</label>
    <input id="q" type="search" name="q" autocomplete="off" spellcheck="false" value="<?= e($search) ?>" placeholder="Search title, author or category" data-suggest role="combobox" aria-expanded="false" aria-controls="q-suggest" aria-autocomplete="list">
    <?php if ($category !== ''): ?><input type="hidden" name="category" value="<?= e($category) ?>"><?php endif; ?>
    <kbd class="kbd" aria-hidden="true">/</kbd>
    <div class="suggest" id="q-suggest" role="listbox" aria-label="Suggestions" hidden></div>
  </form>
  <?php if ($filtering && $books): ?><a class="btn btn--ghost" href="<?= e(url('discover.php')) ?>"><?= $browseAll && $search === '' && $category === '' && !$onlyAvailable ? 'Back to Discover' : 'Clear filters' ?></a><?php endif; ?>
</header>

<?php if ($block && $block['code'] === 'overdue'): ?>
<a class="alert alert--error" href="<?= e(url('loans.php')) ?>" style="text-decoration:none">
  <?= icon('clock') ?><span><strong>Borrowing is paused.</strong> <?= e($block['message']) ?> <span class="link">Go to My loans</span></span>
</a>
<?php endif; ?>

<?php if (!$showFeature): ?>
<section class="stack" style="gap:20px" aria-labelledby="shelf-h">
  <div class="section-title">
    <h2 id="shelf-h" class="<?= $filtering ? 'sr-only' : '' ?>"><?= $filtering ? 'Results' : 'On the shelves' ?></h2>
    <?php if ($filtering): ?><span class="spacer"></span><?php endif; ?>
    <form method="get" class="row row--wrap" action="<?= e(url('discover.php')) ?>">
      <?php foreach (['q' => $search, 'category' => $category, 'view' => $browseAll ? 'all' : ''] as $k => $v): if ($v !== ''): ?><input type="hidden" name="<?= $k ?>" value="<?= e($v) ?>"><?php endif; endforeach; ?>
      <label class="row small" style="gap:8px;cursor:pointer;color:var(--text-2)">
        <input type="checkbox" name="available" value="1" <?= $onlyAvailable ? 'checked' : '' ?> data-autosubmit> Available now
      </label>
      <label class="sr-only" for="sort">Sort by</label>
      <select class="select" id="sort" name="sort" data-autosubmit style="width:auto;min-height:38px;padding:6px 40px 6px 16px;font-size:14px;border-radius:999px">
        <option value="new" <?= $sort === 'new' ? 'selected' : '' ?>>Newest</option>
        <option value="title" <?= $sort === 'title' ? 'selected' : '' ?>>Title A–Z</option>
        <option value="author" <?= $sort === 'author' ? 'selected' : '' ?>>Author A–Z</option>
        <option value="rating" <?= $sort === 'rating' ? 'selected' : '' ?>>Top rated</option>
      </select>
      <noscript><button class="btn btn--ghost btn--sm">Apply</button></noscript>
    </form>
  </div>

  <nav class="chips chips--scroll" aria-label="Categories">
    <a class="chip" href="<?= e($chipUrl('')) ?>" aria-current="<?= $category === '' ? 'true' : 'false' ?>">All books</a>
    <?php foreach ($cats as $c => $n): ?>
      <a class="chip" href="<?= e($chipUrl($c)) ?>" aria-current="<?= $category === $c ? 'true' : 'false' ?>"><?= e($c) ?> <small><?= (int)$n ?></small></a>
    <?php endforeach; ?>
  </nav>

  <?php if ($books): ?>
    <div class="book-grid">
      <?php foreach ($books as $book) include __DIR__ . '/includes/partials/book_card.php'; ?>
    </div>
    <?= pagination_links($p) ?>
  <?php else: ?>
    <div class="empty card">
      <?= empty_art('search') ?>
      <h3>No books found</h3>
      <p>Try a different search or category.</p>
      <a class="btn btn--ghost btn--sm" href="<?= e(url('discover.php')) ?>">Clear filters</a>
    </div>
    <?php
      $suggest = q_all('SELECT ' . book_columns() . ' FROM ' . books_from()
          . ' LEFT JOIN (SELECT book_id, COUNT(*) AS n FROM loans GROUP BY book_id) pop ON pop.book_id = b.id'
          . ' WHERE (b.copies - COALESCE(al.active, 0)) > 0 ORDER BY COALESCE(pop.n, 0) DESC, b.title LIMIT 8');
    ?>
    <?php if ($suggest): ?>
    <section class="stack" style="gap:16px" aria-labelledby="suggest-h">
      <div class="section-title"><h2 id="suggest-h">Popular and on the shelf now</h2></div>
      <div class="shelf-row" data-row>
        <?php foreach ($suggest as $book) include __DIR__ . '/includes/partials/book_card.php'; ?>
      </div>
    </section>
    <?php endif; ?>
  <?php endif; ?>
</section>
<?php endif; ?>
<?php require __DIR__ . '/includes/partials/footer.php'; ?>
