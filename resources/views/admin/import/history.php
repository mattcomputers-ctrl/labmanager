<?= $view->partial('admin/_nav', ['current' => '/admin/import']) ?>
<?php $view->start('head'); ?><link rel="stylesheet" href="/assets/css/import.css"><?php $view->end(); ?>
<?= component('page-header', [
    'title' => 'Past imports',
    'lede' => 'Every dry run and import, newest first. Open one to read its full report.',
    'breadcrumbs' => [['Admin', '/admin'], ['Import legacy codes', '/admin/import'], ['Past imports', null]],
    'actions' => '<a class="btn btn-primary" href="/admin/import">Import legacy codes</a>',
]) ?>
<div class="table-toolbar">
  <div class="filters">
    <?php foreach (['all' => 'All', 'done' => 'Imports', 'dry_run' => 'Dry runs', 'failed' => 'Failed'] as $k => $label): ?>
      <a class="filter-chip <?= $status === $k ? 'on' : '' ?>" href="<?= e(url('/admin/import/history', ['status' => $k === 'all' ? null : $k])) ?>"><?= e($label) ?></a>
    <?php endforeach; ?>
  </div>
  <span class="grow"></span>
  <a class="btn btn-secondary btn-sm" href="<?= e(url('/admin/import/history', $query + ['export' => 'csv'])) ?>">Export CSV</a>
  <a class="btn btn-secondary btn-sm" href="<?= e(url('/admin/import/history', $query + ['export' => 'xlsx'])) ?>">Export Excel</a>
</div>
<?php if (!$runs): ?>
  <div class="card"><?= component('empty-state', ['title' => 'No imports yet', 'text' => 'When you upload the legacy spreadsheets, each dry run and import is recorded here with its report.', 'action_href' => '/admin/import', 'action_label' => 'Import legacy codes']) ?></div>
<?php else: ?>
<div class="table-wrap"><table class="table">
  <thead><tr><th>Run</th><th>When</th><th>Kind</th><th>Status</th><?php if (is_expert()): ?><th>By</th><th>Files</th><?php endif; ?><th class="num">E# new</th><th class="num">Sell new</th><th class="num">Filled in</th><th class="num">Issues</th><th class="actions"></th></tr></thead>
  <tbody>
  <?php foreach ($runs as $r): $color = match ($r['status']) { 'done' => 'green', 'failed' => 'red', 'committed' => 'green', 'dry_run' => 'blue', default => 'gray' }; $label = match ($r['status']) { 'done' => 'Done', 'failed' => 'Failed', 'dry_run' => 'Waiting to commit', 'committed' => 'Committed', 'superseded' => 'Replaced', 'running' => 'Running', 'committing' => 'Committing', default => ucfirst($r['status']) }; ?>
    <tr class="clickable" data-href="/admin/import/history/<?= $r['id'] ?>">
      <td class="num"><a class="row-link" href="/admin/import/history/<?= $r['id'] ?>">#<?= $r['id'] ?></a></td>
      <td class="nowrap"><?= e($r['started']) ?></td>
      <td><?= e($r['kind']) ?></td>
      <td><?= component('badge', ['label' => $label, 'color' => $color]) ?></td>
      <?php if (is_expert()): ?><td><?= e($r['by']) ?></td><td class="truncate" style="max-width:260px" title="<?= e($r['files']) ?>"><?= e($r['files']) ?></td><?php endif; ?>
      <td class="num"><?= number_format((int) $r['create_internal']) ?></td>
      <td class="num"><?= number_format((int) $r['create_sell']) ?></td>
      <td class="num"><?= number_format((int) $r['update_internal'] + (int) $r['update_sell']) ?></td>
      <td class="num"><?= number_format((int) $r['issues']) ?></td>
      <td class="actions"><a class="btn btn-ghost btn-sm" href="<?= $r['status'] === 'dry_run' ? '/admin/import/review/' . $r['id'] : '/admin/import/history/' . $r['id'] ?>"><?= $r['status'] === 'dry_run' ? 'Review' : 'Open' ?></a></td>
    </tr>
  <?php endforeach; ?>
  </tbody>
</table></div>
<?= component('pagination', ['page' => $paging['page'], 'per_page' => $paging['per_page'], 'total' => $total, 'base' => '/admin/import/history', 'query' => $query]) ?>
<?php endif; ?>
