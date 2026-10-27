<?php
/**
 * Filter bar + export buttons shared by every report.
 * Props: name, filters, options, show (list of: dates, customer, tech, type, prefix, by, status), tables (key => title) for export,
 *        by_options (list of [value,label]) when 'by' is shown, status_options (list of [value,label]) when 'status' is shown, problems
 */
$show = $show ?? [];
$tables = $tables ?? [];
$has = fn(string $k) => in_array($k, $show, true);
$techLabel = fn(array $u) => $u['display_name'] !== '' ? $u['display_name'] : $u['username'];
?>
<?php foreach (($problems ?? []) as $p): ?>
  <?= component('callout', ['type' => 'warning', 'body' => $p]) ?>
<?php endforeach; ?>
<form method="get" action="/reports/<?= e($name) ?>" class="report-filters card" aria-label="Report filters">
  <div class="card-body">
    <div class="report-filter-grid">
      <?php if ($has('dates')): ?>
        <?= component('form/field', ['name' => 'from', 'label' => 'From', 'type' => 'date', 'value' => $filters['from'] ?? '', 'id' => 'rf-from']) ?>
        <?= component('form/field', ['name' => 'to', 'label' => 'To', 'type' => 'date', 'value' => $filters['to'] ?? '', 'id' => 'rf-to']) ?>
      <?php endif; ?>
      <?php if ($has('customer')): ?>
        <?= component('form/field', ['name' => 'customer_id', 'label' => 'Customer', 'type' => 'select', 'value' => $filters['customer_id'] ?? '', 'placeholder' => 'All customers', 'id' => 'rf-customer', 'options' => array_map(fn($c) => [(int) $c['id'], $c['name']], $options['customers'])]) ?>
      <?php endif; ?>
      <?php if ($has('tech')): ?>
        <?= component('form/field', ['name' => 'user_id', 'label' => 'Tech', 'type' => 'select', 'value' => $filters['user_id'] ?? '', 'placeholder' => 'All techs', 'id' => 'rf-tech', 'options' => array_map(fn($u) => [(int) $u['id'], $techLabel($u)], $options['techs'])]) ?>
      <?php endif; ?>
      <?php if ($has('type')): ?>
        <?= component('form/field', ['name' => 'type_id', 'label' => 'Project type', 'type' => 'select', 'value' => $filters['type_id'] ?? '', 'placeholder' => 'All types', 'id' => 'rf-type', 'options' => array_map(fn($t) => [(int) $t['id'], $t['label']], $options['types'])]) ?>
      <?php endif; ?>
      <?php if ($has('prefix')): ?>
        <?= component('form/field', ['name' => 'prefix_id', 'label' => 'Prefix', 'type' => 'select', 'value' => $filters['prefix_id'] ?? '', 'placeholder' => 'All prefixes', 'id' => 'rf-prefix', 'options' => array_map(fn($p) => [(int) $p['id'], $p['code'] . ' — ' . $p['label']], $options['prefixes'])]) ?>
      <?php endif; ?>
      <?php if ($has('month')): ?>
        <?= component('form/field', ['name' => 'to', 'label' => 'Last month shown', 'type' => 'date', 'value' => $filters['to'] ?? '', 'id' => 'rf-to', 'help' => 'Leave blank for the current month. The report always shows 12 months ending here.']) ?>
      <?php endif; ?>
    </div>
    <?php if ($has('by') || $has('status')): ?>
    <div class="report-chip-rows">
      <?php if ($has('by')): ?>
        <div class="filters" role="group" aria-label="Group by">
          <span class="small muted">Group by:</span>
          <?php foreach (($by_options ?? []) as [$bv, $bl]): ?>
            <label class="filter-chip<?= ($filters['by'] ?? '') === $bv ? ' on' : '' ?>"><input type="radio" name="by" value="<?= e($bv) ?>"<?= ($filters['by'] ?? '') === $bv ? ' checked' : '' ?> class="sr-only" onchange="this.form.requestSubmit()"><?= e($bl) ?></label>
          <?php endforeach; ?>
        </div>
      <?php endif; ?>
      <?php if ($has('status')): ?>
        <div class="filters" role="group" aria-label="Project status">
          <span class="small muted">Projects:</span>
          <?php foreach (($status_options ?? []) as [$sv, $sl]): ?>
            <label class="filter-chip<?= ($filters['status'] ?? '') === $sv ? ' on' : '' ?>"><input type="radio" name="status" value="<?= e($sv) ?>"<?= ($filters['status'] ?? '') === $sv ? ' checked' : '' ?> class="sr-only" onchange="this.form.requestSubmit()"><?= e($sl) ?></label>
          <?php endforeach; ?>
        </div>
      <?php endif; ?>
    </div>
    <?php endif; ?>
  </div>
  <div class="card-foot report-filter-actions">
    <div class="flex flex-wrap">
      <?= component('button', ['label' => 'Apply filters', 'variant' => 'primary', 'type' => 'submit']) ?>
      <?= component('button', ['label' => 'Clear', 'variant' => 'ghost', 'href' => '/reports/' . $name]) ?>
    </div>
    <?php if ($tables && can('codes.export')): ?>
    <div class="report-exports">
      <?php $q = array_filter($filters, fn($v) => $v !== null && $v !== ''); ?>
      <?php foreach ($tables as $tk => $tt): ?>
        <span class="report-export-group">
          <span class="small muted"><?= e($tt) ?>:</span>
          <a class="btn btn-secondary btn-sm" href="<?= e(url('/reports/' . $name . '/export', $q + ['table' => $tk, 'format' => 'csv'])) ?>">CSV</a>
          <a class="btn btn-secondary btn-sm" href="<?= e(url('/reports/' . $name . '/export', $q + ['table' => $tk, 'format' => 'xlsx'])) ?>">Excel</a>
        </span>
      <?php endforeach; ?>
    </div>
    <?php endif; ?>
  </div>
</form>
