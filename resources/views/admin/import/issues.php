<?= $view->partial('admin/_nav', ['current' => '/admin/import']) ?>
<?php $view->start('head'); ?><link rel="stylesheet" href="/assets/css/import.css"><?php $view->end(); ?>
<?= component('page-header', [
    'title' => 'Import issues',
    'lede' => 'Rows the legacy import could not place on its own: sell numbers listed twice, codes it could not read, descriptions with no code. Open one to correct, merge or discard it.',
    'breadcrumbs' => [['Admin', '/admin'], ['Import legacy codes', '/admin/import'], ['Import issues', null]],
    'actions' => $firstOpenId ? '<a class="btn btn-primary" href="' . e(url('/admin/import/issues/' . (int) $firstOpenId, $filters['import_id'] ? ['import_id' => (int) $filters['import_id']] : [])) . '">' . ($filters['status'] === 'open' && $issues ? 'Resolve the first one' : ($filters['import_id'] ? 'Resolve this run\'s open issues' : 'Resolve open issues')) . '</a>' : '',
]) ?>
<form method="get" action="/admin/import/issues" class="table-toolbar">
  <?php if ($filters['import_id']): ?><input type="hidden" name="import_id" value="<?= (int) $filters['import_id'] ?>"><?php endif; ?>
  <div class="filters">
    <?php foreach (['open' => 'Open' . ($openTotal ? ' (' . number_format($openTotal) . ')' : ''), 'resolved' => 'Resolved', 'all' => 'All'] as $k => $label): ?>
      <a class="filter-chip <?= $filters['status'] === $k ? 'on' : '' ?>" href="<?= e(url('/admin/import/issues', ['status' => $k === 'open' ? null : $k, 'type' => $filters['type'], 'q' => $filters['q'], 'import_id' => $filters['import_id']])) ?>"><?= e($label) ?></a>
    <?php endforeach; ?>
    <input type="hidden" name="status" value="<?= e($filters['status']) ?>">
    <label class="sr-only" for="f-type">Type</label>
    <select name="type" id="f-type" onchange="this.form.submit()">
      <option value="">All types</option>
      <?php foreach ($types as $k => $label): ?><option value="<?= e($k) ?>"<?= $filters['type'] === $k ? ' selected' : '' ?>><?= e($label) ?><?= isset($openCounts[$k]) ? ' (' . $openCounts[$k] . ' open)' : '' ?></option><?php endforeach; ?>
    </select>
  </div>
  <label class="sr-only" for="f-q">Search issues</label>
  <input type="search" id="f-q" name="q" value="<?= e($filters['q']) ?>" placeholder="Search a code or description…" class="grow">
  <button class="btn btn-secondary" type="submit">Search</button>
  <a class="btn btn-secondary btn-sm" href="<?= e(url('/admin/import/issues', $query + ['export' => 'csv'])) ?>">Export CSV</a>
  <a class="btn btn-secondary btn-sm" href="<?= e(url('/admin/import/issues', $query + ['export' => 'xlsx'])) ?>">Export Excel</a>
</form>
<?php if ($filters['import_id']): ?><p class="small muted">Showing issues from import run #<?= (int) $filters['import_id'] ?>. <a href="<?= e(url('/admin/import/issues', ['status' => $filters['status'] === 'open' ? null : $filters['status']])) ?>">Show all</a></p><?php endif; ?>

<?php if (!$issues): ?>
  <div class="card"><?= component('empty-state', [
      'title' => $filters['status'] === 'open' && !$filters['q'] && !$filters['type'] ? 'No open issues' : 'Nothing matches',
      'text' => $filters['status'] === 'open' && !$filters['q'] && !$filters['type']
          ? 'Every row from the legacy sheets has been placed. Issues appear here only when an import finds a duplicate sell number, a code it cannot read, or a description with no code.'
          : 'Try another filter, or clear the search.',
      'action_href' => $openTotal === 0 && $filters['status'] === 'open' ? '/admin/import' : url('/admin/import/issues', ['status' => 'all']),
      'action_label' => $openTotal === 0 && $filters['status'] === 'open' ? 'Import legacy codes' : 'Show all issues',
  ]) ?></div>
<?php else: ?>
<div class="table-wrap"><table class="table">
  <thead><tr>
    <th>Type</th><th>Code in sheet</th><th>E number</th><th>Description</th>
    <?php if (is_expert()): ?><th>Sheet</th><th class="num">Row</th><th>What happened</th><?php endif; ?>
    <th>Status</th><th class="actions"></th>
  </tr></thead>
  <tbody>
  <?php foreach ($issues as $i): $href = url('/admin/import/issues/' . (int) $i['id'], $filters['import_id'] ? ['import_id' => (int) $filters['import_id']] : []); ?>
    <tr class="clickable <?= $i['status'] !== 'open' ? 'void-row' : '' ?>" data-href="<?= e($href) ?>">
      <td><?= component('badge', ['label' => $i['type_label'], 'color' => $i['status'] === 'open' ? 'amber' : 'gray']) ?></td>
      <td><?= $i['code'] !== '' ? '<span class="code">' . e($i['code']) . '</span>' : '<span class="muted">—</span>' ?></td>
      <td><?= $i['internal'] !== '' ? '<span class="code">' . e($i['internal']) . '</span>' : '<span class="muted">—</span>' ?></td>
      <td class="truncate" style="max-width:280px" title="<?= e($i['description']) ?>"><?= e($i['description'] ?: '—') ?></td>
      <?php if (is_expert()): ?>
        <td class="nowrap small"><?= e($i['source_file']) ?></td><td class="num"><?= (int) $i['source_row'] ?></td>
        <td class="truncate small" style="max-width:360px" title="<?= e($i['message']) ?>"><?= e($i['message']) ?></td>
      <?php endif; ?>
      <td><?= $i['status'] === 'open' ? component('badge', ['label' => 'Open', 'color' => 'amber', 'dot' => true]) : component('badge', ['label' => ucfirst((string) $i['resolution']), 'color' => 'green']) ?></td>
      <td class="actions"><a class="btn btn-ghost btn-sm" href="<?= e($href) ?>"><?= $i['status'] === 'open' ? 'Resolve' : 'Open' ?></a></td>
    </tr>
  <?php endforeach; ?>
  </tbody>
</table></div>
<?= component('pagination', ['page' => $paging['page'], 'per_page' => $paging['per_page'], 'total' => $total, 'base' => '/admin/import/issues', 'query' => $query]) ?>
<?php endif; ?>
