<?php $view->start('head'); ?><link rel="stylesheet" href="/assets/css/reports.css"><?php $view->end(); ?>
<?= component('page-header', ['title' => $title, 'lede' => $lede, 'breadcrumbs' => [['Reports', '/reports'], [$title, null]]]) ?>
<?= $view->partial('reports/_toolbar', [
    'name' => $name, 'filters' => $filters, 'options' => $options, 'problems' => $problems,
    'show' => ['dates', 'customer', 'tech', 'type'],
    'tables' => ['month' => 'Per month', 'tech' => 'Per tech'],
]) ?>
<?php $months = $data['tables']['month']['rows']; $techs = $data['tables']['tech']['rows']; ?>
<?php if ($months === []): ?>
  <div class="card"><?= component('empty-state', ['title' => 'No items in this range', 'text' => 'Proofs are counted on the colors and items inside projects. There are no items dated in this range; widen the dates or clear the filters.', 'action_href' => '/reports/proofs', 'action_label' => 'Clear filters']) ?></div>
<?php else: ?>
  <div class="report-stats">
    <div class="card stat"><div class="stat-value"><?= number_format($data['total_proofs']) ?></div><div class="stat-label">Proofs made <?= e(fmt_date($filters['from'])) ?> – <?= e(fmt_date($filters['to'])) ?></div></div>
    <div class="card stat"><div class="stat-value"><?= number_format($data['total_items_with_proofs']) ?></div><div class="stat-label">Items that had proofs</div></div>
  </div>
  <?php if (is_easy()): ?><p class="muted">An item is dated by the day it was completed, or started, or by the project's submitted date if neither is set.</p><?php endif; ?>
  <div class="grid grid-2">
    <section class="report-section">
      <h2>Per month</h2>
      <div class="table-wrap<?= is_expert() ? ' table-dense' : '' ?>"><table class="table">
        <thead><tr><th>Month</th><th>Proofs</th><th class="num">Proofs</th><th class="num">Items with proofs</th><?php if (is_expert()): ?><th class="num">Items</th><?php endif; ?></tr></thead>
        <tbody>
        <?php foreach ($months as $r): ?>
          <tr><td class="nowrap"><?= e($r['month']) ?></td><td class="bar-cell"><?= component('report-bar', ['value' => $r['proofs'], 'max' => $data['max_month']]) ?></td><td class="num"><?= (int) $r['proofs'] ?></td><td class="num"><?= (int) $r['items_with_proofs'] ?></td><?php if (is_expert()): ?><td class="num muted"><?= (int) $r['items'] ?></td><?php endif; ?></tr>
        <?php endforeach; ?>
        </tbody>
      </table></div>
    </section>
    <section class="report-section">
      <h2>Per tech</h2>
      <div class="table-wrap<?= is_expert() ? ' table-dense' : '' ?>"><table class="table">
        <thead><tr><th>Tech</th><th>Proofs</th><th class="num">Proofs</th><th class="num">Items with proofs</th><?php if (is_expert()): ?><th class="num">Items</th><?php endif; ?></tr></thead>
        <tbody>
        <?php foreach ($techs as $r): ?>
          <tr><td><?= e($r['tech']) ?></td><td class="bar-cell"><?= component('report-bar', ['value' => $r['proofs'], 'max' => $data['max_tech']]) ?></td><td class="num"><?= (int) $r['proofs'] ?></td><td class="num"><?= (int) $r['items_with_proofs'] ?></td><?php if (is_expert()): ?><td class="num muted"><?= (int) $r['items'] ?></td><?php endif; ?></tr>
        <?php endforeach; ?>
        </tbody>
      </table></div>
    </section>
  </div>
<?php endif; ?>
