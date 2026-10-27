<?php
/** @var string $q @var array $filters @var array|null $result @var array $prefixes @var array $query */
$view->start('head'); ?><link rel="stylesheet" href="/assets/css/codes.css"><?php $view->end();
$canExport = can('codes.export');
$examples = ['y-1000', 'Y 1000', 'E6374-10', '6374', 'blister purple'];
?>
<?= component('page-header', [
    'title' => 'Code lookup',
    'lede' => 'The cross-reference. Find any E number or sell number by any spelling, a word from the description, or the customer name.',
    'actions' => (can('codes.assign_sell', 'codes.assign_internal') ? component('button', ['label' => 'Assign a code', 'variant' => 'primary', 'href' => is_easy() ? '/wizard/quick-assign' : '/assign']) : '')
        . (can('codes.manual_entry') ? component('button', ['label' => 'Enter a code by hand', 'href' => is_easy() ? '/wizard/manual-entry' : '/codes/manual']) : ''),
]) ?>

<form method="get" action="/codes" role="search" class="card" style="padding:var(--s-4) var(--s-6);margin-bottom:var(--s-5)">
  <label class="sr-only" for="f-q">Search codes</label>
  <div class="lookup-search">
    <input type="search" id="f-q" name="q" value="<?= e($q) ?>" class="input input-lg" placeholder="E number, sell number, description or customer…" autocomplete="off" data-autofocus>
    <button type="submit" class="btn btn-primary btn-lg">Search</button>
  </div>
  <div class="lookup-toolbar" style="margin-bottom:0">
    <div class="filters">
      <?php foreach (['all' => 'All codes', 'active' => 'Active only', 'voided' => 'Voided only'] as $k => $label): ?>
        <a class="filter-chip<?= $filters['show'] === $k ? ' on' : '' ?>" href="<?= e(url('/codes', ['q' => $q, 'show' => $k === 'all' ? null : $k, 'prefix' => $filters['prefix'] ?: null])) ?>"><?= e($label) ?></a>
      <?php endforeach; ?>
      <?php if (is_expert()): ?>
      <label class="sr-only" for="f-prefix">Prefix</label>
      <select id="f-prefix" name="prefix" onchange="this.form.submit()">
        <option value="">Any prefix</option>
        <?php foreach ($prefixes as $p): ?><option value="<?= e($p['code']) ?>"<?= $filters['prefix'] === $p['code'] ? ' selected' : '' ?>><?= e($p['code']) ?> — <?= e($p['label']) ?></option><?php endforeach; ?>
      </select>
      <?php if ($filters['show'] !== 'all'): ?><input type="hidden" name="show" value="<?= e($filters['show']) ?>"><?php endif; ?>
      <?php elseif ($filters['prefix'] !== ''): ?>
        <input type="hidden" name="prefix" value="<?= e($filters['prefix']) ?>">
        <a class="filter-chip on" href="<?= e(url('/codes', ['q' => $q])) ?>" title="Remove the prefix filter">Prefix <?= e($filters['prefix']) ?> &times;</a>
      <?php endif; ?>
    </div>
    <?php if ($result !== null && $result['total'] > 0 && $canExport): ?>
    <div class="flex" style="gap:var(--s-2)">
      <a class="btn btn-secondary btn-sm" href="<?= e(url('/codes/export', ['q' => $q, 'show' => $filters['show'], 'prefix' => $filters['prefix'] ?: null, 'format' => 'csv'])) ?>">Export CSV</a>
      <a class="btn btn-secondary btn-sm" href="<?= e(url('/codes/export', ['q' => $q, 'show' => $filters['show'], 'prefix' => $filters['prefix'] ?: null, 'format' => 'xlsx'])) ?>">Export Excel</a>
    </div>
    <?php endif; ?>
  </div>
</form>

<?php if ($result === null): ?>
  <div class="card">
    <?= component('empty-state', [
        'title' => 'Type a code, a description or a customer',
        'text' => 'Any spelling works: y-1000, Y 1000 and y1000 all find Y1000. E numbers can be typed as E6374, E6374-10 or just 6374. Words find descriptions ("blister purple") and customer names.',
        'icon' => '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round"><circle cx="11" cy="11" r="7"/><path d="m20 20-3.5-3.5"/></svg>',
    ]) ?>
    <div class="spellings" style="padding:0 var(--s-4) var(--s-6)">
      <span class="muted small" style="align-self:center">Try:</span>
      <?php foreach ($examples as $ex): ?><a class="code-pill" href="<?= e(url('/codes', ['q' => $ex])) ?>"><?= e($ex) ?></a><?php endforeach; ?>
    </div>
    <div class="card-foot" style="padding:var(--s-4) var(--s-6);border-top:1px solid var(--border)">
      <div class="muted small" style="margin-bottom:var(--s-2)">Or browse one prefix:</div>
      <div class="filters">
        <?php foreach ($prefixes as $p): if ((int) $p['retired'] === 1) continue; ?><a class="filter-chip" href="<?= e(url('/codes', ['prefix' => $p['code']])) ?>" title="<?= e($p['label']) ?>"><?= e($p['code']) ?></a><?php endforeach; ?>
        <a class="filter-chip" href="/codes/logs">All logs ›</a>
      </div>
    </div>
  </div>
<?php elseif ($result['total'] === 0): ?>
  <div class="card">
    <?= component('empty-state', [
        'title' => $q !== '' ? 'Nothing matches "' . $q . '"' : 'No codes under this prefix yet',
        'text' => $q !== '' ? 'Check the spelling. Sell numbers are prefix + number + optional suffix (Y1000, Y1000UV, X104043); E numbers are E + digits (E6374, E6374-10). A word from the description or a customer name also works. ' . ($filters['show'] !== 'all' ? 'You are only looking at ' . $filters['show'] . ' codes — try "All codes".' : '') : 'Codes assigned under this prefix will appear here.',
        'action_href' => $q !== '' && can('codes.manual_entry') ? (is_easy() ? '/wizard/manual-entry' : '/codes/manual') . '?code=' . rawurlencode($q) : null,
        'action_label' => 'Enter it by hand (legacy item)',
    ]) ?>
  </div>
<?php else: ?>
  <div class="lookup-toolbar">
    <div class="count"><?= plural($result['total'], 'product') ?> match<?= $result['total'] === 1 ? 'es' : '' ?><?= $q !== '' ? ' "' . e($q) . '"' : '' ?><?= $filters['prefix'] !== '' ? ' under ' . e($filters['prefix']) : '' ?>. Each card is one product: its E number with every sell number that belongs to it.</div>
  </div>
  <div class="product-list">
    <?php foreach ($result['products'] as $product): ?>
      <?= $view->partial('codes/_product', ['product' => $product]) ?>
    <?php endforeach; ?>
  </div>
  <?= component('pagination', ['page' => $result['page'], 'per_page' => $result['per_page'], 'total' => $result['total'], 'base' => '/codes', 'query' => $query]) ?>
<?php endif; ?>
