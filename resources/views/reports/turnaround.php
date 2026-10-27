<?php $view->start('head'); ?><link rel="stylesheet" href="/assets/css/reports.css"><?php $view->end(); ?>
<?= component('page-header', ['title' => $title, 'lede' => $lede, 'breadcrumbs' => [['Reports', '/reports'], [$title, null]]]) ?>
<?= $view->partial('reports/_toolbar', [
    'name' => $name, 'filters' => $filters, 'options' => $options, 'problems' => $problems,
    'show' => ['dates', 'customer', 'tech', 'type', 'by'],
    'by_options' => [['customer', 'Customer'], ['tech', 'Tech'], ['type', 'Project type']],
    'tables' => ['summary' => 'Summary', 'projects' => 'Project list'],
]) ?>
<?php $summary = $data['tables']['summary']; $o = $data['overall']; ?>
<?php if ($o === null): ?>
  <div class="card"><?= component('empty-state', ['title' => 'No completed projects in this range', 'text' => 'Turnaround is measured from the date a project was submitted to the date it was completed, so it only counts projects that are marked completed with both dates filled in. Widen the date range or clear the filters.', 'action_href' => '/reports/turnaround', 'action_label' => 'Clear filters']) ?></div>
<?php else: ?>
  <div class="report-stats">
    <div class="card stat"><div class="stat-value"><?= (int) $o['count'] ?></div><div class="stat-label">Projects completed <?= e(fmt_date($filters['from'])) ?> – <?= e(fmt_date($filters['to'])) ?></div></div>
    <div class="card stat"><div class="stat-value"><?= e((string) $o['average']) ?></div><div class="stat-label">Average days, submitted to completed</div></div>
    <div class="card stat"><div class="stat-value"><?= e((string) $o['median']) ?></div><div class="stat-label">Median days (half were faster)</div></div>
    <div class="card stat"><div class="stat-value"><?= (int) $o['fastest'] ?> – <?= (int) $o['slowest'] ?></div><div class="stat-label">Fastest and slowest, in days</div></div>
  </div>
  <section class="report-section">
    <h2><?= e($summary['title']) ?></h2>
    <?php if (is_easy()): ?><p class="section-note">The bar shows the average number of days. A shorter bar means projects finished sooner.</p><?php endif; ?>
    <div class="table-wrap<?= is_expert() ? ' table-dense' : '' ?>"><table class="table">
      <thead><tr><th><?= e($summary['columns']['group']) ?></th><th class="num">Completed</th><th>Average days</th><th class="num">Average</th><th class="num">Median</th><th class="num">Fastest</th><th class="num">Slowest</th></tr></thead>
      <tbody>
      <?php foreach ($summary['rows'] as $r): ?>
        <tr>
          <td><?= e($r['group']) ?></td>
          <td class="num"><?= (int) $r['count'] ?></td>
          <td class="bar-cell"><?= component('report-bar', ['value' => $r['average'], 'max' => $data['max_average']]) ?></td>
          <td class="num"><?= e((string) $r['average']) ?></td>
          <td class="num"><?= e((string) $r['median']) ?></td>
          <td class="num"><?= (int) $r['fastest'] ?></td>
          <td class="num"><?= (int) $r['slowest'] ?></td>
        </tr>
      <?php endforeach; ?>
        <tr class="total-row"><td><strong>All projects</strong></td><td class="num"><strong><?= (int) $o['count'] ?></strong></td><td class="bar-cell"></td><td class="num"><strong><?= e((string) $o['average']) ?></strong></td><td class="num"><strong><?= e((string) $o['median']) ?></strong></td><td class="num"><?= (int) $o['fastest'] ?></td><td class="num"><?= (int) $o['slowest'] ?></td></tr>
      </tbody>
    </table></div>
  </section>
  <section class="report-section">
    <h2>Completed projects in this range</h2>
    <div class="table-wrap<?= is_expert() ? ' table-dense' : '' ?>"><table class="table">
      <thead><tr><th>#</th><th>Project</th><?php if (is_expert()): ?><th>Type</th><?php endif; ?><th>Customer</th><th>Tech</th><th>Submitted</th><th>Completed</th><th class="num">Days</th></tr></thead>
      <tbody>
      <?php foreach ($data['projects'] as $p): ?>
        <tr class="clickable" data-href="/projects/<?= (int) $p['id'] ?>">
          <td class="num"><a class="row-link" href="/projects/<?= (int) $p['id'] ?>"><?= (int) $p['project_number'] ?></a></td>
          <td class="truncate" style="max-width:320px"><?= e($p['title']) ?></td>
          <?php if (is_expert()): ?><td><?= e($p['type']) ?></td><?php endif; ?>
          <td><?= e($p['customer']) ?></td>
          <td><?= e($p['tech']) ?></td>
          <td class="nowrap"><?= e(fmt_date($p['date_submitted'])) ?></td>
          <td class="nowrap"><?= e(fmt_date($p['date_completed'])) ?></td>
          <td class="num"><?= max(0, (int) $p['days']) ?></td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table></div>
  </section>
<?php endif; ?>
