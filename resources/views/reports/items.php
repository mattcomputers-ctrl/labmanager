<?php $view->start('head'); ?><link rel="stylesheet" href="/assets/css/reports.css"><?php $view->end(); ?>
<?= component('page-header', ['title' => $title, 'lede' => $lede, 'breadcrumbs' => [['Reports', '/reports'], [$title, null]]]) ?>
<?= $view->partial('reports/_toolbar', [
    'name' => $name, 'filters' => $filters, 'options' => $options, 'problems' => $problems,
    'show' => ['dates', 'customer', 'tech', 'type', 'by', 'status'],
    'by_options' => [['type', 'Project type'], ['tech', 'Tech'], ['customer', 'Customer']],
    'status_options' => [['open', 'Open'], ['completed', 'Completed'], ['all', 'All']],
    'tables' => ['summary' => 'Table'],
]) ?>
<?php $rows = $data['tables']['summary']['rows']; $groupLabel = $data['tables']['summary']['columns']['group']; ?>
<?php if ($data['total'] === 0): ?>
  <?php
    $q = array_filter($filters, fn($v) => $v !== null && $v !== '' && $v !== false);
    $others = array_diff_key($q, ['status' => 1, 'by' => 1]);
    if ($data['status'] !== 'all') {
        $empty = ['text' => sprintf('This report counts the colors, trials and other items inside projects. No %s project matching these filters has any items yet.', $data['status']), 'action_href' => url('/reports/items', ['status' => 'all'] + $q), 'action_label' => 'Include ' . ($data['status'] === 'open' ? 'completed' : 'open') . ' projects'];
    } elseif ($others !== []) {
        $empty = ['text' => 'This report counts the colors, trials and other items inside projects. No project of any status matches these filters, or none of them has items yet.', 'action_href' => url('/reports/items', ['status' => 'all', 'by' => $filters['by'] ?? 'type']), 'action_label' => 'Clear filters'];
    } else {
        $empty = ['text' => 'This report counts the colors, trials and other items inside projects. No project has any items yet.', 'action_href' => '/projects', 'action_label' => 'Go to projects'];
    }
  ?>
  <div class="card"><?= component('empty-state', ['title' => 'No items to count'] + $empty) ?></div>
<?php else: ?>
  <div class="report-stats">
    <div class="card stat"><div class="stat-value"><?= number_format($data['done']) ?> <span class="muted" style="font-size:var(--fs-l);font-weight:500">of <?= number_format($data['total']) ?></span></div><div class="stat-label">Items done on <?= e($data['status'] === 'all' ? 'all' : $data['status']) ?> projects</div></div>
    <div class="card stat"><div class="stat-value"><?= (int) $data['percent'] ?>%</div><div class="stat-label">Done overall</div></div>
    <div class="card stat"><div class="stat-value"><?= number_format($data['total'] - $data['done']) ?></div><div class="stat-label">Still to do</div></div>
  </div>
  <section class="report-section">
    <h2>By <?= e(strtolower($groupLabel)) ?></h2>
    <?php if (is_easy()): ?><p class="section-note">The green part of each bar is items that are done (matched, approved, rejected or not needed); the grey part is still to do.</p><?php endif; ?>
    <div class="table-wrap<?= is_expert() ? ' table-dense' : '' ?>"><table class="table">
      <thead><tr><th><?= e($groupLabel) ?></th><?php if (is_expert()): ?><th class="num">Projects</th><?php endif; ?><th>Done vs total</th><th class="num">Done</th><th class="num">To do</th><th class="num">Total</th><th class="num">% done</th><?php if (is_expert()): ?><th class="num">Matched / approved</th><?php endif; ?></tr></thead>
      <tbody>
      <?php foreach ($rows as $r): $dp = $r['total'] ? $r['done'] / $r['total'] * 100 : 0; ?>
        <tr>
          <td><?= e($r['group']) ?></td>
          <?php if (is_expert()): ?><td class="num"><?= (int) $r['projects'] ?></td><?php endif; ?>
          <td class="bar-cell"><div class="rbar-stack" aria-hidden="true"><span class="done" style="width:<?= number_format($dp, 1, '.', '') ?>%"></span><span class="open" style="width:<?= number_format(100 - $dp, 1, '.', '') ?>%"></span></div></td>
          <td class="num"><?= (int) $r['done'] ?></td>
          <td class="num"><?= (int) $r['remaining'] ?></td>
          <td class="num"><?= (int) $r['total'] ?></td>
          <td class="num"><?= (int) $r['percent'] ?>%</td>
          <?php if (is_expert()): ?><td class="num muted"><?= (int) $r['succeeded'] ?></td><?php endif; ?>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table></div>
  </section>
<?php endif; ?>
