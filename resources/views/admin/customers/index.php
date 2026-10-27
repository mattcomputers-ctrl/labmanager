<?php $view->start('head'); ?><link rel="stylesheet" href="/assets/css/admin-users.css"><?php $view->end(); ?>
<?= $view->partial('admin/_nav', ['current' => '/admin/customers']) ?>
<?php $query = array_filter($filters, fn($v) => $v !== null && $v !== '' && $v !== 'active'); $sources = ['manual' => 'Added by hand', 'import' => 'From the spreadsheet import', 'cms' => 'From the CMS', 'system' => 'Built in']; ?>
<?= component('page-header', [
    'title' => 'Customers',
    'lede' => 'The customer names used on projects and codes. Most come from the CMS; you can add, rename and merge duplicates here.',
    'actions' => component('button', ['label' => 'Add a customer', 'href' => '/admin/customers/new', 'variant' => 'primary']),
]) ?>

<form method="get" action="/admin/customers" class="table-toolbar" role="search">
  <input type="search" name="q" class="grow" value="<?= e($filters['q']) ?>" placeholder="Search by name or CMS id" aria-label="Search customers" data-autofocus>
  <div class="filters">
    <?php foreach (['active' => 'Active', 'inactive' => 'Deactivated', 'all' => 'All'] as $k => $l): ?>
      <a class="filter-chip <?= $filters['status'] === $k ? 'on' : '' ?>" href="<?= e(url('/admin/customers', ['status' => $k, 'q' => $filters['q'], 'source' => $filters['source']])) ?>"><?= e($l) ?></a>
    <?php endforeach; ?>
    <select name="source" aria-label="Source" onchange="this.form.submit()">
      <option value="">Any source</option>
      <?php foreach ($sources as $k => $l): ?><option value="<?= e($k) ?>"<?= $filters['source'] === $k ? ' selected' : '' ?>><?= e($l) ?></option><?php endforeach; ?>
    </select>
    <input type="hidden" name="status" value="<?= e($filters['status']) ?>">
    <button class="btn btn-secondary" type="submit">Search</button>
  </div>
  <div class="filters">
    <a class="btn btn-ghost btn-sm" href="<?= e(url('/admin/customers', $query + ['export' => 'csv'])) ?>">CSV</a>
    <a class="btn btn-ghost btn-sm" href="<?= e(url('/admin/customers', $query + ['export' => 'xlsx'])) ?>">Excel</a>
  </div>
</form>

<?php if (!$rows): ?>
  <div class="card"><?= component('empty-state', [
      'title' => $filters['q'] !== '' ? 'No customers match' : 'No customers yet',
      'text' => $filters['q'] !== '' ? 'Try fewer letters. Deactivated customers are hidden unless you pick "All".' : 'Customers arrive from the CMS sync and the legacy import. You can also add one by hand.',
      'action_href' => $filters['q'] === '' ? '/admin/customers/new' : null, 'action_label' => 'Add a customer',
  ]) ?></div>
<?php else: ?>
<div class="table-wrap">
  <table class="table">
    <thead><tr><th>Customer</th><th>Source</th><?php if (is_expert()): ?><th>CMS id</th><?php endif; ?><th class="num">Projects</th><th class="num">Codes</th><th>Status</th><th class="actions"></th></tr></thead>
    <tbody>
    <?php foreach ($rows as $c): $href = '/admin/customers/' . (int) $c['id']; ?>
      <tr class="clickable<?= (int) $c['active'] ? '' : ' void-row' ?>" data-href="<?= e($href) ?>">
        <td><a class="row-link" href="<?= e($href) ?>"><?= e($c['name']) ?></a></td>
        <td><?= e($sources[$c['source']] ?? ucfirst((string) $c['source'])) ?></td>
        <?php if (is_expert()): ?><td class="code"><?= e($c['cms_customer_id'] ?? '') ?></td><?php endif; ?>
        <td class="num"><?= (int) $c['project_count'] ?></td>
        <td class="num"><?= (int) $c['code_count'] ?></td>
        <td><?= (int) $c['active'] ? component('badge', ['label' => 'Active', 'color' => 'green']) : component('badge', ['label' => 'Deactivated', 'color' => 'gray']) ?></td>
        <td class="actions"><a class="btn btn-ghost btn-sm" href="<?= e($href) ?>">Open</a></td>
      </tr>
    <?php endforeach; ?>
    </tbody>
  </table>
</div>
<?= component('pagination', ['page' => $paging['page'], 'per_page' => $paging['per_page'], 'total' => $total, 'base' => '/admin/customers', 'query' => $query]) ?>
<?php endif; ?>
