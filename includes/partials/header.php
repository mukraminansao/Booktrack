<?php
/**
 * Page shell: <head>, sidebar navigation and the opening of <main>.
 * Expects: $title (string), $active (nav key). Optional: $bodyClass.
 */
$user = current_user();
$active = $active ?? '';
$isAdmin = $user && $user['role'] === 'admin';
$isMemberSurface = $user && !$isAdmin;
if ($isAdmin) $bodyClass=trim(($bodyClass ?? '').' admin-surface');
$isHome = ($bodyClass ?? '') === 'reading-home';
$isPublicLanding = !$user && ($bodyClass ?? '') === 'landing-page';
$pageDescription = $isPublicLanding
    ? 'BookTrack helps small libraries manage books, borrowing, holds and reminders. Browse the catalogue, check availability and keep track of your loans.'
    : 'Manage your library account, discover books, check availability and track your loans with BookTrack.';
if ($isMemberSurface && !$isHome) $bodyClass = trim(($bodyClass ?? '') . ' reading-home member-page');
$railActive = $isMemberSurface && !$isHome && $active === 'discover' ? 'catalog' : $active;

if ($user && !$isAdmin) {
    $navCounts = [
        // only loans that need attention: overdue or due within the "due soon" window
        'loans'    => (int)q_val("SELECT COUNT(*) FROM loans WHERE user_id = ? AND status = 'borrowed' AND due_at <= ?",
                        [(int)$user['id'], date('Y-m-d', strtotime('+' . (int)config('library.due_soon_days') . ' days'))])
                      + ready_hold_count((int)$user['id']),
        'messages' => unread_replies((int)$user['id']),
    ];
    $nav = [
        ['discover.php', 'discover', 'compass', 'Discover'],
        ['loans.php', 'loans', 'book', 'My loans', $navCounts['loans']],
        ['favorites.php', 'favorites', 'heart', 'Favorites'],
        ['messages.php', 'messages', 'message', 'Messages', $navCounts['messages']],
    ];
} elseif ($isAdmin) {
    $navCounts = [
        'returns'  => (int)q_val("SELECT COUNT(*) FROM loans WHERE status = 'return_requested'"),
        'messages' => chat_unread_conversations(),
    ];
    $nav = [
        ['admin/index.php', 'overview', 'grid', 'Overview'],
        [$navCounts['returns'] ? 'admin/loans.php?tab=requests' : 'admin/loans.php', 'loans', 'swap', 'Circulation', $navCounts['returns']],
        ['admin/desk.php', 'desk', 'scan', 'Desk'],
        ['admin/books.php', 'books', 'books', 'Books'],
        ['admin/members.php', 'members', 'users', 'Members'],
        ['admin/messages.php', 'messages', 'inbox', 'Messages', $navCounts['messages']],
        ['admin/activity.php', 'activity', 'activity', 'Activity'],
        ['admin/content.php', 'content', 'grid', 'Content & audio'],
        ['admin/backups.php', 'backups', 'shield', 'Backups'],
    ];
}
$theme = ($_COOKIE['bt_theme'] ?? ($isMemberSurface || !$user ? 'light' : 'dark')) === 'light' ? 'light' : 'dark';
$reminders = $user ? reminders_for($user) : [];
$railNav = $nav ?? [];
if ($isMemberSurface) {
    $railNav = [
        ['discover.php', 'discover', 'home', 'Home'],
        ['discover.php?view=all', 'catalog', 'library', 'Book catalogue'],
        ['loans.php', 'loans', 'stopwatch', 'My loans'],
        ['favorites.php', 'favorites', 'bookmark', 'Favorites'],
        ['profile.php', 'profile', 'settings', 'Profile and settings'],
    ];
}
?><!doctype html>
<html lang="en" data-theme="<?= $theme ?>">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <meta name="theme-color" content="<?= $isMemberSurface ? ($theme === 'light' ? '#F8F4EB' : '#1A2018') : ($theme === 'light' ? '#EDF0F4' : '#0E1420') ?>">
  <meta name="color-scheme" content="<?= $theme ?>">
  <meta name="csrf-token" content="<?= e(csrf_token()) ?>">
  <title><?= e(($title ?? '') ? $title . ' | ' : '') ?><?= e(config('app_name')) ?></title>
  <meta name="description" content="<?= e($pageDescription) ?>">
  <meta name="robots" content="<?= $isPublicLanding ? 'index,follow' : 'noindex,nofollow' ?>">
  <?php if ($isPublicLanding): ?>
  <link rel="canonical" href="<?= e(absolute_url('index.php')) ?>">
  <meta property="og:type" content="website">
  <meta property="og:title" content="<?= e(($title ?? '') . ' | ' . config('app_name')) ?>">
  <meta property="og:description" content="<?= e($pageDescription) ?>">
  <meta property="og:url" content="<?= e(absolute_url('index.php')) ?>">
  <?php endif; ?>
  <link rel="icon" type="image/png" href="<?= e(asset('img/favicon.png')) ?>">
  <link rel="apple-touch-icon" href="<?= e(asset('img/icon-512.png')) ?>">
  <link rel="preload" href="<?= e(url('assets/fonts/dm-sans.woff2')) ?>" as="font" type="font/woff2" crossorigin>
  <?php if (!$isMemberSurface): ?><link rel="preload" href="<?= e(url('assets/fonts/bricolage-grotesque.woff2')) ?>" as="font" type="font/woff2" crossorigin><?php endif; ?>
  <link rel="stylesheet" href="<?= e(asset('css/app.css')) ?>">
  <?php if ($user): ?><link rel="stylesheet" href="<?= e(asset('css/enhancements.css')) ?>"><script src="<?= e(asset('js/audiobook.js')) ?>" type="module"></script><?php endif; ?>
  <?php if (!$user): ?><link rel="stylesheet" href="<?= e(asset('css/welcome.css')) ?>"><?php endif; ?>
  <?php if ($isAdmin && in_array(basename($_SERVER['SCRIPT_NAME'] ?? ''),['book.php','listen.php'],true)): ?><link rel="stylesheet" href="<?= e(asset('css/reading-home.css')) ?>"><?php endif; ?>
  <?php if ($isMemberSurface): ?><link rel="stylesheet" href="<?= e(asset('css/reading-home.css')) ?>"><?php if (!$isHome): ?><link rel="stylesheet" href="<?= e(asset('css/member-pages.css')) ?>"><?php endif; ?><link rel="stylesheet" href="<?= e(asset('css/responsive.css')) ?>"><?php endif; ?>
  <script src="<?= e(asset('js/transitions.js')) ?>"></script>
  <script src="<?= e(asset('js/app.js')) ?>" defer></script>
</head>
<body class="<?= e($bodyClass ?? '') ?>"<?= $user ? ' data-search-api="' . e(url('api/search.php')) . '"' : '' ?>>
<?php if ($user):
    $home = url(home_for($user));
?>
<a class="skip-link" href="#main">Skip to content</a>
<div class="app" id="app">
  <aside class="sidebar" id="sidebar" aria-label="Sidebar">
    <a class="brand" href="<?= e($home) ?>" aria-label="BookTrack home">
      <?= $isMemberSurface ? icon('book') : logo_mark() ?>
      <span class="brand__text"><span class="brand__name">BookTrack</span><?php if ($isAdmin): ?><span class="brand__tag">Librarian</span><?php endif; ?></span>
    </a>
    <nav class="nav" aria-label="Main">
      <?php foreach ($railNav as $item): ?>
        <a href="<?= e(url($item[0])) ?>" aria-label="<?= e($item[3]) ?>" title="<?= e($item[3]) ?>" <?= $railActive === $item[1] ? 'aria-current="page"' : '' ?>>
          <span class="nav__icon"><?= icon($item[2]) ?></span>
          <span class="nav__label"><?= e($item[3]) ?></span>
          <?php if (!empty($item[4])): ?><span class="count" aria-label="<?= (int)$item[4] ?> <?= $item[1] === 'messages' ? 'new' : 'need attention' ?>"><?= (int)$item[4] ?></span><?php endif; ?>
        </a>
      <?php endforeach; ?>
    </nav>
    <div class="rail-user">
      <button class="rail-bell" type="button" popovertarget="reminders" aria-label="Reminders<?= $reminders ? ', ' . count($reminders) . ' new' : '' ?>">
        <span class="nav__icon"><?= icon('bell') ?><?php if ($reminders): ?><span class="count"><?= count($reminders) > 9 ? '9+' : count($reminders) ?></span><?php endif; ?></span>
        <span class="nav__label">Reminders</span>
      </button>
      <a class="rail-account" href="<?= e(url('profile.php')) ?>" <?= $active === 'profile' ? 'aria-current="page"' : '' ?> title="<?= e($user['name']) ?>">
        <span class="nav__icon"><span class="avatar"><?= e(initials($user['name'])) ?></span></span>
        <span class="nav__label">Profile</span>
      </a>
      <?php if ($isMemberSurface): ?>
      <button class="rail-signout" type="button" popovertarget="reading-menu" aria-label="Account menu" title="Account menu"><span class="nav__icon"><?= icon('menu') ?></span></button>

      <?php else: ?>
      <form method="post" action="<?= e(url('logout.php')) ?>">
        <?= csrf_field() ?>
        <button class="rail-signout" type="submit"><span class="nav__icon"><?= icon('logout') ?></span><span class="nav__label">Sign out</span></button>
      </form>
      <?php endif; ?>
    </div>
  </aside>
  <?php if ($isMemberSurface): ?>
      <div class="reading-menu" id="reading-menu" popover aria-label="Account menu">
        <a href="<?= e(url('messages.php')) ?>"><?= icon('message') ?>Messages<?php if ($navCounts['messages']): ?> (<?= (int)$navCounts['messages'] ?> new)<?php endif; ?></a>
        <a href="<?= e(url('favorites.php')) ?>"><?= icon('bookmark') ?>Saved books</a>
        <a href="<?= e(url('profile.php')) ?>"><?= icon('user') ?>Profile and settings</a>
        <form method="post" action="<?= e(url('logout.php')) ?>"><?= csrf_field() ?><button type="submit"><?= icon('logout') ?>Sign out</button></form>
      </div>
  <?php endif; ?>

  <?php
  // Phones get an app-style tab bar: the five main sections, always in reach.
  $tabbarItems = $isAdmin
      ? array_values(array_filter($nav, fn($i) => $i[1] !== 'activity'))
      : [
          ['discover.php', 'discover', 'home', 'Home'],
          ['discover.php?view=all', 'catalog', 'search', 'Discover'],
          ['loans.php', 'loans', 'book', 'Loans', $navCounts['loans']],
          ['favorites.php', 'favorites', 'bookmark', 'Saved'],
          ['profile.php', 'profile', 'user', 'Profile'],
        ];
  // Rendered after <main> (in footer.php) so keyboard and screen-reader users reach the
  // page before the bar; it's fixed to the bottom of the screen either way.
  ob_start();
  ?>
  <nav class="tabbar" aria-label="Sections">
    <?php foreach (array_slice($tabbarItems, 0, $isAdmin ? 6 : 5) as $item): ?>
      <a href="<?= e(url($item[0])) ?>" <?= $railActive === $item[1] ? 'aria-current="page"' : '' ?>>
        <span class="tabbar__icon"><?= icon($item[2]) ?><?php if (!empty($item[4])): ?><span class="count" aria-label="<?= (int)$item[4] ?> <?= $item[1] === 'messages' ? 'new' : 'need attention' ?>"><?= (int)$item[4] ?></span><?php endif; ?></span>
        <span class="tabbar__label"><?= e($item[1] === 'loans' && !$isAdmin ? 'Loans' : $item[3]) ?></span>
      </a>
    <?php endforeach; ?>
  </nav>
  <?php $tabbarHtml = ob_get_clean(); ?>

  <div class="shell">
    <header class="topbar" data-topbar>
      <?php if ($isMemberSurface): ?>
      <form class="reading-search" method="get" action="<?= e(url('discover.php')) ?>" role="search">
        <?= icon('search') ?><label class="sr-only" for="reading-search">Search the catalogue</label>
        <input id="reading-search" name="q" type="search" value="<?= e($search ?? '') ?>" placeholder="Search book name, author, edition ..." autocomplete="off" data-suggest role="combobox" aria-expanded="false" aria-controls="reading-suggest" aria-autocomplete="list">
        <?php if (isset($category) && $category !== ''): ?><input type="hidden" name="category" value="<?= e($category) ?>"><?php endif; ?>
        <div class="suggest" id="reading-suggest" role="listbox" aria-label="Suggestions" hidden></div>
      </form>
      <a class="reading-profile" href="<?= e(url('profile.php')) ?>" aria-label="Profile for <?= e($user['name']) ?>"><span class="avatar"><?= e(initials($user['name'])) ?></span><span><?= e($user['name']) ?></span></a>
      <?php endif; ?>
      <a class="topbar__brand" href="<?= e($home) ?>"><?php if ($isMemberSurface): ?><span class="compact-brand-mark"><?= icon('book') ?></span><?php else: ?><?= logo_mark() ?><?php endif; ?>BookTrack<?php if ($isAdmin): ?><small>Librarian</small><?php endif; ?></a>
      <div class="topbar__actions">
        <?php if ($isMemberSurface): ?>
      <button class="compact-account" type="button" popovertarget="reading-menu" aria-label="Account menu" title="<?= e($user['name']) ?>"><span class="avatar"><?= e(initials($user['name'])) ?></span></button>
        <?php endif; ?>
        <button class="btn btn--ghost btn--icon topbar__btn" type="button" data-palette-open aria-label="Search"><?= icon('search') ?></button>
        <button class="btn btn--ghost btn--icon topbar__btn" type="button" popovertarget="reminders" aria-label="Reminders<?= $reminders ? ', ' . count($reminders) . ' new' : '' ?>"><?= icon('bell') ?><?php if ($reminders): ?><span class="count"><?= count($reminders) > 9 ? '9+' : count($reminders) ?></span><?php endif; ?></button>
        <a class="avatar topbar__avatar" href="<?= e(url('profile.php')) ?>" aria-label="Your profile"><?= e(initials($user['name'])) ?></a>
      </div>
    </header>

    <main class="main" id="main" tabindex="-1">
<?php endif; ?>
