<?php $title = $title ?? 'Chroma'; $themeCookie = $_COOKIE['chroma_theme'] ?? 'system'; $flashes = $app->flash->pull(); ?>
<!DOCTYPE html>
<html lang="en"<?= in_array($themeCookie, ['light', 'dark'], true) ? ' data-theme="' . e($themeCookie) . '"' : '' ?>>
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title><?= e($title) ?> · Chroma</title>
<meta name="csrf-token" content="<?= e(csrf_token()) ?>">
<link rel="stylesheet" href="/assets/css/chroma.css">
<link rel="icon" href="/assets/img/favicon.svg" type="image/svg+xml">
<script>try{var t=localStorage.getItem('chroma-theme');if(t&&t!=='system'){document.documentElement.setAttribute('data-theme',t);}}catch(e){}</script>
<script defer src="/assets/js/chroma.js"></script>
<script defer src="/assets/js/alpine.min.js"></script>
</head>
<body class="mode-easy">
<?= $content ?>
<script id="server-flash" type="application/json"><?= json_encode($flashes, JSON_HEX_TAG | JSON_HEX_AMP | JSON_UNESCAPED_UNICODE) ?></script>
<div class="toasts" x-data aria-live="polite">
  <template x-for="t in $store.toasts.items" :key="t.id">
    <div class="toast" :class="'toast-' + t.type" role="status">
      <div><div class="toast-msg" x-text="t.message"></div><div class="toast-detail" x-show="t.detail" x-text="t.detail"></div></div>
      <button type="button" @click="$store.toasts.remove(t.id)" aria-label="Dismiss">&times;</button>
    </div>
  </template>
</div>
</body>
</html>
