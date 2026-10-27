<?php
/** @var array $prefixes @var int $total */
$view->start('head'); ?><link rel="stylesheet" href="/assets/css/codes.css"><?php $view->end();
$active = array_filter($prefixes, fn($p) => (int) $p['retired'] === 0);
$retired = array_filter($prefixes, fn($p) => (int) $p['retired'] === 1);
?>
<?= component('page-header', [
    'title' => 'Code logs',
    'lede' => 'One log per prefix, newest first: the replacement for the Security log, the UV log and the other sheets. The E number log lists every formula code.',
    'actions' => component('button', ['label' => 'Code lookup', 'href' => '/codes'])
        . (can('codes.export') && $total > 0 ? ' ' . component('button', ['label' => 'Export CSV', 'size' => 'sm', 'href' => '/codes/logs?export=csv']) . ' ' . component('button', ['label' => 'Export Excel', 'size' => 'sm', 'href' => '/codes/logs?export=xlsx']) : ''),
]) ?>

<?php if ($total === 0): ?>
  <div class="card"><?= component('empty-state', ['title' => 'No codes have been logged yet', 'text' => 'Once the legacy spreadsheets are imported, or the first code is assigned, each prefix gets its own log here.', 'action_href' => can('codes.assign_sell', 'codes.assign_internal') ? (is_easy() ? '/wizard/quick-assign' : '/assign') : null, 'action_label' => 'Assign the first code']) ?></div>
<?php else: ?>
<div class="prefix-grid">
  <?php foreach ($active as $p): ?>
    <a class="prefix-card" href="/codes/logs/<?= e($p['code']) ?>">
      <span class="pc-code"><?= e($p['code']) ?></span>
      <span class="pc-label"><?= e($p['label']) ?></span>
      <span class="pc-count"><?= plural($p['count'], 'code') ?><?= $p['voided'] ? ' · ' . (int) $p['voided'] . ' voided' : '' ?><?= $p['last'] ? '<br>Last: ' . e(fmt_date($p['last'])) : '' ?></span>
    </a>
  <?php endforeach; ?>
</div>
<?php if ($retired): ?>
  <h2 class="mt-6" style="font-size:var(--fs-l)">Retired prefixes</h2>
  <p class="muted small">No new numbers are given out under these. Their logs stay for reference.</p>
  <div class="prefix-grid">
    <?php foreach ($retired as $p): ?>
      <a class="prefix-card is-retired" href="/codes/logs/<?= e($p['code']) ?>">
        <span class="pc-code"><?= e($p['code']) ?></span>
        <span class="pc-label"><?= e($p['label']) ?></span>
        <span class="pc-count"><?= plural($p['count'], 'code') ?></span>
      </a>
    <?php endforeach; ?>
  </div>
<?php endif; ?>
<?php endif; ?>
