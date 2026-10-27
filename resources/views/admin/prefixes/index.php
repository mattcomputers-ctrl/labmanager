<?php
/** @var list<array<string,mixed>> $rows @var array{q:string,status:string} $filters @var array<string,int> $counts @var array{page:int,per_page:int,offset:int} $paging @var int $total */
$view->start('head'); ?><link rel="stylesheet" href="/assets/css/prefix-admin.css"><?php $view->end();
$addHref = is_easy() ? '/wizard/add-prefix' : '/admin/prefixes/new';
$query = array_filter(['q' => $filters['q'], 'status' => $filters['status'] === 'all' ? null : $filters['status']]);
$chip = fn(string $status, string $label) => '<a class="filter-chip' . ($filters['status'] === $status ? ' on' : '') . '" href="' . e(url('/admin/prefixes', ['q' => $filters['q'], 'status' => $status === 'all' ? null : $status])) . '">' . e($label) . ' <span class="xs">' . (int) $counts[$status] . '</span></a>';
?>
<?= $view->partial('admin/_nav', ['current' => '/admin/prefixes']) ?>
<?= component('page-header', [
    'title' => 'Prefixes',
    'lede' => 'The letters that start every sell number, each with its own counter. Retired prefixes stay in lookup and the logs but cannot be used for new codes.',
    'actions' => component('button', ['label' => 'Add a prefix', 'href' => $addHref, 'variant' => 'primary']),
]) ?>

<form method="get" action="/admin/prefixes" class="table-toolbar" role="search">
  <label class="sr-only" for="prefix-q">Search prefixes</label>
  <input id="prefix-q" type="search" name="q" class="grow" placeholder="Search by letters or name…" value="<?= e($filters['q']) ?>">
  <?php if ($filters['status'] !== 'all'): ?><input type="hidden" name="status" value="<?= e($filters['status']) ?>"><?php endif; ?>
  <div class="filters"><?= $chip('all', 'All') ?><?= $chip('active', 'Active') ?><?= $chip('retired', 'Retired') ?></div>
  <button type="submit" class="btn btn-secondary btn-sm">Search</button>
  <div class="export-links">
    <a class="btn btn-ghost btn-sm" href="<?= e(url('/admin/prefixes/export', ['format' => 'csv'])) ?>">Export CSV</a>
    <a class="btn btn-ghost btn-sm" href="<?= e(url('/admin/prefixes/export', ['format' => 'xlsx'])) ?>">Export Excel</a>
  </div>
</form>

<?php if (!$rows): ?>
  <div class="card">
  <?php if ($counts['all'] === 0): ?>
    <?= component('empty-state', ['title' => 'No prefixes yet', 'text' => 'A prefix is the letters that start a sell number, like Y for yellow. Add the first one and codes can be given out under it.', 'action_href' => $addHref, 'action_label' => 'Add a prefix']) ?>
  <?php else: ?>
    <?= component('empty-state', ['title' => 'No prefixes match', 'text' => 'Nothing matches that search or filter. Clear it to see every prefix, active and retired.', 'action_href' => '/admin/prefixes', 'action_label' => 'Show all prefixes']) ?>
  <?php endif; ?>
  </div>
<?php else: ?>
<div class="table-wrap">
  <table class="table prefix-table">
    <thead>
      <tr>
        <th>Prefix</th>
        <th>Name</th>
        <?php if (is_expert()): ?><th>What it is for</th><th>Last used</th><?php endif; ?>
        <th>Next number</th>
        <?php if (is_expert()): ?><th class="num">Codes</th><?php endif; ?>
        <th>Suffixes</th>
        <th class="actions"><span class="sr-only">Actions</span></th>
      </tr>
    </thead>
    <tbody>
    <?php foreach ($rows as $p): $retired = (int) $p['retired'] === 1; $href = '/admin/prefixes/' . (int) $p['id']; ?>
      <tr class="clickable<?= $retired ? ' void-row prefix-retired' : '' ?>" data-href="<?= e($href) ?>">
        <td class="nowrap">
          <a class="row-link code prefix-code" href="<?= e($href) ?>"><?= e($p['code']) ?></a>
          <?php if ($retired): ?><span class="muted small">(retired)</span><?php endif; ?>
          <?php if ($p['tag'] === 'inherited'): ?> <?= component('badge', ['label' => 'Inherited', 'color' => 'purple']) ?><?php elseif ($p['tag'] === 'legacy'): ?> <?= component('badge', ['label' => 'Legacy', 'color' => 'amber']) ?><?php endif; ?>
        </td>
        <td><?= e($p['label']) ?></td>
        <?php if (is_expert()): ?>
          <td class="truncate prefix-desc"><?= e($p['description']) ?></td>
          <td class="nowrap"><?= $p['last_used_display'] !== '' ? '<span class="code">' . e($p['last_used_display']) . '</span>' : '<span class="muted">none yet</span>' ?></td>
        <?php endif; ?>
        <td class="nowrap"><?php if ($retired): ?><span class="muted">—</span><?php else: ?><span class="code"><?= e($p['next_display']) ?></span><?php endif; ?></td>
        <?php if (is_expert()): ?><td class="num"><?= number_format($p['code_count']) ?></td><?php endif; ?>
        <td><?php if ($p['suffix_codes']): foreach ($p['suffixes'] as $s): ?><span class="badge badge-<?= (int) $s['active'] ? 'blue' : 'gray' ?>" title="<?= e($s['label']) ?><?= (int) $s['active'] ? '' : ' (switched off)' ?>"><?= e($s['code']) ?></span> <?php endforeach; else: ?><span class="muted small">none</span><?php endif; ?></td>
        <td class="actions">
          <a class="btn btn-ghost btn-sm" href="<?= e($href) ?>"><?= is_easy() ? 'Open' : 'Edit' ?></a>
          <?php if (is_expert()): ?>
            <?php if ($retired): ?>
              <?= component('confirm-form', ['action' => $href . '/unretire', 'label' => 'Un-retire', 'variant' => 'primary', 'size' => 'sm', 'title' => 'Un-retire ' . $p['code'] . '?', 'body' => sprintf('%s goes back into the prefix list and new %s numbers can be given out again, starting at %s. This is logged.', $p['code'], $p['code'], $p['next_display']), 'confirm_label' => 'Yes, un-retire it', 'fields' => ['return' => '/admin/prefixes' . ($query ? '?' . http_build_query($query) : '')]]) ?>
            <?php else: ?>
              <?= component('confirm-form', ['action' => $href . '/retire', 'label' => 'Retire', 'size' => 'sm', 'title' => 'Retire ' . $p['code'] . '?', 'body' => sprintf('No new %s numbers can be given out until it is un-retired. %s This is logged and can be undone with one click.', $p['code'], $p['code_count'] > 0 ? sprintf('The %s already assigned stay exactly as they are, in lookup and in the logs.', plural($p['code_count'], 'code')) : 'No codes have been given out under it yet, so nothing else changes.'), 'confirm_label' => 'Yes, retire it', 'fields' => ['return' => '/admin/prefixes' . ($query ? '?' . http_build_query($query) : '')]]) ?>
            <?php endif; ?>
          <?php endif; ?>
        </td>
      </tr>
    <?php endforeach; ?>
    </tbody>
  </table>
</div>
<?= component('pagination', ['page' => $paging['page'], 'per_page' => $paging['per_page'], 'total' => $total, 'base' => '/admin/prefixes', 'query' => $query]) ?>
<?php endif; ?>
<p class="help mt-4">Inherited and legacy tags are reminders from the old spreadsheets; they do not change how a prefix works. Need help? <a href="/help/admin-prefixes">Read about prefixes, suffixes and sequences</a>.</p>
