<?php /** Easy mode "Edit details" page (Expert edits inline on the project page). Posts to POST /projects/{id} → Projects::update(). */
$view->start('head'); ?><link rel="stylesheet" href="/assets/css/projects.css"><?php $view->end();
$pid = (int) $project['id'];
$errors = form_errors();
$teamIds = array_map(fn($u) => (int) $u['id'], $team);
?>
<?= component('page-header', ['title' => 'Edit project ' . $project['project_number'], 'lede' => $project['title'], 'breadcrumbs' => [['Projects', '/projects'], ['Project ' . $project['project_number'], '/projects/' . $pid], ['Edit details', null]]]) ?>
<form method="post" action="/projects/<?= $pid ?>" class="card page-narrow" novalidate>
  <div class="card-body stack">
    <?= csrf_field() ?>
    <?= component('form/field', ['name' => 'title', 'label' => 'Title', 'required' => true, 'value' => old('title', $project['title']), 'attrs' => ['maxlength' => 200], 'size' => 'lg', 'autofocus' => true]) ?>
    <?php foreach ($fields as $f): $n = $f['name']; $label = $f['long_label'] ?? $f['label']; ?>
      <?php switch ($f['type']): case 'lookup': ?>
        <div class="field"><label class="label" for="f-<?= e($n) ?>"><?= e($label) ?></label>
          <?= component('form/lookup', ['name' => $n, 'id' => 'f-' . $n, 'url' => $n === 'customer_id' ? '/api/customers?q=' : ($f['lookup'] ?? '/api/users?q='), 'value' => old($n, $f['value']), 'label_value' => $f['display'], 'allow_new' => $n === 'customer_id', 'size' => 'lg']) ?>
          <p class="help"><?= e($f['help']) ?></p><?php if (isset($errors[$n])): ?><p class="error-text" role="alert"><?= e($errors[$n]) ?></p><?php endif; ?></div>
      <?php break; case 'textarea': ?>
        <?= component('form/field', ['name' => $n, 'label' => $label, 'type' => 'textarea', 'rows' => 4, 'value' => old($n, $f['value']), 'help' => $f['help']]) ?>
      <?php break; case 'select': ?>
        <?= component('form/field', ['name' => $n, 'label' => $label, 'type' => 'select', 'placeholder' => 'Not set', 'value' => old($n, $f['value']), 'options' => $f['options'], 'help' => $f['help'], 'size' => 'lg']) ?>
      <?php break; case 'checkbox': ?>
        <?= component('form/field', ['name' => $n, 'label' => $label, 'type' => 'checkbox', 'value' => old($n, $f['value']), 'help' => $f['help']]) ?>
      <?php break; case 'date': ?>
        <?= component('form/field', ['name' => $n, 'label' => $label, 'type' => 'date', 'value' => old($n, $f['value']), 'help' => $f['help'], 'size' => 'lg']) ?>
      <?php break; default: ?>
        <?= component('form/field', ['name' => $n, 'label' => $label, 'type' => 'text', 'value' => old($n, $f['value']), 'help' => $f['help'], 'size' => 'lg', 'attrs' => !empty($f['list']) ? ['list' => 'substrate-list'] : []]) ?>
      <?php endswitch; ?>
    <?php endforeach; ?>
    <datalist id="substrate-list"><?php foreach ($substrates as $s): ?><option value="<?= e($s['name']) ?>"><?php endforeach; ?></datalist>
    <?php if ((int) $project['has_team'] === 1): ?>
      <div class="field" id="team"><label class="label" for="f-team">Team members <span class="optional">(optional)</span></label>
        <select id="f-team" name="team[]" multiple size="6">
          <?php foreach ($users as $u): ?><option value="<?= (int) $u['id'] ?>"<?= in_array((int) $u['id'], array_map('intval', (array) old('team', $teamIds)), true) ? ' selected' : '' ?>><?= e($u['display_name'] ?: $u['username']) ?></option><?php endforeach; ?>
        </select>
        <input type="hidden" name="team_present" value="1">
        <p class="help">Hold Ctrl (or Cmd) to pick several. Team members can open the project even when it is confidential.</p>
        <?php if (isset($errors['team'])): ?><p class="error-text" role="alert"><?= e($errors['team']) ?></p><?php endif; ?>
      </div>
    <?php endif; ?>
  </div>
  <div class="card-foot form-actions" style="padding:var(--s-3) var(--s-4);border-top:1px solid var(--border);display:flex;gap:var(--s-3)">
    <button type="submit" class="btn btn-primary btn-lg">Save changes</button>
    <a class="btn btn-ghost btn-lg" href="/projects/<?= $pid ?>">Cancel</a>
  </div>
</form>
