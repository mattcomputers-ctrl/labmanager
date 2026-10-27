<?php $wrap = $first ? 'login-page' : 'page-narrow'; ?>
<div class="<?= $wrap ?>">
  <div class="card <?= $first ? 'login-card' : '' ?>">
    <div class="card-body">
      <h1 style="font-size:var(--fs-2xl)"><?= $first ? 'Choose your password' : 'Change password' ?></h1>
      <?php if ($first): ?><p class="muted">Welcome to Chroma. Before you start, replace the temporary password you were given.</p><?php endif; ?>
      <form method="post" action="/account/password" novalidate>
        <?= csrf_field() ?>
        <?= component('form/field', ['name' => 'current_password', 'label' => $first ? 'Temporary password' : 'Current password', 'type' => 'password', 'required' => true, 'autofocus' => true, 'attrs' => ['autocomplete' => 'current-password']]) ?>
        <?= component('form/field', ['name' => 'password', 'label' => 'New password', 'type' => 'password', 'required' => true, 'help' => 'At least 10 characters. A short sentence you will remember works well.', 'attrs' => ['autocomplete' => 'new-password']]) ?>
        <?= component('form/field', ['name' => 'password_confirm', 'label' => 'New password again', 'type' => 'password', 'required' => true, 'attrs' => ['autocomplete' => 'new-password']]) ?>
        <div class="form-actions"><button type="submit" class="btn btn-primary btn-lg">Save new password</button><?php if (!$first): ?><a class="btn btn-ghost" href="/account">Cancel</a><?php endif; ?></div>
      </form>
    </div>
  </div>
</div>
