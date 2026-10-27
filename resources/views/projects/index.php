<?php
/** @var list<array<string,mixed>> $rows */
use Chroma\Domain\Projects\Projects;
$view->start('head'); ?><link rel="stylesheet" href="/assets/css/projects.css"><script src="/assets/js/projects.js"></script><?php $view->end();
$newUrl = is_easy() ? '/wizard/new-project' : '/projects/new';
$statusColor = ['open' => 'blue', 'completed' => 'green', 'cancelled' => 'gray'];
// Column defaults: "Completed" is always "—" on the Open list, so it is off there (on for Completed / All); Target and
// Stage are empty for most types. Under 1024px (tablet) the chooser also starts with Tech and Completed off ('narrow')
// so Status, the list's main filter, stays on screen; the chooser can bring them back at any width.
$columns = [
    ['key' => 'number', 'label' => 'Project #', 'on' => true],
    ['key' => 'type', 'label' => 'Type', 'on' => true],
    ['key' => 'customer', 'label' => 'Customer', 'on' => true],
    ['key' => 'title', 'label' => 'Title / description', 'on' => true],
    ['key' => 'submitted', 'label' => 'Submitted', 'on' => true],
    ['key' => 'completed', 'label' => 'Completed', 'on' => $filters['status'] !== 'open', 'narrow' => false],
    ['key' => 'target', 'label' => 'Target', 'on' => false],
    ['key' => 'tech', 'label' => 'Tech', 'on' => true, 'narrow' => false],
    ['key' => 'items', 'label' => 'Items done / total', 'on' => true],
    ['key' => 'stage', 'label' => 'Stage', 'on' => false],
    ['key' => 'status', 'label' => 'Status', 'on' => true],
];
$exportQuery = $query;
unset($exportQuery['per_page']);
?>
<?= component('page-header', [
    'title' => 'Projects',
    'lede' => is_easy() ? 'Every job the lab has been asked to do. Open projects are shown first; use the filters to find older ones.' : null,
    'actions' => can('projects.create') ? component('button', ['label' => 'New project', 'variant' => 'primary', 'href' => $newUrl, 'size' => is_easy() ? 'lg' : null]) : '',
]) ?>

<?php if (!$anyProjects): ?>
  <?= component('empty-state', ['title' => 'No projects yet', 'text' => 'A project is one request from a customer (or one R&D effort): "match these colors", "find out why this ink is not drying". Each project holds the colors or items inside it.', 'action_href' => can('projects.create') ? $newUrl : null, 'action_label' => 'Start the first project']) ?>
<?php else: ?>

<form method="get" action="/projects" class="card mb-4" style="padding:var(--s-3) var(--s-4)" x-data="{ more: <?= $filtered && ($filters['customer_id'] || $filters['tech'] || $filters['from'] || $filters['to'] || $filters['type']) ? 'true' : 'false' ?> }">
  <div class="table-toolbar" style="margin-bottom:0">
    <label class="sr-only" for="pq">Search projects</label>
    <input id="pq" type="search" name="q" class="grow" value="<?= e($filters['q']) ?>" placeholder="Search by project number, words, or any code (E9102, Y1715…)" autocomplete="off">
    <div class="filters">
      <?php foreach (['open' => 'Open', 'completed' => 'Completed', 'cancelled' => 'Cancelled', 'all' => 'All'] as $k => $l): ?>
        <a class="filter-chip <?= $filters['status'] === $k ? 'on' : '' ?>" href="<?= e(url('/projects', ['status' => $k === 'open' ? null : $k] + array_diff_key($query, ['status' => 1, 'page' => 1]))) ?>"><?= e($l) ?></a>
      <?php endforeach; ?>
      <a class="filter-chip <?= $filters['mine'] ? 'on' : '' ?>" href="<?= e(url('/projects', ['mine' => $filters['mine'] ? null : 1] + array_diff_key($query, ['mine' => 1, 'page' => 1]))) ?>">Mine</a>
      <button type="button" class="filter-chip" :class="{ on: more }" @click="more = !more" aria-expanded="false" :aria-expanded="more">More filters</button>
    </div>
    <button type="submit" class="btn btn-secondary">Search</button>
  </div>
  <div class="projects-filters mt-3" x-show="more" x-cloak>
    <?php if ($filters['status'] !== 'open'): ?><input type="hidden" name="status" value="<?= e($filters['status']) ?>"><?php endif; ?>
    <?php if ($filters['mine']): ?><input type="hidden" name="mine" value="1"><?php endif; ?>
    <?= component('form/field', ['name' => 'type', 'label' => 'Type', 'type' => 'select', 'placeholder' => 'Any type', 'value' => $filters['type'], 'options' => array_map(fn($t) => [$t['code'], $t['label']], array_values($types))]) ?>
    <?= component('form/field', ['name' => 'customer_id', 'label' => 'Customer', 'type' => 'select', 'placeholder' => 'Any customer', 'value' => $filters['customer_id'], 'options' => array_map(fn($c) => [(int) $c['id'], $c['name']], $customers)]) ?>
    <?= component('form/field', ['name' => 'tech', 'label' => 'Tech', 'type' => 'select', 'placeholder' => 'Anyone', 'value' => $filters['tech'], 'options' => array_map(fn($u) => [(int) $u['id'], $u['display_name'] ?: $u['username']], $techs)]) ?>
    <?= component('form/field', ['name' => 'from', 'label' => 'Submitted from', 'type' => 'date', 'value' => $filters['from']]) ?>
    <?= component('form/field', ['name' => 'to', 'label' => 'Submitted to', 'type' => 'date', 'value' => $filters['to']]) ?>
    <div class="field"><label class="label">&nbsp;</label><div class="flex" style="gap:var(--s-2)"><button type="submit" class="btn btn-primary">Apply</button><a class="btn btn-ghost" href="/projects">Clear</a></div></div>
  </div>
</form>

<div class="table-toolbar" x-data="columnChooser(<?= json_attr(['key' => 'projects', 'columns' => $columns]) ?>)">
  <div class="grow muted small"><?= $total === 0 ? 'Nothing matches.' : plural($total, 'project') . ($filtered ? ' match' : ' ' . ($filters['status'] === 'all' ? 'in total' : $filters['status'])) ?></div>
  <?php if (can('codes.export')): ?>
  <a class="btn btn-secondary btn-sm" href="<?= e(url('/projects/export', ['format' => 'csv'] + $exportQuery)) ?>">Export CSV</a>
  <a class="btn btn-secondary btn-sm" href="<?= e(url('/projects/export', ['format' => 'xlsx'] + $exportQuery)) ?>">Export Excel</a>
  <?php endif; ?>
  <?php if (is_expert()): ?>
  <div class="menu col-menu" @click.outside="open = false" @chroma:escape.document="open = false">
    <button type="button" class="btn btn-secondary btn-sm" @click="open = !open" :aria-expanded="open">Columns</button>
    <div class="menu-panel" x-show="open" x-cloak>
      <template x-for="c in columns" :key="c.key"><label><input type="checkbox" :checked="show(c.key)" @change="toggle(c.key)"> <span x-text="c.label"></span></label></template>
      <button type="button" class="btn btn-ghost btn-sm" @click="reset()">Reset</button>
    </div>
  </div>
  <?php endif; ?>

  <?php if ($rows): ?>
  <div class="table-wrap w-full<?= is_expert() ? ' table-dense' : '' ?>">
    <table class="table projects-table">
      <thead>
        <tr>
          <th x-show="show('number')">#</th>
          <th x-show="show('type')">Type</th>
          <th x-show="show('customer')">Customer</th>
          <th x-show="show('title')"><?= is_easy() ? 'Project' : 'Title / description' ?></th>
          <?php if (is_expert()): ?>
          <th x-show="show('submitted')">Submitted</th>
          <th x-show="show('completed')">Completed</th>
          <th x-show="show('target')">Target</th>
          <th x-show="show('tech')">Tech</th>
          <?php else: ?>
          <th>Submitted</th>
          <?php endif; ?>
          <th x-show="show('items')">Progress</th>
          <?php if (is_expert()): ?><th x-show="show('stage')">Stage</th><?php endif; ?>
          <th x-show="show('status')">Status</th>
        </tr>
      </thead>
      <tbody>
      <?php foreach ($rows as $p): $href = '/projects/' . (int) $p['id']; ?>
        <tr class="clickable" data-href="<?= e($href) ?>">
          <td class="num" data-label="Project #" x-show="show('number')"><a class="row-link" href="<?= e($href) ?>"><?= (int) $p['project_number'] ?></a><?php if ((int) $p['confidential']): ?> <span title="Confidential" aria-label="Confidential">🔒</span><?php endif; ?></td>
          <td data-label="Type" x-show="show('type')"><?= component('badge', ['label' => $p['type_label'], 'color' => $p['type_color']]) ?></td>
          <td class="customer-cell" data-label="Customer" x-show="show('customer')" title="<?= e($p['customer_name'] ?? '') ?>"><?= e($p['customer_name'] ?? '—') ?></td>
          <td class="title-cell" data-label="Project" x-show="show('title')"><div class="proj-title truncate" title="<?= e($p['title']) ?>"><?= e($p['title']) ?></div><?php if ($p['summary'] !== ''): ?><div class="proj-desc truncate"><?= e(mb_strimwidth($p['summary'], 0, is_easy() ? 90 : 140, '…')) ?></div><?php endif; ?></td>
          <?php if (is_expert()): ?>
          <td class="nowrap" x-show="show('submitted')"><?= e(fmt_date($p['date_submitted'])) ?></td>
          <td class="nowrap" x-show="show('completed')"><?= e(fmt_date($p['date_completed'])) ?: '<span class="muted">—</span>' ?></td>
          <td class="nowrap" x-show="show('target')"><?= e(fmt_date($p['target_date'])) ?: '<span class="muted">—</span>' ?></td>
          <td x-show="show('tech')"><?= e($p['tech_name'] ?? '—') ?></td>
          <?php else: ?>
          <td class="nowrap" data-label="Submitted"><?= e(fmt_date($p['date_submitted'])) ?></td>
          <?php endif; ?>
          <td class="progress-cell" data-label="Progress" x-show="show('items')">
            <?php if ((int) $p['item_count'] > 0): ?>
            <div class="progress" title="<?= e($p['progress_label']) ?>"><span style="width:<?= (int) $p['pct'] ?>%"></span></div>
            <div class="progress-label"><?= e($p['progress_label']) ?></div>
            <?php else: ?><span class="muted small">No <?= e(mb_strtolower((string) $p['item_label'])) ?> yet</span><?php endif; ?>
          </td>
          <?php if (is_expert()): ?><td x-show="show('stage')"><?= e($p['stage_label'] ?? '') ?: '<span class="muted">—</span>' ?></td><?php endif; ?>
          <td data-label="Status" x-show="show('status')"><?= component('badge', ['label' => Projects::STATUSES[$p['status']] ?? $p['status'], 'color' => $statusColor[$p['status']] ?? 'gray', 'dot' => $p['status'] === 'open']) ?></td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
  </div>
  <div class="w-full"><?= component('pagination', ['page' => $paging['page'], 'per_page' => $paging['per_page'], 'total' => $total, 'base' => '/projects', 'query' => $query]) ?></div>
  <?php else: ?>
  <div class="w-full"><?= component('empty-state', ['title' => $filters['q'] !== '' ? 'Nothing matches "' . $filters['q'] . '"' : 'No ' . ($filters['status'] === 'all' ? '' : $filters['status'] . ' ') . 'projects match', 'text' => $filters['q'] !== '' ? 'Search matches project numbers, words in the title or description, and any E number or sell number held by a project. Try fewer words, or check the status filter.' : 'Try another status, clear the filters, or start a new project.', 'action_href' => $filtered ? '/projects' : (can('projects.create') ? $newUrl : null), 'action_label' => $filtered ? 'Clear filters' : 'New project']) ?></div>
  <?php endif; ?>
</div>
<?php endif; ?>
