<?php $view->start('head'); ?><link rel="stylesheet" href="/assets/css/admin-users.css"><?php $view->end(); ?>
<?= $view->partial('admin/_nav', ['current' => '/admin/users']) ?>
<?php
$isNew = $user === null;
$isSelf = $isSelf ?? false;
$isAdmin = $isAdmin ?? false;
$lastAdmin = $lastAdmin ?? false;
$active = $isNew ? 1 : (int) $user['active'];
$groupIds = old('group_ids', $isNew ? [] : array_fill_keys($user['group_ids'], '1'));
$checkedGroups = is_array($groupIds) ? array_keys(array_filter($groupIds, fn($v) => (string) $v === '1')) : [];
$badge = $isNew ? '' : ($active ? component('badge', ['label' => 'Active', 'color' => 'green']) : component('badge', ['label' => 'Deactivated', 'color' => 'gray']));
?>
<?= component('page-header', [
    'title' => $isNew ? 'Add a user' : ($user['display_name'] ?: $user['username']),
    'lede' => $isNew ? 'Everything on one page. The account is created with a temporary password that is shown once.' : 'Username ' . $user['username'] . ($user['last_login_at'] ? ' · last signed in ' . fmt_ago($user['last_login_at']) : ' · has not signed in yet'),
    'breadcrumbs' => [['Admin', '/admin'], ['Users', '/admin/users'], [$isNew ? 'New' : $user['username'], null]],
    'badge' => $badge,
    'actions' => $isNew && is_expert() ? '<a class="btn btn-ghost" href="/wizard/add-user">Walk me through it</a>' : '',
]) ?>

<?php if (!empty($shownPassword)): ?>
  <div class="card temp-password-card mb-4"><div class="card-body">
    <?= component('code-display', ['code' => $shownPassword, 'label' => 'Temporary password for ' . $user['username'], 'description' => 'Shown once. Give it to them now; they must choose their own password when they next sign in.']) ?>
  </div></div>
<?php endif; ?>

<div class="grid <?= $isNew ? '' : 'grid-2' ?> user-grid">
  <div class="card"><div class="card-body">
    <form method="post" action="<?= $isNew ? '/admin/users' : '/admin/users/' . (int) $user['id'] ?>" x-data="{ mode: '<?= e(old('password_mode', 'generate')) ?>' }">
      <?= csrf_field() ?>
      <h3 class="mt-0">Details</h3>
      <div class="form-grid">
        <?php if ($isNew): ?>
          <?= component('form/field', ['name' => 'username', 'label' => 'Username', 'required' => true, 'value' => old('username', $request->query['username'] ?? ''), 'placeholder' => 'e.g. jsmith', 'help' => 'What they type to sign in. Lowercase, no spaces.', 'autofocus' => true, 'attrs' => ['autocapitalize' => 'off', 'spellcheck' => 'false', 'maxlength' => 100]]) ?>
        <?php else: ?>
          <?= component('form/field', ['name' => 'username_display', 'label' => 'Username', 'value' => $user['username'], 'attrs' => ['disabled' => true], 'help' => 'Usernames do not change; they are how the logs identify people.']) ?>
        <?php endif; ?>
        <?= component('form/field', ['name' => 'display_name', 'label' => 'Full name', 'required' => true, 'value' => old('display_name', $isNew ? ($request->query['display_name'] ?? '') : $user['display_name']), 'placeholder' => 'e.g. Jane Smith', 'attrs' => ['maxlength' => 150]]) ?>
        <?= component('form/field', ['name' => 'email', 'label' => 'Email', 'type' => 'email', 'optional_text' => true, 'value' => old('email', $isNew ? ($request->query['email'] ?? '') : $user['email']), 'attrs' => ['maxlength' => 190]]) ?>
        <?= component('form/field', ['name' => 'initials', 'label' => 'Initials', 'optional_text' => true, 'value' => old('initials', $isNew ? '' : $user['initials']), 'help' => 'Shown on compact lists.', 'attrs' => ['maxlength' => 5, 'style' => 'max-width:120px']]) ?>
      </div>

      <h3>Groups</h3>
      <p class="help mt-0">Groups decide what a person can do. Someone in several groups gets everything each group allows.</p>
      <?php $err = form_errors()['group_ids'] ?? null; if ($err): ?><p class="error-text" role="alert"><?= e($err) ?></p><?php endif; ?>
      <div class="group-checks">
        <?php foreach ($groups as $g): $gid = (int) $g['id']; $isAdminGroup = $g['name'] === 'Administrators'; $selfAdmin = $isAdminGroup && !$isNew && !empty($isSelf) && in_array($gid, $checkedGroups, true); $lock = $isAdminGroup && (!$auth->isAdmin() || ($lastAdmin && !$isNew) || $selfAdmin); ?>
          <label class="check" for="g-<?= $gid ?>">
            <input type="hidden" name="group_ids[<?= $gid ?>]" value="<?= $lock && in_array($gid, $checkedGroups, true) ? '1' : '0' ?>">
            <input type="checkbox" id="g-<?= $gid ?>" name="group_ids[<?= $gid ?>]" value="1"<?= in_array($gid, $checkedGroups, true) ? ' checked' : '' ?><?= $lock ? ' disabled' : '' ?>>
            <span class="check-text"><span><?= e($g['name']) ?><?php if ((int) $g['is_system']): ?> <span class="muted small">built in</span><?php endif; ?></span>
              <span class="help"><?= e($g['description']) ?><?php if ($lock): ?> <?= $lastAdmin && !$isNew ? 'This is the last active Administrator, so they must stay in this group.' : ($selfAdmin ? 'This is your own account: ask another Administrator if you should leave this group.' : 'Only an Administrator can change who is an Administrator.') ?><?php endif; ?></span></span>
          </label>
        <?php endforeach; ?>
      </div>

      <?php if ($isNew): ?>
        <h3>Temporary password</h3>
        <div @change="if ($event.target.name === 'password_mode') mode = $event.target.value">
        <?= component('form/field', ['name' => 'password_mode', 'type' => 'radio', 'label' => 'How should the first password be set?', 'value' => old('password_mode', 'generate'), 'options' => [
            ['generate', 'Make one up for me', 'Chroma picks a memorable password and shows it once after saving.'],
            ['typed', 'I will type one', 'At least 10 characters. A short sentence works well.'],
        ]]) ?>
        </div>
        <div x-show="mode === 'typed'" x-cloak>
          <div class="form-grid">
            <?= component('form/field', ['name' => 'password', 'type' => 'password', 'label' => 'Temporary password', 'attrs' => ['autocomplete' => 'new-password']]) ?>
            <?= component('form/field', ['name' => 'password_confirm', 'type' => 'password', 'label' => 'Type it again', 'attrs' => ['autocomplete' => 'new-password']]) ?>
          </div>
        </div>
        <p class="help">Either way they will be asked to choose their own password the first time they sign in.</p>
      <?php else: ?>
        <h3>Account</h3>
        <?php $lockActive = $isSelf || $lastAdmin || ($isAdmin && !$auth->isAdmin()); ?>
        <?= component('form/field', ['name' => 'active', 'type' => 'checkbox', 'label' => 'Can sign in', 'value' => old('active', $active), 'attrs' => $lockActive ? ['disabled' => true] : [],
            'help' => $isSelf ? 'You cannot deactivate your own account.' : ($lastAdmin ? 'This is the last active Administrator, so the account must stay active.' : ($isAdmin && !$auth->isAdmin() ? 'Only an Administrator can deactivate another Administrator.' : 'Untick to stop them signing in. Their name stays on everything they did.'))]) ?>
        <?php if ($lockActive): ?><input type="hidden" name="active" value="<?= $active ?>"><?php endif; ?>
      <?php endif; ?>

      <div class="form-actions">
        <button class="btn btn-primary" type="submit"><?= $isNew ? 'Create the account' : 'Save changes' ?></button>
        <a class="btn btn-ghost" href="/admin/users">Cancel</a>
      </div>
    </form>
  </div></div>

  <?php if (!$isNew): ?>
  <div class="stack">
    <div class="card"><div class="card-body">
      <h3 class="mt-0">Password</h3>
      <p class="muted small"><?= (int) $user['must_change_password'] ? 'They must choose a new password the next time they sign in.' : 'Their password is set. Reset it if they have forgotten it.' ?></p>
      <?php if ($active): ?>
        <a class="btn btn-secondary" href="/admin/users/<?= (int) $user['id'] ?>/password">Reset password</a>
      <?php else: ?>
        <p class="help">Reactivate the account before resetting the password.</p>
      <?php endif; ?>
    </div></div>
    <div class="card"><div class="card-body">
      <h3 class="mt-0"><?= $active ? 'Deactivate' : 'Reactivate' ?></h3>
      <?php if ($active): ?>
        <?php if ($isSelf): ?>
          <p class="help">You cannot deactivate your own account. Ask another administrator.</p>
        <?php elseif ($lastAdmin): ?>
          <p class="help">This is the last active Administrator. Make someone else an Administrator first.</p>
        <?php elseif ($isAdmin && !$auth->isAdmin()): ?>
          <p class="help">Only an Administrator can deactivate another Administrator.</p>
        <?php else: ?>
          <p class="muted small">They will be signed out everywhere and cannot sign in again until reactivated. Nothing they did is removed.</p>
          <?= component('confirm-form', ['action' => '/admin/users/' . (int) $user['id'] . '/deactivate', 'label' => 'Deactivate account', 'title' => 'Deactivate ' . $user['username'] . '?', 'body' => 'They will be signed out and will not be able to sign in again until you reactivate the account. Their name stays on every project and code they touched.', 'confirm_label' => 'Yes, deactivate']) ?>
        <?php endif; ?>
      <?php else: ?>
        <p class="muted small">They will be able to sign in again with their old password.</p>
        <form method="post" action="/admin/users/<?= (int) $user['id'] ?>/reactivate"><?= csrf_field() ?><button class="btn btn-secondary" type="submit">Reactivate account</button></form>
      <?php endif; ?>
    </div></div>
    <?php if (!empty($history)): ?>
    <div class="card"><div class="card-head"><h3>History</h3></div><div class="card-body">
      <ul class="feed small">
        <?php foreach ($history as $h): ?>
          <li><span class="muted nowrap"><?= e(fmt_datetime($h['created_at'], 'M j')) ?></span><span><?= e($h['note'] ?: $h['action']) ?> <span class="muted">· <?= e($h['user_name'] ?: 'system') ?></span></span></li>
        <?php endforeach; ?>
      </ul>
    </div></div>
    <?php endif; ?>
  </div>
  <?php endif; ?>
</div>
