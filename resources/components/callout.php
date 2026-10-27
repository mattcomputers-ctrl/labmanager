<?php /** Props: type (info|success|warning|danger), title, body (html allowed if html=true), text */
$type = $type ?? 'info';
$icons = [
  'info' => '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"><circle cx="12" cy="12" r="9"/><path d="M12 11v5M12 8h.01"/></svg>',
  'success' => '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="9"/><path d="m8.5 12.5 2.5 2.5 5-5"/></svg>',
  'warning' => '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M10.3 3.9 1.8 18a2 2 0 0 0 1.7 3h17a2 2 0 0 0 1.7-3L13.7 3.9a2 2 0 0 0-3.4 0z"/><path d="M12 9v4M12 17h.01"/></svg>',
  'danger' => '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"><circle cx="12" cy="12" r="9"/><path d="M15 9l-6 6M9 9l6 6"/></svg>',
];
?>
<div class="callout callout-<?= e($type) ?>" role="<?= $type === 'danger' || $type === 'warning' ? 'alert' : 'note' ?>">
  <?= $icons[$type] ?? $icons['info'] ?>
  <div><?php if (!empty($title)): ?><span class="callout-title"><?= e($title) ?></span><?php endif; ?><?= !empty($html) ? ($body ?? '') : nl2br(e($body ?? $text ?? '')) ?></div>
</div>
