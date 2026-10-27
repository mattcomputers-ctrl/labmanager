<?php
/** @var \Chroma\Core\App $app */
/** @var \Chroma\Core\Auth $auth */
$title = $title ?? ($app->settings->get('app_name') ?? 'Chroma');
$user = $auth->user();
$modeNow = $mode ?? $app->mode();
$flashes = $app->flash->pull();
$path = $request->path ?? '/';
$nav = [];
if (can('projects.view')) { $nav[] = ['Projects', '/projects', str_starts_with($path, '/projects')]; }
if (can('codes.view')) { $nav[] = ['Code lookup', '/codes', $path === '/codes' || str_starts_with($path, '/codes/lookup') || str_starts_with($path, '/codes/e/') || str_starts_with($path, '/codes/s/')]; }
if (can('codes.view')) { $nav[] = ['Code logs', '/codes/logs', str_starts_with($path, '/codes/logs')]; }
if (can('codes.assign_internal', 'codes.assign_sell')) { $nav[] = ['Quick assign', '/assign', str_starts_with($path, '/assign') || str_starts_with($path, '/wizard/quick-assign')]; }
if (can('reports.view')) { $nav[] = ['Reports', '/reports', str_starts_with($path, '/reports')]; }
if (can('admin.lists', 'admin.prefixes', 'admin.sequences', 'admin.users', 'admin.network', 'admin.cms', 'admin.system', 'admin.import')) { $nav[] = ['Admin', '/admin', str_starts_with($path, '/admin')]; }
$nav[] = ['Help', '/help', str_starts_with($path, '/help')];
$initials = '';
if ($user) {
    $name = (string) ($user['display_name'] ?: $user['username']);
    foreach (preg_split('/\s+/', $name) ?: [] as $part) { $initials .= mb_substr($part, 0, 1); }
    $initials = mb_strtoupper(mb_substr($initials, 0, 2));
}
$themeCookie = $_COOKIE['chroma_theme'] ?? 'system';
$returnTo = $request->method === 'GET' ? $request->fullUrl() : '/';
$expertSwitch = $expertUrl ?? null;
$easySwitch = $easyUrl ?? null;
?>
<!DOCTYPE html>
<html lang="en"<?= in_array($themeCookie, ['light', 'dark'], true) ? ' data-theme="' . e($themeCookie) . '"' : '' ?>>
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title><?= e($title) ?> · Chroma</title>
<meta name="csrf-token" content="<?= e(csrf_token()) ?>">
<link rel="stylesheet" href="/assets/css/chroma.css?v=<?= e($app->version()['short'] ?: '1') ?>">
<link rel="icon" href="/assets/img/favicon.svg" type="image/svg+xml">
<script>try{var t=localStorage.getItem('chroma-theme');if(t&&t!=='system'){document.documentElement.setAttribute('data-theme',t);}}catch(e){}</script>
<script defer src="/assets/js/chroma.js?v=<?= e($app->version()['short'] ?: '1') ?>"></script>
<?= $view->section('head') ?>
<script defer src="/assets/js/alpine-focus.min.js"></script>
<script defer src="/assets/js/alpine.min.js"></script>
</head>
<body class="mode-<?= e($modeNow) ?>" x-data="{ navOpen: false, userMenu: false }" @chroma:escape.document="navOpen = false; userMenu = false">
<a class="skip-link" href="#main">Skip to content</a>
<header class="topbar">
  <button class="btn btn-ghost btn-icon hamburger" type="button" @click="navOpen = !navOpen; userMenu = false" aria-label="Menu" :aria-expanded="navOpen">
    <svg width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"><path d="M4 7h16M4 12h16M4 17h16"/></svg>
  </button>
  <a class="brand" href="/"><span class="brand-mark" aria-hidden="true"></span>Chroma</a>
  <nav class="nav" :class="{ open: navOpen }" @click.outside="if (!$event.target.closest('.hamburger')) navOpen = false" @click.self="navOpen = false" aria-label="Main">
    <?php if (can('codes.view')): ?>
    <form class="nav-search" action="/codes" method="get" role="search">
      <label class="sr-only" for="nav-search">Search codes and projects</label>
      <input id="nav-search" type="search" name="q" placeholder="Search codes, projects…" autocomplete="off">
    </form>
    <?php endif; ?>
    <?php foreach ($nav as [$label, $href, $active]): ?>
      <a href="<?= e($href) ?>"<?= $active ? ' class="active" aria-current="page"' : '' ?> @click="navOpen = false"><?= e($label) ?></a>
    <?php endforeach; ?>
  </nav>
  <div class="topbar-right">
    <?php if (can('codes.view')): ?>
    <form class="topbar-search" action="/codes" method="get" role="search">
      <label class="sr-only" for="global-search">Search codes and projects</label>
      <input id="global-search" type="search" name="q" placeholder="Search codes, projects… (press /)" value="<?= e($path === '/codes' ? ($request->query['q'] ?? '') : '') ?>" autocomplete="off">
    </form>
    <?php endif; ?>
    <div class="mode-toggle" role="group" aria-label="Easy or Expert mode" title="Easy mode walks you through each task one step at a time. Expert mode puts everything on one page.">
      <a href="<?= e($easySwitch ?? url('/mode', ['set' => 'easy', 'return' => $returnTo])) ?>" class="<?= $modeNow === 'easy' ? 'on' : '' ?>" <?= $modeNow === 'easy' ? 'aria-current="true"' : '' ?>>Easy</a>
      <a href="<?= e($expertSwitch ?? url('/mode', ['set' => 'expert', 'return' => $returnTo])) ?>" class="<?= $modeNow === 'expert' ? 'on' : '' ?>" <?= $modeNow === 'expert' ? 'aria-current="true"' : '' ?>>Expert</a>
    </div>
    <div class="menu" @click.outside="userMenu = false">
      <button type="button" class="btn btn-ghost btn-icon" @click="userMenu = !userMenu; navOpen = false" aria-label="Account menu" :aria-expanded="userMenu">
        <span class="avatar"><?= e($initials ?: '?') ?></span>
      </button>
      <div class="menu-panel" x-show="userMenu" x-cloak x-transition.opacity>
        <div class="menu-head"><?= e($user['display_name'] ?? $user['username'] ?? '') ?></div>
        <a href="/account">My account</a>
        <a href="/help">Help</a>
        <div class="menu-head">Theme</div>
        <button type="button" @click="chroma.setTheme('light'); userMenu=false">Light</button>
        <button type="button" @click="chroma.setTheme('dark'); userMenu=false">Dark</button>
        <button type="button" @click="chroma.setTheme('system'); userMenu=false">Match my device</button>
        <hr style="margin:6px 0">
        <form method="post" action="/logout"><?= csrf_field() ?><button type="submit">Sign out</button></form>
      </div>
    </div>
  </div>
</header>
<main id="main" class="page <?= e($pageClass ?? '') ?>">
<?= $content ?>
</main>
<script id="server-flash" type="application/json"><?= json_encode($flashes, JSON_HEX_TAG | JSON_HEX_AMP | JSON_UNESCAPED_UNICODE) ?></script>
<div class="toasts" x-data aria-live="polite">
  <template x-for="t in $store.toasts.items" :key="t.id">
    <div class="toast" :class="'toast-' + t.type" role="status">
      <div><div class="toast-msg" x-text="t.message"></div><div class="toast-detail" x-show="t.detail" x-text="t.detail"></div></div>
      <button type="button" @click="$store.toasts.remove(t.id)" aria-label="Dismiss">&times;</button>
    </div>
  </template>
</div>
<?= $view->section('foot') ?>
</body>
</html>
