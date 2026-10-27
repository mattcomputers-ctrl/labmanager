<?php $view->start('head'); ?><link rel="stylesheet" href="/assets/css/reports.css"><?php $view->end(); ?>
<?= component('page-header', ['title' => $title, 'lede' => $lede, 'breadcrumbs' => [['Reports', '/reports'], [$title, null]]]) ?>
<?= $view->partial('reports/_toolbar', [
    'name' => $name, 'filters' => $filters, 'options' => $options, 'problems' => $problems,
    'show' => ['dates', 'tech', 'status'],
    'status_options' => [['open', 'Open'], ['completed', 'Completed'], ['cancelled', 'Cancelled'], ['all', 'All']],
    'tables' => ['stages' => 'Stages', 'projects' => 'Project list'],
]) ?>
<?php $stages = $data['tables']['stages']['rows']; ?>
<?php if ($data['total'] === 0): ?>
  <?php
    $q = array_filter($filters, fn($v) => $v !== null && $v !== '' && $v !== false);
    $others = array_diff_key($q, ['status' => 1]);
    if ($data['status'] !== 'all') {
        $empty = ['text' => $data['status'] === 'open' ? 'There are no open R&D projects matching these filters. Try "All" to include released and shelved work.' : sprintf('No %s R&D projects match these filters. Try "All" to see every stage.', $data['status']), 'action_href' => url('/reports/rnd', ['status' => 'all'] + $others), 'action_label' => 'Show all R&D projects'];
    } elseif ($others !== []) {
        $empty = ['text' => 'No R&D project of any status matches these filters. Clear the tech and date filters to see all of them.', 'action_href' => '/reports/rnd?status=all', 'action_label' => 'Clear filters'];
    } else {
        $empty = ['text' => 'There are no R&D projects yet. They appear here once one is started from the Projects page.', 'action_href' => '/projects', 'action_label' => 'Go to projects'];
    }
  ?>
  <div class="card"><?= component('empty-state', ['title' => 'No R&D projects here'] + $empty) ?></div>
<?php else: ?>
  <section class="report-section">
    <h2>Projects in each stage <span class="muted small">(<?= (int) $data['total'] ?> <?= e($data['status'] === 'all' ? '' : $data['status']) ?> R&amp;D projects)</span></h2>
    <?php if (is_easy()): ?><p class="section-note">Stages run from idea to released or shelved. The bar shows how many projects sit in each stage right now.</p><?php endif; ?>
    <div class="table-wrap<?= is_expert() ? ' table-dense' : '' ?>"><table class="table">
      <thead><tr><th>Stage</th><th>Projects</th><th class="num">Projects</th><th class="num">Average days since submitted</th></tr></thead>
      <tbody>
      <?php foreach ($stages as $s): ?>
        <tr><td><?= e($s['stage']) ?><?= (int) $s['is_terminal'] === 1 ? ' <span class="muted small">(final)</span>' : '' ?></td><td class="bar-cell"><?= component('report-bar', ['value' => $s['count'], 'max' => $data['max'], 'tone' => (int) $s['is_terminal'] === 1 ? 'muted' : 'primary']) ?></td><td class="num"><?= (int) $s['count'] ?></td><td class="num"><?= e((string) $s['avg_age']) ?></td></tr>
      <?php endforeach; ?>
      </tbody>
    </table></div>
  </section>
  <section class="report-section">
    <h2>The projects</h2>
    <div class="table-wrap<?= is_expert() ? ' table-dense' : '' ?>"><table class="table">
      <thead><tr><th>#</th><th>Project</th><th>Stage</th><?php if (is_expert()): ?><th>Status</th><?php endif; ?><th>Lead</th><th>Target</th><th class="num">Days</th><?php if (is_expert()): ?><th class="num">Trials</th><?php endif; ?></tr></thead>
      <tbody>
      <?php foreach ($data['projects'] as $p): $late = $p['target_date'] && $p['status'] === 'open' && $p['target_date'] < today(); ?>
        <tr class="clickable" data-href="/projects/<?= (int) $p['id'] ?>">
          <td class="num"><a class="row-link" href="/projects/<?= (int) $p['id'] ?>"><?= (int) $p['project_number'] ?></a></td>
          <td><?= e($p['title']) ?><?= (int) $p['confidential'] === 1 ? ' ' . component('badge', ['label' => 'Confidential', 'color' => 'purple']) : '' ?></td>
          <td><?= component('badge', ['label' => $p['stage'], 'color' => (int) $p['is_terminal'] === 1 ? 'gray' : 'purple']) ?></td>
          <?php if (is_expert()): ?><td><?= component('badge', ['label' => ucfirst($p['status']), 'color' => $p['status']]) ?></td><?php endif; ?>
          <td><?= e($p['lead'] ?: '—') ?></td>
          <td class="nowrap"><?= $p['target_date'] ? ($late ? component('badge', ['label' => 'Overdue · ' . fmt_date($p['target_date']), 'color' => 'red']) : e(fmt_date($p['target_date']))) : '<span class="muted">—</span>' ?></td>
          <td class="num"><?= (int) $p['age'] ?></td>
          <?php if (is_expert()): ?><td class="num"><?= (int) $p['item_count'] ?></td><?php endif; ?>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table></div>
  </section>
<?php endif; ?>
