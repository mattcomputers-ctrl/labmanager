<?php $view->start('head'); ?><link rel="stylesheet" href="/assets/css/admin-users.css"><?php $view->end(); ?>
<?= $view->partial('admin/_nav', ['current' => '/admin/users']) ?>
<?= component('page-header', [
    'title' => 'Reset password for ' . ($user['display_name'] ?: $user['username']),
    'lede' => 'Sets a temporary password. They must choose their own the next time they sign in.',
    'breadcrumbs' => [['Admin', '/admin'], ['Users', '/admin/users'], [$user['username'], '/admin/users/' . (int) $user['id']], ['Reset password', null]],
]) ?>
<div class="page-narrow">
  <div class="card"><div class="card-body">
    <form method="post" action="/admin/users/<?= (int) $user['id'] ?>/password" x-data="{ mode: '<?= e(old('password_mode', 'generate')) ?>' }">
      <?= csrf_field() ?>
      <div @change="if ($event.target.name === 'password_mode') mode = $event.target.value">
      <?= component('form/field', ['name' => 'password_mode', 'type' => 'radio', 'label' => 'Temporary password', 'value' => old('password_mode', 'generate'), 'options' => [
          ['generate', 'Make one up for me', 'Chroma picks a memorable password and shows it once on the next screen.'],
          ['typed', 'I will type one', 'At least 10 characters. A short sentence works well.'],
      ]]) ?>
      </div>
      <div x-show="mode === 'typed'" x-cloak>
        <?= component('form/field', ['name' => 'password', 'type' => 'password', 'label' => 'Temporary password', 'attrs' => ['autocomplete' => 'new-password']]) ?>
        <?= component('form/field', ['name' => 'password_confirm', 'type' => 'password', 'label' => 'Type it again', 'attrs' => ['autocomplete' => 'new-password']]) ?>
      </div>
      <?= component('callout', ['type' => 'info', 'body' => 'They will be signed out of every other device. The old password stops working immediately.']) ?>
      <div class="form-actions">
        <button class="btn btn-primary" type="submit">Set temporary password</button>
        <a class="btn btn-ghost" href="/admin/users/<?= (int) $user['id'] ?>">Cancel</a>
      </div>
    </form>
  </div></div>
</div>
