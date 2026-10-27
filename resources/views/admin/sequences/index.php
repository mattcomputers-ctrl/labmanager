<?php
/** @var array{internal:array<string,mixed>,project:array<string,mixed>} $status @var list<array<string,mixed>> $history */
$view->start('head'); ?><link rel="stylesheet" href="/assets/css/prefix-admin.css"><?php $view->end();
$e = $status['internal'];
$pj = $status['project'];
?>
<?= $view->partial('admin/_nav', ['current' => '/admin/sequences']) ?>
<?= component('page-header', [
    'title' => 'Sequences',
    'lede' => 'The two shared counters: the next E number and the next project number. Each can be moved to skip a block, but never to a number that has already been given out. Prefix counters are set on each prefix.',
    'actions' => can('codes.export') ? component('button', ['label' => 'Export CSV', 'size' => 'sm', 'href' => '/admin/sequences?export=csv']) . ' ' . component('button', ['label' => 'Export Excel', 'size' => 'sm', 'href' => '/admin/sequences?export=xlsx']) : '',
]) ?>

<div class="grid grid-2 prefix-edit-grid">
  <form method="post" action="/admin/sequences/internal" class="card" novalidate>
    <div class="card-head"><h2>E numbers</h2></div>
    <div class="card-body">
      <?= csrf_field() ?>
      <div class="grid grid-2 seq-stats">
        <div class="stat"><div class="stat-value code"><?= e($e['highest_display'] !== '' ? $e['highest_display'] : '—') ?></div><div class="stat-label">Highest E number used<?= $e['highest_display'] === '' ? ' (none yet)' : '' ?></div></div>
        <div class="stat"><div class="stat-value code"><?= e($e['next_display']) ?></div><div class="stat-label">Next E number to be given out</div></div>
      </div>
      <?= component('form/field', ['name' => 'next_value', 'label' => 'Set the next E number to', 'type' => 'number', 'tip' => \Chroma\Http\Tips::NEXT_NUMBER, 'min' => $e['highest'] + 1, 'value' => old('next_value', (string) $e['next']), 'prefix' => 'E', 'required' => true, 'attrs' => ['style' => 'max-width:200px'], 'help' => sprintf('Whole number only, at least %d. Use this to start a new block, for example after the legacy import.', $e['highest'] + 1)]) ?>
    </div>
    <div class="card-foot form-actions"><button type="submit" class="btn btn-primary">Move the E counter</button></div>
  </form>

  <form method="post" action="/admin/sequences/project" class="card" novalidate>
    <div class="card-head"><h2>Project numbers</h2></div>
    <div class="card-body">
      <?= csrf_field() ?>
      <div class="grid grid-2 seq-stats">
        <div class="stat"><div class="stat-value"><?= $pj['highest'] > 0 ? (int) $pj['highest'] : '—' ?></div><div class="stat-label">Highest project number used<?= $pj['highest'] === 0 ? ' (none yet)' : '' ?></div></div>
        <div class="stat"><div class="stat-value"><?= (int) $pj['next'] ?></div><div class="stat-label">Next project number</div></div>
      </div>
      <?= component('form/field', ['name' => 'project_next', 'label' => 'Set the next project number to', 'type' => 'number', 'tip' => \Chroma\Http\Tips::NEXT_NUMBER, 'min' => $pj['highest'] + 1, 'value' => old('project_next', (string) $pj['next']), 'required' => true, 'attrs' => ['style' => 'max-width:200px'], 'help' => sprintf('Whole number only, at least %d. New projects count up from here.', $pj['highest'] + 1)]) ?>
    </div>
    <div class="card-foot form-actions"><button type="submit" class="btn btn-primary">Move the project counter</button></div>
  </form>
</div>

<div class="card mt-4">
  <div class="card-head"><h2>Recent changes</h2></div>
  <?php if ($history): ?>
  <ul class="list history-list">
    <?php foreach ($history as $h): ?>
      <li><span class="grow"><?= e($h['note'] ?: $h['action']) ?><br><span class="muted small"><?= e($h['user_name'] ?: 'Unknown') ?> · <?= e(fmt_datetime($h['created_at'])) ?></span></span></li>
    <?php endforeach; ?>
  </ul>
  <?php else: ?>
    <div class="card-body"><p class="muted mb-0">Neither counter has been moved by hand yet. Every change made here is logged with who made it and when.</p></div>
  <?php endif; ?>
</div>
<p class="help mt-4">Why can't a counter go back to a used number? Numbers are never reused: a mistaken code is voided, not recycled. You can move a counter up to skip a block, or back down as far as the number after the highest one used. <a href="/help/admin-prefixes">Read more</a>.</p>
