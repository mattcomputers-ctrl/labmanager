<?php
/** @var string $code @var array $prefix @var array $log @var array $filters @var array $prefixes @var array $query */
$view->start('head'); ?><link rel="stylesheet" href="/assets/css/codes.css"><?php $view->end();
$isE = $code === 'E';
$canExport = can('codes.export');
$base = '/codes/logs/' . $code;
$hasFilter = $filters['q'] !== '' || $filters['show'] !== 'all' || $filters['from'] !== '' || $filters['to'] !== '';
$retired = (int) ($prefix['retired'] ?? 0) === 1;
?>
<?= component('page-header', [
    'breadcrumbs' => [['Code logs', '/codes/logs'], [$isE ? 'E numbers' : $code . ' numbers', null]],
    'title' => $isE ? 'E number log' : $code . ' log — ' . $prefix['label'],
    'badge' => $retired ? component('badge', ['label' => 'Retired', 'color' => 'gray']) : '',
    'lede' => $isE ? 'Every formula code, newest first, with the sell numbers that belong to each.' : (($prefix['description'] ?? '') ?: 'Sell numbers under this prefix, newest first.') . ($retired ? ' This prefix is retired: no new numbers are given out under it.' : ''),
    'actions' => (!$isE && !$retired && can('codes.assign_sell') ? component('button', ['label' => 'Assign a ' . $code . ' number', 'variant' => 'primary', 'href' => is_easy() ? url('/wizard/quick-assign', ['product_mode' => 'new', 'want' => 'sell', 'prefix_id' => $prefix['id']]) : url('/assign', ['product_mode' => 'new', 'want' => 'sell', 'prefix_id' => $prefix['id']])]) : '')
        . ($isE && can('codes.assign_internal') ? component('button', ['label' => 'Assign an E number', 'variant' => 'primary', 'href' => is_easy() ? url('/wizard/quick-assign', ['product_mode' => 'new', 'want' => 'internal']) : url('/assign', ['product_mode' => 'new', 'want' => 'internal'])]) : ''),
]) ?>

<form method="get" action="<?= e($base) ?>" class="table-toolbar">
  <div class="log-switch">
    <label class="sr-only" for="f-log">Which log</label>
    <select id="f-log" onchange="location.href='/codes/logs/' + this.value">
      <?php foreach ($prefixes as $p): ?><option value="<?= e($p['code']) ?>"<?= $p['code'] === $code ? ' selected' : '' ?>><?= e($p['code']) ?> — <?= e($p['label']) ?> (<?= (int) $p['count'] ?>)</option><?php endforeach; ?>
    </select>
  </div>
  <label class="sr-only" for="f-q">Search this log</label>
  <input type="search" id="f-q" name="q" value="<?= e($filters['q']) ?>" class="grow" placeholder="<?= $isE ? 'E number or a word from the description' : 'Number (' . e($code) . '2449 or 2449) or a word from the description' ?>">
  <div class="filters">
    <?php foreach (['all' => 'All', 'active' => 'Active', 'voided' => 'Voided'] as $k => $label): ?>
      <a class="filter-chip<?= $filters['show'] === $k ? ' on' : '' ?>" href="<?= e(url($base, ['show' => $k === 'all' ? null : $k] + $query)) ?>"><?= e($label) ?></a>
    <?php endforeach; ?>
    <?php if ($filters['show'] !== 'all'): ?><input type="hidden" name="show" value="<?= e($filters['show']) ?>"><?php endif; ?>
    <?php if (is_expert()): ?>
      <label class="sr-only" for="f-from">From date</label><input type="date" id="f-from" name="from" value="<?= e($filters['from']) ?>" title="From date">
      <label class="sr-only" for="f-to">To date</label><input type="date" id="f-to" name="to" value="<?= e($filters['to']) ?>" title="To date">
    <?php endif; ?>
    <button type="submit" class="btn btn-secondary">Filter</button>
    <?php if ($hasFilter): ?><a class="btn btn-ghost" href="<?= e($base) ?>">Clear</a><?php endif; ?>
  </div>
  <?php if ($canExport && $log['total'] > 0): ?>
  <div class="flex" style="gap:var(--s-2)">
    <a class="btn btn-secondary btn-sm" href="<?= e(url($base . '/export', ['format' => 'csv'] + $query)) ?>">Export CSV</a>
    <a class="btn btn-secondary btn-sm" href="<?= e(url($base . '/export', ['format' => 'xlsx'] + $query)) ?>">Export Excel</a>
  </div>
  <?php endif; ?>
</form>

<?php if ($log['total'] === 0): ?>
  <div class="card"><?= component('empty-state', [
      'title' => $hasFilter ? 'Nothing in this log matches' : 'Nothing in this log yet',
      'text' => $hasFilter ? 'Try fewer letters, a different number, or clear the filters.' : ($isE ? 'E numbers appear here as soon as they are assigned or imported.' : 'Sell numbers under ' . $code . ' appear here as soon as they are assigned or imported.'),
      'action_href' => $hasFilter ? $base : null, 'action_label' => 'Clear filters',
  ]) ?></div>
<?php else: ?>
<div class="table-wrap">
  <table class="table<?= is_expert() ? ' table-dense' : '' ?>">
    <?php if ($isE): ?>
    <thead><tr><th>E number</th><th>Description</th><th>Sell numbers</th><th>Customer</th><th>Date</th><th>Assigned by</th><?php if (is_expert()): ?><th>Resale</th><th>Source</th><?php endif; ?></tr></thead>
    <tbody>
    <?php foreach ($log['rows'] as $r): $void = (int) $r['voided'] === 1; ?>
      <tr class="clickable<?= $void ? ' void-row' : '' ?>" data-href="/codes/e/<?= (int) $r['id'] ?>">
        <td class="nowrap"><?= component('code-pill', ['code' => $r['display'], 'href' => '/codes/e/' . (int) $r['id'], 'void' => $void]) ?><?= $void ? ' ' . component('badge', ['label' => 'Voided', 'color' => 'red']) : '' ?></td>
        <td><?= e($r['description']) ?><?= $void && $r['void_reason'] ? '<br><span class="muted xs">Voided: ' . e($r['void_reason']) . '</span>' : '' ?></td>
        <td><?php foreach ($r['sells'] as $s): ?><?= component('code-pill', ['code' => $s['display'], 'href' => '/codes/s/' . (int) $s['id'], 'void' => (int) $s['voided'] === 1]) ?> <?php endforeach; ?><?= $r['sells'] ? '' : '<span class="muted">—</span>' ?></td>
        <td><?= e($r['customer'] ?? '') ?></td>
        <td class="nowrap"><?= e(fmt_date($r['date_assigned'])) ?></td>
        <td class="small"><?= e($r['assigned_by_name'] ?? '') ?></td>
        <?php if (is_expert()): ?><td><?= (int) $r['resale'] === 1 ? 'Yes' : '' ?></td><td class="small muted"><?= e(\Chroma\Domain\Codes\CodeSearch::sourceLabel($r['source'])) ?></td><?php endif; ?>
      </tr>
    <?php endforeach; ?>
    </tbody>
    <?php else: ?>
    <thead><tr><th>Sell number</th><th>Description</th><th>E number</th><th>Customer</th><th>Date</th><th>Assigned by</th><?php if (is_expert()): ?><th>Reason</th><th>Source</th><?php endif; ?></tr></thead>
    <tbody>
    <?php foreach ($log['rows'] as $r): $void = (int) $r['voided'] === 1; ?>
      <tr class="clickable<?= $void ? ' void-row' : '' ?>" data-href="/codes/s/<?= (int) $r['id'] ?>">
        <td class="nowrap"><?= component('code-pill', ['code' => $r['display'], 'href' => '/codes/s/' . (int) $r['id'], 'void' => $void]) ?><?= $void ? ' ' . component('badge', ['label' => 'Voided', 'color' => 'red']) : '' ?></td>
        <td><?= e($r['description']) ?><?= $void && $r['void_reason'] ? '<br><span class="muted xs">Voided: ' . e($r['void_reason']) . '</span>' : '' ?></td>
        <td class="nowrap"><?= $r['internal_display'] ? component('code-pill', ['code' => $r['internal_display'], 'href' => '/codes/e/' . (int) $r['internal_code_id'], 'void' => (int) $r['internal_voided'] === 1]) : '<span class="muted">—</span>' ?></td>
        <td><?= e($r['customer'] ?? '') ?></td>
        <td class="nowrap"><?= e(fmt_date($r['date_assigned'])) ?></td>
        <td class="small"><?= e($r['assigned_by_name'] ?? '') ?></td>
        <?php if (is_expert()): ?><td class="small"><?= e($r['reason'] ? (\Chroma\Domain\Codes\CodeAssigner::REASONS[$r['reason']] ?? $r['reason']) : '') ?></td><td class="small muted"><?= e(\Chroma\Domain\Codes\CodeSearch::sourceLabel($r['source'])) ?></td><?php endif; ?>
      </tr>
    <?php endforeach; ?>
    </tbody>
    <?php endif; ?>
  </table>
</div>
<?= component('pagination', ['page' => $log['page'], 'per_page' => $log['per_page'], 'total' => $log['total'], 'base' => $base, 'query' => $query]) ?>
<?php endif; ?>
