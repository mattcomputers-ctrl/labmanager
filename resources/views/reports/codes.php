<?php $view->start('head'); ?><link rel="stylesheet" href="/assets/css/reports.css"><?php $view->end(); ?>
<?= component('page-header', ['title' => $title, 'lede' => $lede, 'breadcrumbs' => [['Reports', '/reports'], [$title, null]]]) ?>
<?= $view->partial('reports/_toolbar', [
    'name' => $name, 'filters' => $filters, 'options' => $options, 'problems' => $problems,
    'show' => ['month', 'prefix'],
    'tables' => ['pivot' => 'Table'],
]) ?>
<?php $months = $data['months']; $labels = $data['month_labels']; $tot = $data['totals_row']; $e = $data['e_row']; ?>
<?php if ($tot['total'] === 0 && $e['total'] === 0): ?>
  <div class="card"><?= component('empty-state', ['title' => 'No codes assigned in these 12 months', 'text' => 'This report counts sell numbers and E numbers by the date they were assigned. Pick a different last month, or clear the prefix filter.', 'action_href' => '/reports/codes', 'action_label' => 'Show the last 12 months']) ?></div>
<?php else: ?>
  <div class="report-stats">
    <div class="card stat"><div class="stat-value"><?= number_format($tot['total']) ?></div><div class="stat-label">Sell numbers, <?= e($labels[0]) ?> – <?= e(end($labels)) ?></div></div>
    <div class="card stat"><div class="stat-value"><?= number_format($e['total']) ?></div><div class="stat-label">E numbers in the same months</div></div>
    <div class="card stat"><div class="stat-value"><?= number_format($tot['voided'] + $e['voided']) ?></div><div class="stat-label">Of those, later voided</div></div>
  </div>
  <section class="report-section">
    <h2>Sell numbers per month</h2>
    <?php if (is_easy()): ?><p class="section-note">Each column is one month. The number above the column is how many sell numbers were given out that month; the exact figures per prefix are in the table underneath.</p><?php endif; ?>
    <div class="col-chart" role="img" aria-label="Sell numbers assigned per month; the table below has the same numbers">
      <?php foreach ($months as $m): $n = (int) $data['month_totals'][$m]; $h = $data['max_month'] > 0 ? max(2, (int) round($n / $data['max_month'] * 100)) : 2; ?>
        <div class="col"><span class="v"><?= $n ?></span><span class="b" style="height:<?= $h ?>%"></span></div>
      <?php endforeach; ?>
    </div>
    <div class="col-labels" aria-hidden="true"><?php foreach ($labels as $l): ?><span><?= e($l) ?></span><?php endforeach; ?></div>
  </section>
  <section class="report-section">
    <h2>Per prefix</h2>
    <?php $cellMax = 0; foreach ($data['rows'] as $r) { foreach ($months as $m) { $cellMax = max($cellMax, (int) $r[$m]); } } foreach ($months as $m) { $cellMax = max($cellMax, (int) $e[$m]); }
      $heat = function (int $n) use ($cellMax): string { if ($n === 0) { return 'heat zero'; } $f = $cellMax > 0 ? $n / $cellMax : 0; return 'heat ' . ($f > 0.75 ? 'h4' : ($f > 0.5 ? 'h3' : ($f > 0.25 ? 'h2' : 'h1'))); }; ?>
    <div class="table-wrap<?= is_expert() ? ' table-dense' : '' ?>"><table class="table pivot">
      <thead><tr><th>Prefix</th><?php foreach ($labels as $l): ?><th class="num"><?= e($l) ?></th><?php endforeach; ?><th class="num">Total</th><th class="num">Voided</th></tr></thead>
      <tbody>
      <?php foreach ($data['rows'] as $r): ?>
        <tr>
          <td><strong><?= e($r['prefix']) ?></strong><?php if (is_expert()): ?> <span class="muted small"><?= e($r['label']) ?></span><?php endif; ?></td>
          <?php foreach ($months as $m): ?><td class="<?= $heat((int) $r[$m]) ?>"><?= (int) $r[$m] ?></td><?php endforeach; ?>
          <td class="num"><strong><?= (int) $r['total'] ?></strong></td>
          <td class="num muted"><?= (int) $r['voided'] ?></td>
        </tr>
      <?php endforeach; ?>
        <tr class="total-row"><td>All sell numbers</td><?php foreach ($months as $m): ?><td class="num"><?= (int) $tot[$m] ?></td><?php endforeach; ?><td class="num"><?= (int) $tot['total'] ?></td><td class="num muted"><?= (int) $tot['voided'] ?></td></tr>
        <tr class="total-row"><td><strong>E numbers</strong></td><?php foreach ($months as $m): ?><td class="<?= $heat((int) $e[$m]) ?>"><?= (int) $e[$m] ?></td><?php endforeach; ?><td class="num"><strong><?= (int) $e['total'] ?></strong></td><td class="num muted"><?= (int) $e['voided'] ?></td></tr>
      </tbody>
    </table></div>
    <p class="muted small" style="margin-top:var(--s-3)">Voided codes are still counted as assigned (the number was used up); the Voided column says how many of them were voided afterwards. Retired prefixes with no codes in these months are left out.</p>
  </section>
<?php endif; ?>
