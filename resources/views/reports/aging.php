<?php $view->start('head'); ?><link rel="stylesheet" href="/assets/css/reports.css"><?php $view->end(); ?>
<?= component('page-header', ['title' => $title, 'lede' => $lede, 'breadcrumbs' => [['Reports', '/reports'], [$title, null]]]) ?>
<?= $view->partial('reports/_toolbar', [
    'name' => $name, 'filters' => $filters, 'options' => $options, 'problems' => $problems,
    'show' => ['customer', 'tech', 'type'],
    'tables' => ['buckets' => 'Buckets', 'list' => 'Project list'],
]) ?>
<?php if ($data['total'] === 0): ?>
  <div class="card"><?= component('empty-state', ['title' => 'No open projects', 'text' => 'Every project matching these filters is completed or cancelled, so there is nothing to age. Clear the filters to see all open projects.', 'action_href' => '/reports/aging', 'action_label' => 'Clear filters']) ?></div>
<?php else: ?>
  <?php $maxBucket = max(1, max(array_column($data['buckets'], 'count'))); $q = array_filter($filters, fn($v) => $v !== null && $v !== '' && $v !== false); unset($q['bucket']); ?>
  <div class="report-stats">
    <div class="card stat"><div class="stat-value"><?= (int) $data['total'] ?></div><div class="stat-label">Open projects</div></div>
    <div class="card stat"><div class="stat-value"><?= e((string) ($data['average_age'] ?? 0)) ?></div><div class="stat-label">Average days open</div></div>
    <div class="card stat"><div class="stat-value"><?= (int) ($data['max_age'] ?? 0) ?></div><div class="stat-label">Oldest, in days</div></div>
  </div>
  <section class="report-section">
    <h2>How long they have been open</h2>
    <?php if (is_easy()): ?><p class="section-note">Days are counted from the submitted date to today. Press a bucket to see only those projects in the list below.</p><?php endif; ?>
    <div class="age-buckets">
      <?php foreach ($data['buckets'] as $b): $on = ($filters['bucket'] ?? '') === $b['key']; $tone = match ($b['key']) { '0-7', '8-14' => 'primary', '15-30' => 'primary', '31-60' => 'warning', default => 'danger' }; ?>
        <a class="age-bucket<?= $on ? ' on' : '' ?>" href="<?= e(url('/reports/aging', $on ? $q : $q + ['bucket' => $b['key']])) ?>" aria-pressed="<?= $on ? 'true' : 'false' ?>">
          <span class="n"><?= (int) $b['count'] ?></span> <span class="l"><?= e($b['bucket']) ?> · <?= e((string) $b['share']) ?>%</span>
          <?= component('report-bar', ['value' => $b['count'], 'max' => $maxBucket, 'tone' => $tone]) ?>
        </a>
      <?php endforeach; ?>
    </div>
  </section>
  <section class="report-section">
    <h2><?= !empty($filters['bucket']) ? 'Open projects, ' . e($filters['bucket']) . ' days' : 'All open projects' ?> <span class="muted small">(<?= number_format($data['list_total']) ?>)</span></h2>
    <?php if ($data['list'] === []): ?>
      <?php if ((int) ($data['page'] ?? 1) > 1): ?>
        <?php $first = array_filter($filters, fn($v) => $v !== null && $v !== '' && $v !== false); ?>
        <div class="card"><?= component('empty-state', ['title' => 'This page is past the end of the list', 'text' => sprintf('The list has %s %s, which fit on the earlier pages.', number_format($data['list_total']), $data['list_total'] === 1 ? 'project' : 'projects'), 'action_href' => url('/reports/aging', $first), 'action_label' => 'Go to the first page']) ?></div>
      <?php else: ?>
        <div class="card"><?= component('empty-state', ['title' => 'Nothing in this bucket', 'text' => 'No open project has been waiting that long. Pick another bucket above.', 'action_href' => url('/reports/aging', $q), 'action_label' => 'Show all open projects']) ?></div>
      <?php endif; ?>
    <?php else: ?>
    <div class="table-wrap<?= is_expert() ? ' table-dense' : '' ?>"><table class="table">
      <thead><tr><th>#</th><th>Project</th><th>Customer</th><?php if (is_expert()): ?><th>Tech</th><th>Submitted</th><?php endif; ?><th class="num">Days open</th><th>Progress</th></tr></thead>
      <tbody>
      <?php foreach ($data['list'] as $p): ?>
        <tr class="clickable" data-href="/projects/<?= (int) $p['id'] ?>">
          <td class="num"><a class="row-link" href="/projects/<?= (int) $p['id'] ?>"><?= (int) $p['project_number'] ?></a></td>
          <td><?= component('badge', ['label' => $p['type'], 'color' => $p['type_color']]) ?> <?= e($p['title']) ?><?= (int) $p['confidential'] === 1 ? ' ' . component('badge', ['label' => 'Confidential', 'color' => 'purple']) : '' ?></td>
          <td><?= e($p['customer'] ?: '—') ?></td>
          <?php if (is_expert()): ?><td><?= e($p['tech'] ?: '—') ?></td><td class="nowrap"><?= e(fmt_date($p['date_submitted'])) ?></td><?php endif; ?>
          <td class="num"><strong><?= (int) $p['age'] ?></strong> <span class="muted small"><?= e($p['bucket']) ?></span></td>
          <td style="min-width:140px"><?php $pct = (int) $p['item_count'] ? (int) round((int) $p['items_done'] / (int) $p['item_count'] * 100) : 0; ?><div class="progress" title="<?= e($p['progress']) ?> done"><span style="width:<?= $pct ?>%"></span></div><div class="progress-label"><?= e($p['progress']) ?> done</div></td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table></div>
    <?= component('pagination', ['page' => $data['page'], 'per_page' => $data['per_page'], 'total' => $data['list_total'], 'base' => '/reports/aging', 'query' => array_filter($filters, fn($v) => $v !== null && $v !== '')]) ?>
    <?php endif; ?>
  </section>
<?php endif; ?>
