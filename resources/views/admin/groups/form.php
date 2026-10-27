<?php $view->start('head'); ?><link rel="stylesheet" href="/assets/css/admin-users.css"><?php $view->end(); ?>
<?= $view->partial('admin/_nav', ['current' => '/admin/users']) ?>
<?= component('page-header', [
    'title' => 'Add a group',
    'lede' => 'Name it, tick what it may do, then add people from the group page.',
    'breadcrumbs' => [['Admin', '/admin'], ['Users', '/admin/users'], ['Groups', '/admin/groups'], ['New', null]],
    'actions' => is_expert() ? '<a class="btn btn-ghost" href="/wizard/add-group">Walk me through it</a>' : '',
]) ?>
<?php $perms = old('permissions', []); $checked = is_array($perms) ? array_keys(array_filter($perms, fn($v) => (string) $v === '1')) : []; ?>
<form method="post" action="/admin/groups">
  <?= csrf_field() ?>
  <div class="card mb-4"><div class="card-body">
    <div class="form-grid">
      <?= component('form/field', ['name' => 'name', 'label' => 'Group name', 'required' => true, 'value' => old('name', $request->query['name'] ?? ''), 'placeholder' => 'e.g. Press room', 'autofocus' => true, 'attrs' => ['maxlength' => 100]]) ?>
      <?= component('form/field', ['name' => 'description', 'label' => 'What is it for?', 'optional_text' => true, 'value' => old('description', $request->query['description'] ?? ''), 'placeholder' => 'One line that explains who belongs here', 'attrs' => ['maxlength' => 255]]) ?>
    </div>
  </div></div>
  <div class="card mb-4"><div class="card-head"><h2>What may people in this group do?</h2><span class="muted small">Tick everything the group should be allowed to do.</span></div><div class="card-body">
    <?= $view->partial('admin/groups/_permissions', ['areas' => $areas, 'checked' => $checked]) ?>
  </div></div>
  <div class="form-actions">
    <button class="btn btn-primary" type="submit">Create the group</button>
    <a class="btn btn-ghost" href="/admin/groups">Cancel</a>
  </div>
</form>
