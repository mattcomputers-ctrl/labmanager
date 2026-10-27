<div class="login-page">
  <div class="card login-card">
    <div class="card-body">
      <a class="brand mb-6" href="/" style="display:inline-flex"><span class="brand-mark" aria-hidden="true"></span>Chroma</a>
      <h1 style="font-size:var(--fs-2xl)">Sign in</h1>
      <p class="muted"><?= e(setting('company_name')) ?> color lab</p>
      <form method="post" action="/login" novalidate>
        <?= csrf_field() ?>
        <input type="hidden" name="next" value="<?= e($next ?? '') ?>">
        <?= component('form/field', ['name' => 'username', 'label' => 'Username', 'required' => true, 'autofocus' => true, 'attrs' => ['autocomplete' => 'username', 'autocapitalize' => 'none', 'spellcheck' => 'false']]) ?>
        <?= component('form/field', ['name' => 'password', 'label' => 'Password', 'type' => 'password', 'required' => true, 'attrs' => ['autocomplete' => 'current-password']]) ?>
        <button type="submit" class="btn btn-primary btn-lg btn-block">Sign in</button>
      </form>
      <p class="help mt-4">Forgot your password? Ask an administrator to reset it.</p>
    </div>
  </div>
</div>
