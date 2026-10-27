<?php $view->start('head'); ?><link rel="stylesheet" href="/assets/css/admin-users.css"><?php $view->end(); ?>
<?= $view->partial('admin/_nav', ['current' => '/admin/users']) ?>
<?php $addUrl = is_easy() ? '/wizard/add-user' : '/admin/users/new'; $query = array_filter($filters, fn($v) => $v !== null && $v !== '' && $v !== 'active'); ?>
<?= component('page-header', [
    'title' => 'Users',
    'lede' => 'Everyone who can sign in to Chroma, and the groups that decide what each person may do.',
    'actions' => '<a class="btn btn-secondary" href="/admin/groups">Groups</a>' . component('button', ['label' => 'Add a user', 'href' => $addUrl, 'variant' => 'primary']),
]) ?>

<form method="get" action="/admin/users" class="table-toolbar" role="search">
  <input type="search" name="q" class="grow" value="<?= e($filters['q']) ?>" placeholder="Search by username, name or email" aria-label="Search users">
  <div class="filters">
    <?php foreach (['active' => 'Active', 'inactive' => 'Deactivated', 'all' => 'Everyone'] as $k => $l): ?>
      <a class="filter-chip <?= $filters['status'] === $k ? 'on' : '' ?>" href="<?= e(url('/admin/users', ['status' => $k, 'q' => $filters['q'], 'group_id' => $filters['group_id']])) ?>"><?= e($l) ?></a>
    <?php endforeach; ?>
    <select name="group_id" aria-label="Group" onchange="this.form.submit()">
      <option value="">Any group</option>
      <?php foreach ($groups as $g): ?><option value="<?= (int) $g['id'] ?>"<?= (int) $filters['group_id'] === (int) $g['id'] ? ' selected' : '' ?>><?= e($g['name']) ?></option><?php endforeach; ?>
    </select>
    <input type="hidden" name="status" value="<?= e($filters['status']) ?>">
    <button class="btn btn-secondary" type="submit">Search</button>
  </div>
  <div class="filters">
    <a class="btn btn-ghost btn-sm" href="<?= e(url('/admin/users', $query + ['export' => 'csv'])) ?>">CSV</a>
    <a class="btn btn-ghost btn-sm" href="<?= e(url('/admin/users', $query + ['export' => 'xlsx'])) ?>">Excel</a>
  </div>
</form>

<?php if ($adminCount <= 1): ?>
  <?= component('callout', ['type' => 'warning', 'title' => 'Only one Administrator', 'body' => 'If that person is away or forgets their password nobody can manage users. Add a second person to the Administrators group.']) ?>
  <div class="mb-4"></div>
<?php endif; ?>

<?php if (!$rows): ?>
  <div class="card"><?= component('empty-state', [
      'title' => $filters['q'] !== '' || $filters['group_id'] ? 'No users match' : ($filters['status'] === 'inactive' ? 'No deactivated accounts' : 'No users yet'),
      'text' => $filters['q'] !== '' || $filters['group_id'] ? 'Try fewer letters, or clear the group filter.' : 'Each person in the lab, sales and the office gets their own account so the logs show who did what.',
      'action_href' => $filters['q'] === '' && !$filters['group_id'] ? $addUrl : null, 'action_label' => 'Add a user',
  ]) ?></div>
<?php else: ?>
<div class="table-wrap">
  <table class="table">
    <thead><tr>
      <th>Username</th><th>Name</th><th>Groups</th><th>Status</th>
      <?php if (is_expert()): ?><th>Last sign-in</th><th>Password</th><?php endif; ?>
      <th class="actions"></th>
    </tr></thead>
    <tbody>
    <?php foreach ($rows as $u): $href = '/admin/users/' . (int) $u['id']; ?>
      <tr class="clickable<?= (int) $u['active'] ? '' : ' void-row' ?>" data-href="<?= e($href) ?>">
        <td><a class="row-link" href="<?= e($href) ?>"><?= e($u['username']) ?></a></td>
        <td><?= e($u['display_name']) ?><?php if ($u['email'] && is_expert()): ?><br><span class="muted small"><?= e($u['email']) ?></span><?php endif; ?></td>
        <td><?= e($u['groups_text'] ?: '—') ?></td>
        <td><?= (int) $u['active'] ? component('badge', ['label' => 'Active', 'color' => 'green']) : component('badge', ['label' => 'Deactivated', 'color' => 'gray']) ?></td>
        <?php if (is_expert()): ?>
          <td class="nowrap"><?= $u['last_login_at'] ? e(fmt_ago($u['last_login_at'])) : '<span class="muted">Never</span>' ?></td>
          <td><?= (int) $u['must_change_password'] ? component('badge', ['label' => 'Must change', 'color' => 'amber']) : '<span class="muted small">Set</span>' ?></td>
        <?php endif; ?>
        <td class="actions"><a class="btn btn-ghost btn-sm" href="<?= e($href) ?>">Open</a></td>
      </tr>
    <?php endforeach; ?>
    </tbody>
  </table>
</div>
<?= component('pagination', ['page' => $paging['page'], 'per_page' => $paging['per_page'], 'total' => $total, 'base' => '/admin/users', 'query' => $query]) ?>
<?php endif; ?>
