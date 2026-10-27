<?php $view->start('head'); ?><link rel="stylesheet" href="/assets/css/admin-users.css"><?php $view->end(); ?>
<?= $view->partial('admin/_nav', ['current' => '/admin/users']) ?>
<?php $addUrl = is_easy() ? '/wizard/add-group' : '/admin/groups/new'; $cat = \Chroma\Core\Permissions::catalogue(); ?>
<?= component('page-header', [
    'title' => 'Groups',
    'lede' => 'A group is a set of people who may do the same things. Put each person in one or more groups.',
    'breadcrumbs' => [['Admin', '/admin'], ['Users', '/admin/users'], ['Groups', null]],
    'actions' => '<a class="btn btn-ghost btn-sm" href="/admin/groups?export=csv">CSV</a><a class="btn btn-ghost btn-sm" href="/admin/groups?export=xlsx">Excel</a>' . component('button', ['label' => 'Add a group', 'href' => $addUrl, 'variant' => 'primary']),
]) ?>

<?php if (!$rows): ?>
  <div class="card"><?= component('empty-state', ['title' => 'No groups yet', 'text' => 'Groups decide what people may do. Run the seeder or add the first group.', 'action_href' => $addUrl, 'action_label' => 'Add a group']) ?></div>
<?php else: ?>
<div class="table-wrap">
  <table class="table">
    <thead><tr><th>Group</th><th>What it is for</th><th class="num">Members</th><?php if (is_expert()): ?><th class="num">Permissions</th><?php endif; ?><th class="actions"></th></tr></thead>
    <tbody>
    <?php foreach ($rows as $g): $href = '/admin/groups/' . (int) $g['id']; ?>
      <tr class="clickable" data-href="<?= e($href) ?>">
        <td><a class="row-link" href="<?= e($href) ?>"><?= e($g['name']) ?></a><?php if ((int) $g['is_system']): ?> <?= component('badge', ['label' => 'Built in', 'color' => 'gray']) ?><?php endif; ?></td>
        <td><?= e($g['description']) ?></td>
        <td class="num"><?= (int) $g['member_count'] ?></td>
        <?php if (is_expert()): ?><td class="num"><?= $g['name'] === 'Administrators' ? 'All' : (int) $g['permission_count'] . ' of ' . count($cat) ?></td><?php endif; ?>
        <td class="actions"><a class="btn btn-ghost btn-sm" href="<?= e($href) ?>">Open</a></td>
      </tr>
    <?php endforeach; ?>
    </tbody>
  </table>
</div>
<p class="help mt-4">Someone in several groups gets everything each of their groups allows. Administrators always have every permission.</p>
<?php endif; ?>
