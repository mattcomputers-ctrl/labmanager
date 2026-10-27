<?php $view->start('head'); ?><link rel="stylesheet" href="/assets/css/admin-users.css"><?php $view->end(); ?>
<?= $view->partial('admin/_nav', ['current' => '/admin/users']) ?>
<?php $gid = (int) $group['id']; $activeMembers = array_filter($group['members'], fn($m) => (int) $m['active'] === 1); ?>
<?= component('page-header', [
    'title' => $group['name'],
    'lede' => $group['description'] ?: 'No description yet.',
    'breadcrumbs' => [['Admin', '/admin'], ['Users', '/admin/users'], ['Groups', '/admin/groups'], [$group['name'], null]],
    'badge' => (int) $group['is_system'] ? component('badge', ['label' => 'Built in', 'color' => 'gray']) : '',
]) ?>

<div class="tabs" role="navigation" aria-label="Sections">
  <a href="#details">Details</a><a href="#permissions">What it may do</a><a href="#members">Members (<?= count($activeMembers) ?>)</a>
</div>

<div class="card mb-4" id="details"><div class="card-body">
  <form method="post" action="/admin/groups/<?= $gid ?>">
    <?= csrf_field() ?>
    <div class="form-grid">
      <?= component('form/field', ['name' => 'name', 'label' => 'Group name', 'required' => true, 'value' => old('name', $group['name']), 'attrs' => $isAdministrators ? ['readonly' => true, 'maxlength' => 100] : ['maxlength' => 100], 'help' => $isAdministrators ? 'Chroma relies on this name; it cannot change.' : null]) ?>
      <?= component('form/field', ['name' => 'description', 'label' => 'What is it for?', 'optional_text' => true, 'value' => old('description', $group['description']), 'attrs' => ['maxlength' => 255]]) ?>
    </div>
    <div class="form-actions"><button class="btn btn-primary" type="submit">Save details</button></div>
  </form>
</div></div>

<div class="card mb-4" id="permissions">
  <div class="card-head"><h2>What people in this group may do</h2><?php if ($isAdministrators): ?><span class="muted small">Administrators can do everything.</span><?php endif; ?></div>
  <div class="card-body">
    <?php if ($isAdministrators): ?>
      <?= component('callout', ['type' => 'info', 'body' => 'Administrators always hold every permission, including ones added in future updates, so nobody can lock the lab out of these screens. To give someone less, put them in a different group.']) ?>
      <div class="mb-4"></div>
      <?= $view->partial('admin/groups/_permissions', ['areas' => $areas, 'checked' => array_keys(\Chroma\Core\Permissions::catalogue()), 'disabled' => true]) ?>
    <?php else: ?>
      <form method="post" action="/admin/groups/<?= $gid ?>/permissions">
        <?= csrf_field() ?>
        <?php $perms = old('permissions'); $checked = is_array($perms) ? array_keys(array_filter($perms, fn($v) => (string) $v === '1')) : $group['permissions']; ?>
        <?= $view->partial('admin/groups/_permissions', ['areas' => $areas, 'checked' => $checked]) ?>
        <div class="form-actions"><button class="btn btn-primary" type="submit">Save permissions</button><span class="help">Takes effect for everyone in the group on their next page load.</span></div>
      </form>
    <?php endif; ?>
  </div>
</div>

<div class="card mb-4" id="members">
  <div class="card-head"><h2>Members</h2><span class="muted small"><?= plural(count($activeMembers), 'active member') ?></span></div>
  <div class="card-body">
    <?php if ($candidates): ?>
    <form method="post" action="/admin/groups/<?= $gid ?>/members" class="table-toolbar">
      <?= csrf_field() ?>
      <label class="sr-only" for="add-member">Add a person</label>
      <select id="add-member" name="user_id" class="grow" required>
        <option value="">Choose a person to add…</option>
        <?php foreach ($candidates as $c): ?><option value="<?= (int) $c['id'] ?>"><?= e($c['display_name'] ?: $c['username']) ?> (<?= e($c['username']) ?>)</option><?php endforeach; ?>
      </select>
      <button class="btn btn-secondary" type="submit">Add to group</button>
    </form>
    <?php endif; ?>
    <?php if (!$group['members']): ?>
      <?= component('empty-state', ['title' => 'Nobody in this group yet', 'text' => 'Pick a person above to add them. They get what this group allows straight away.']) ?>
    <?php else: ?>
    <ul class="list">
      <?php foreach ($group['members'] as $m): ?>
        <li>
          <span class="grow"><a href="/admin/users/<?= (int) $m['id'] ?>"><?= e($m['display_name'] ?: $m['username']) ?></a> <span class="muted small"><?= e($m['username']) ?></span>
            <?php if (!(int) $m['active']): ?> <?= component('badge', ['label' => 'Deactivated', 'color' => 'gray']) ?><?php endif; ?></span>
          <?php $isSelfRow = (int) $m['id'] === (int) $auth->id(); ?>
          <?php if ($isSelfRow && $group['name'] === 'Administrators'): ?>
            <span class="muted small" title="You cannot remove yourself from the Administrators group. Ask another Administrator to do it.">This is you · ask another Administrator to remove you</span>
          <?php else: ?>
            <?= component('confirm-form', ['action' => '/admin/groups/' . $gid . '/members/' . (int) $m['id'] . '/remove', 'label' => 'Remove', 'size' => 'sm', 'title' => 'Remove ' . ($isSelfRow ? 'yourself' : ($m['display_name'] ?: $m['username'])) . ' from ' . $group['name'] . '?', 'body' => ($isSelfRow ? 'This is your own account: you lose what this group allows as soon as you confirm. ' : 'They lose what this group allows. ') . 'Anything ' . ($isSelfRow ? 'you' : 'they') . ' can do through other groups is unchanged.', 'confirm_label' => 'Yes, remove']) ?>
          <?php endif; ?>
        </li>
      <?php endforeach; ?>
    </ul>
    <?php endif; ?>
  </div>
</div>

<?php if (!(int) $group['is_system']): ?>
<div class="card mb-4"><div class="card-body">
  <h3 class="mt-0">Delete this group</h3>
  <p class="muted small"><?= $group['members'] ? plural(count($group['members']), 'person is', 'people are') . ' in it. They will keep the permissions of their other groups and lose what this one allowed.' : 'Nobody is in it, so deleting it changes nothing for anyone.' ?></p>
  <?= component('confirm-form', ['action' => '/admin/groups/' . $gid . '/delete', 'label' => 'Delete group', 'title' => 'Delete "' . $group['name'] . '"?', 'body' => ($group['members'] ? plural(count($group['members']), 'person', 'people') . ' will be taken out of it and lose what it allowed. ' : '') . 'This cannot be undone, though you can create the group again.', 'confirm_label' => 'Yes, delete it']) ?>
</div></div>
<?php endif; ?>

<?php if (!empty($history)): ?>
<div class="card"><div class="card-head"><h3>History</h3></div><div class="card-body">
  <ul class="feed small">
    <?php foreach ($history as $h): ?>
      <li><span class="muted nowrap"><?= e(fmt_datetime($h['created_at'], 'M j')) ?></span><span><?= e($h['note'] ?: $h['action']) ?> <span class="muted">· <?= e($h['user_name'] ?: 'system') ?></span></span></li>
    <?php endforeach; ?>
  </ul>
</div></div>
<?php endif; ?>
