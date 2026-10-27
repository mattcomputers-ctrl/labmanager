<?php
/** @var list<array<string,mixed>> $rows @var array{q:string,status:string} $filters @var array<string,int> $counts @var array{page:int,per_page:int,offset:int} $paging @var int $total */
$view->start('head'); ?><link rel="stylesheet" href="/assets/css/prefix-admin.css"><?php $view->end();
$query = array_filter(['q' => $filters['q'], 'status' => $filters['status'] === 'all' ? null : $filters['status']]);
$returnTo = '/admin/suffixes' . ($query ? '?' . http_build_query($query) : '');
$chip = fn(string $status, string $label) => '<a class="filter-chip' . ($filters['status'] === $status ? ' on' : '') . '" href="' . e(url('/admin/suffixes', ['q' => $filters['q'], 'status' => $status === 'all' ? null : $status])) . '">' . e($label) . ' <span class="xs">' . (int) $counts[$status] . '</span></a>';
?>
<?= $view->partial('admin/_nav', ['current' => '/admin/suffixes']) ?>
<?= component('page-header', [
    'title' => 'Suffixes',
    'lede' => 'Letters that can go on the end of a sell number, like the UV in Y1715UV. Which prefixes allow each suffix is set on the prefix itself.',
    'actions' => component('button', ['label' => 'Add a suffix', 'href' => '/admin/suffixes/new', 'variant' => 'primary']),
]) ?>

<form method="get" action="/admin/suffixes" class="table-toolbar" role="search">
  <label class="sr-only" for="suffix-q">Search suffixes</label>
  <input id="suffix-q" type="search" name="q" class="grow" placeholder="Search by letters or name…" value="<?= e($filters['q']) ?>">
  <?php if ($filters['status'] !== 'all'): ?><input type="hidden" name="status" value="<?= e($filters['status']) ?>"><?php endif; ?>
  <div class="filters"><?= $chip('all', 'All') ?><?= $chip('active', 'On') ?><?= $chip('inactive', 'Off') ?></div>
  <button type="submit" class="btn btn-secondary btn-sm">Search</button>
  <div class="export-links">
    <a class="btn btn-ghost btn-sm" href="<?= e(url('/admin/suffixes/export', ['format' => 'csv'])) ?>">Export CSV</a>
    <a class="btn btn-ghost btn-sm" href="<?= e(url('/admin/suffixes/export', ['format' => 'xlsx'])) ?>">Export Excel</a>
  </div>
</form>

<?php if (!$rows): ?>
  <div class="card">
  <?php if ($counts['all'] === 0): ?>
    <?= component('empty-state', ['title' => 'No suffixes yet', 'text' => 'A suffix marks a variant of a product, like UV for the energy-curable version. Add one here, then tick it on the prefixes that may use it.', 'action_href' => '/admin/suffixes/new', 'action_label' => 'Add a suffix']) ?>
  <?php else: ?>
    <?= component('empty-state', ['title' => 'No suffixes match', 'text' => 'Nothing matches that search or filter. Clear it to see every suffix.', 'action_href' => '/admin/suffixes', 'action_label' => 'Show all suffixes']) ?>
  <?php endif; ?>
  </div>
<?php else: ?>
<div class="table-wrap">
  <table class="table">
    <thead>
      <tr>
        <th>Suffix</th>
        <th>Name</th>
        <th>Status</th>
        <th>Allowed on</th>
        <?php if (is_expert()): ?><th class="num">Codes using it</th><th class="num">Position</th><?php endif; ?>
        <th class="actions"><span class="sr-only">Actions</span></th>
      </tr>
    </thead>
    <tbody>
    <?php foreach ($rows as $s): $on = (int) $s['active'] === 1; $href = '/admin/suffixes/' . (int) $s['id']; ?>
      <tr class="clickable<?= $on ? '' : ' void-row' ?>" data-href="<?= e($href) ?>">
        <td class="nowrap"><a class="row-link code prefix-code" href="<?= e($href) ?>"><?= e($s['code']) ?></a></td>
        <td><?= e($s['label']) ?></td>
        <td><?= component('badge', ['label' => $on ? 'On' : 'Off', 'color' => $on ? 'green' : 'gray', 'dot' => true]) ?></td>
        <td>
          <?php if ($s['prefixes']): foreach ($s['prefixes'] as $pf): ?>
            <a class="badge badge-<?= (int) $pf['retired'] ? 'gray' : 'blue' ?>" href="/admin/prefixes/<?= (int) $pf['id'] ?>" title="<?= e($pf['label']) ?><?= (int) $pf['retired'] ? ' (retired)' : '' ?>"><?= e($pf['code']) ?></a>
          <?php endforeach; else: ?><span class="muted small">no prefix yet</span><?php endif; ?>
        </td>
        <?php if (is_expert()): ?><td class="num"><?= number_format($s['code_count']) ?></td><td class="num"><?= (int) $s['sort_order'] ?></td><?php endif; ?>
        <td class="actions">
          <a class="btn btn-ghost btn-sm" href="<?= e($href) ?>"><?= is_easy() ? 'Open' : 'Edit' ?></a>
          <?php if ($on): ?>
            <?= component('confirm-form', ['action' => $href . '/active', 'label' => 'Switch off', 'size' => 'sm', 'title' => 'Switch off the ' . $s['code'] . ' suffix?', 'body' => sprintf('New codes will not be able to use %s until it is switched back on. The %s that already end in %s are untouched. This is logged.', $s['code'], plural($s['code_count'], 'code'), $s['code']), 'confirm_label' => 'Yes, switch it off', 'fields' => ['active' => '0', 'return' => $returnTo]]) ?>
          <?php else: ?>
            <form method="post" action="<?= e($href . '/active') ?>" style="display:inline"><?= csrf_field() ?><input type="hidden" name="active" value="1"><input type="hidden" name="return" value="<?= e($returnTo) ?>"><button type="submit" class="btn btn-primary btn-sm">Switch on</button></form>
          <?php endif; ?>
        </td>
      </tr>
    <?php endforeach; ?>
    </tbody>
  </table>
</div>
<?= component('pagination', ['page' => $paging['page'], 'per_page' => $paging['per_page'], 'total' => $total, 'base' => '/admin/suffixes', 'query' => $query]) ?>
<?php endif; ?>
<p class="help mt-4">To let a prefix use a suffix, open the prefix under <a href="/admin/prefixes">Prefixes</a> and tick it there. <a href="/help/admin-prefixes">Read more about suffixes</a>.</p>
