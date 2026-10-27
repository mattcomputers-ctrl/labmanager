<?php $view->start('head'); ?><link rel="stylesheet" href="/assets/css/admin-users.css"><?php $view->end(); ?>
<?= $view->partial('admin/_nav', ['current' => '/admin/lists']) ?>
<?= component('page-header', [
    'title' => 'Project types',
    'lede' => 'Each type decides which fields a project shows, what its items are called, and which stages and statuses it uses.',
    'breadcrumbs' => [['Admin', '/admin'], ['Lists', '/admin/lists'], ['Project types', null]],
    'actions' => '<a class="btn btn-ghost btn-sm" href="/admin/project-types?export=csv">CSV</a><a class="btn btn-ghost btn-sm" href="/admin/project-types?export=xlsx">Excel</a><a class="btn btn-secondary" href="/admin/item-statuses">Shared item statuses</a>' . component('button', ['label' => 'Add a project type', 'href' => '/admin/project-types/new', 'variant' => 'primary']),
]) ?>

<?php if (!$rows): ?>
  <div class="card"><?= component('empty-state', ['title' => 'No project types', 'text' => 'Chroma needs at least one type (color match, customer issue, evaluation, R&D). Run the seeder or add one.', 'action_href' => '/admin/project-types/new', 'action_label' => 'Add a project type']) ?></div>
<?php else: ?>
<div class="table-wrap">
  <table class="table">
    <thead><tr><th>Type</th><th>What it is for</th><th>Items called</th><?php if (is_expert()): ?><th>Customer</th><th class="num">Stages</th><th>Statuses</th><?php endif; ?><th class="num">Projects</th><th class="actions"></th></tr></thead>
    <tbody>
    <?php foreach ($rows as $t): $href = '/admin/project-types/' . (int) $t['id']; ?>
      <tr class="clickable<?= (int) $t['active'] ? '' : ' void-row' ?>" data-href="<?= e($href) ?>">
        <td><?= component('badge', ['label' => $t['label'], 'color' => $t['color']]) ?><?php if (!(int) $t['active']): ?> <span class="muted small">inactive</span><?php endif; ?><br><span class="muted xs code"><?= e($t['code']) ?></span></td>
        <td><?= e($t['description']) ?></td>
        <td><?= e($t['item_label']) ?></td>
        <?php if (is_expert()): ?>
          <td><?= (int) $t['has_customer'] ? ((int) $t['customer_required'] ? 'Required' : 'Optional') : 'None' ?></td>
          <td class="num"><?= (int) $t['has_stages'] ? (int) $t['stage_count'] : '—' ?></td>
          <td><?= (int) $t['own_status_count'] > 0 ? 'Own (' . (int) $t['own_status_count'] . ')' : 'Shared' ?></td>
        <?php endif; ?>
        <td class="num"><?= (int) $t['project_count'] ?></td>
        <td class="actions"><a class="btn btn-ghost btn-sm" href="<?= e($href) ?>">Open</a></td>
      </tr>
    <?php endforeach; ?>
    </tbody>
  </table>
</div>
<?php endif; ?>

<div class="card mt-6"><div class="card-head"><h3>Shared item statuses</h3><a class="small" href="/admin/item-statuses">Edit the shared list</a></div><div class="card-body">
  <p class="muted small mb-4">Types without their own list use these. Open a type and press "Customize for this type" to give it its own.</p>
  <p class="flex flex-wrap"><?php foreach ($globalStatuses as $s): if (!(int) $s['active']) continue; ?><?= component('badge', ['label' => $s['label'], 'color' => $s['color']]) ?> <?php endforeach; ?></p>
</div></div>
