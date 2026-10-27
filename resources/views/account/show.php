<?= component('page-header', ['title' => 'My account', 'lede' => 'Your name as it appears on projects and in the logs.']) ?>
<div class="grid grid-2">
  <div class="card"><div class="card-body">
    <form method="post" action="/account">
      <?= csrf_field() ?>
      <?= component('form/field', ['name' => 'username', 'label' => 'Username', 'value' => $user['username'], 'attrs' => ['disabled' => true], 'help' => 'Only an administrator can change this.']) ?>
      <?= component('form/field', ['name' => 'display_name', 'label' => 'Your name', 'value' => $user['display_name'], 'required' => true]) ?>
      <?= component('form/field', ['name' => 'initials', 'label' => 'Initials', 'value' => $user['initials'], 'help' => 'Shown on compact lists. Leave blank to use the first letters of your name.', 'attrs' => ['maxlength' => 5, 'style' => 'max-width:120px']]) ?>
      <?= component('form/field', ['name' => 'email', 'label' => 'Email', 'type' => 'email', 'value' => $user['email'], 'optional_text' => true]) ?>
      <div class="form-actions"><button class="btn btn-primary" type="submit">Save my details</button><a class="btn btn-secondary" href="/account/password">Change password</a></div>
    </form>
  </div></div>
  <div class="card"><div class="card-body">
    <h3>What you can do</h3>
    <p class="muted small">You are in: <?= e(implode(', ', $groups) ?: 'no groups yet') ?></p>
    <ul class="list">
      <?php $cat = \Chroma\Core\Permissions::catalogue(); foreach ($permissions as $p): if (!isset($cat[$p])) continue; ?>
        <li><span><strong><?= e($cat[$p]['label']) ?></strong><br><span class="muted small"><?= e($cat[$p]['help']) ?></span></span></li>
      <?php endforeach; ?>
      <?php if (!$permissions): ?><li class="muted">Nothing yet. Ask an administrator to add you to a group.</li><?php endif; ?>
    </ul>
    <p class="mt-4"><a href="/help">Read the help pages</a></p>
  </div></div>
</div>
