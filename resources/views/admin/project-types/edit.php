<?php $view->start('head'); ?><link rel="stylesheet" href="/assets/css/admin-users.css"><?php $view->end(); ?>
<?= $view->partial('admin/_nav', ['current' => '/admin/lists']) ?>
<?php $tid = (int) $type['id']; $editStage = $editStage ?? null; $editStatus = $editStatus ?? null; $stageErrs = $editStage !== null ? form_errors() : []; ?>
<?= component('page-header', [
    'title' => $type['label'],
    'lede' => $type['description'] ?: 'No description yet.',
    'breadcrumbs' => [['Admin', '/admin'], ['Lists', '/admin/lists'], ['Project types', '/admin/project-types'], [$type['label'], null]],
    'badge' => component('badge', ['label' => (int) $type['active'] ? plural((int) $type['project_count'], 'project') : 'Inactive', 'color' => (int) $type['active'] ? $type['color'] : 'gray']),
]) ?>

<div class="tabs" role="navigation" aria-label="Sections">
  <a href="#details">Details</a><?php if ((int) $type['has_stages']): ?><a href="#stages">Stages (<?= count(array_filter($type['stages'], fn($s) => (int) $s['active'])) ?>)</a><?php endif; ?><a href="#statuses">Item statuses</a>
</div>

<form method="post" action="/admin/project-types/<?= $tid ?>" id="details">
  <?= csrf_field() ?>
  <div class="card mb-4"><div class="card-body">
    <?= $view->partial('admin/project-types/_type-fields', ['type' => $type, 'catalogue' => $catalogue, 'colors' => $colors]) ?>
    <div class="form-actions"><button class="btn btn-primary" type="submit">Save project type</button><a class="btn btn-ghost" href="/admin/project-types">Back to types</a></div>
  </div></div>
</form>

<?php if ((int) $type['has_stages']): ?>
<div class="card mb-4" id="stages">
  <div class="card-head"><h2>Stages</h2><span class="muted small">Where the work is, in order. A "final" stage means the project is done, for example Released or Shelved.</span></div>
  <div class="card-body">
    <form method="post" action="/admin/project-types/<?= $tid ?>/stages" class="inline-add mb-4">
      <?= csrf_field() ?>
      <?= component('form/field', ['name' => 'label', 'id' => 'stage-label', 'label' => 'Add a stage', 'required' => true, 'placeholder' => 'e.g. Press trial', 'value' => '', 'error' => null, 'attrs' => ['maxlength' => 100]]) ?>
      <?= component('form/field', ['name' => 'is_terminal', 'id' => 'stage-terminal', 'label' => 'Final stage', 'type' => 'checkbox', 'value' => 0, 'error' => null]) ?>
      <div class="field"><span class="label" aria-hidden="true">&nbsp;</span><button class="btn btn-secondary" type="submit">Add stage</button></div>
    </form>
    <?php if (!$type['stages']): ?>
      <?= component('empty-state', ['title' => 'No stages yet', 'text' => 'Add them in order, for example Idea, Bench, Scale-up, Press trial, Released, Shelved.']) ?>
    <?php else: ?>
    <div class="table-wrap">
      <table class="table table-dense table-stack">
        <thead><tr><th>Order</th><th>Stage</th><th>Final?</th><th class="num">Projects</th><th>Active</th><th class="actions"></th></tr></thead>
        <?php foreach ($type['stages'] as $i => $s): $sid = (int) $s['id']; $editing = $editStage === $sid; ?>
        <tbody x-data="{ edit: <?= $editing ? 'true' : 'false' ?> }" id="stage-<?= $sid ?>">
          <tr x-show="!edit" class="<?= (int) $s['active'] ? '' : 'void-row' ?>">
            <td class="nowrap" data-label="Order">
              <form method="post" action="/admin/project-types/<?= $tid ?>/stages/<?= $sid ?>/move" style="display:inline"><?= csrf_field() ?><input type="hidden" name="direction" value="up"><button class="btn btn-ghost btn-sm btn-icon" type="submit" aria-label="Move up"<?= $i === 0 ? ' disabled' : '' ?>>↑</button></form>
              <form method="post" action="/admin/project-types/<?= $tid ?>/stages/<?= $sid ?>/move" style="display:inline"><?= csrf_field() ?><input type="hidden" name="direction" value="down"><button class="btn btn-ghost btn-sm btn-icon" type="submit" aria-label="Move down"<?= $i === count($type['stages']) - 1 ? ' disabled' : '' ?>>↓</button></form>
            </td>
            <td data-label="Stage"><strong><?= e($s['label']) ?></strong> <span class="muted xs code"><?= e($s['code']) ?></span></td>
            <td data-label="Final?"><?= (int) $s['is_terminal'] ? 'Yes' : '<span class="muted">No</span>' ?></td>
            <td class="num" data-label="Projects"><?= (int) $s['usage_count'] ?></td>
            <td data-label="Active"><?= (int) $s['active'] ? 'Yes' : '<span class="muted">No</span>' ?></td>
            <td class="actions">
              <button type="button" class="btn btn-ghost btn-sm" @click="edit = true">Edit</button>
              <?php if ((int) $s['usage_count'] === 0): ?>
                <?= component('confirm-form', ['action' => '/admin/project-types/' . $tid . '/stages/' . $sid . '/delete', 'label' => 'Remove', 'size' => 'sm', 'title' => 'Remove the "' . $s['label'] . '" stage?', 'body' => 'No project is at this stage, so it can be removed. To keep it for old work but stop offering it, edit it and untick Active instead.', 'confirm_label' => 'Yes, remove it']) ?>
              <?php endif; ?>
            </td>
          </tr>
          <tr x-show="edit" x-cloak>
            <td colspan="6">
              <form method="post" action="/admin/project-types/<?= $tid ?>/stages/<?= $sid ?>" class="row-edit">
                <?= csrf_field() ?>
                <label class="sr-only" for="stl-<?= $sid ?>">Name</label>
                <input class="input" id="stl-<?= $sid ?>" type="text" name="label" value="<?= e($editing ? (string) old('label', $s['label']) : $s['label']) ?>" required maxlength="100"<?= $editing ? ' autofocus' : '' ?><?= $editing && isset($stageErrs['label']) ? ' aria-invalid="true"' : '' ?>>
                <label class="check small"><input type="hidden" name="is_terminal" value="0"><input type="checkbox" name="is_terminal" value="1"<?= ($editing ? (string) old('is_terminal', $s['is_terminal']) === '1' : (int) $s['is_terminal'] === 1) ? ' checked' : '' ?>> <span>Final stage</span></label>
                <label class="check small"><input type="hidden" name="active" value="0"><input type="checkbox" name="active" value="1"<?= ($editing ? (string) old('active', $s['active']) === '1' : (int) $s['active'] === 1) ? ' checked' : '' ?>> <span>Active</span></label>
                <button class="btn btn-primary btn-sm" type="submit">Save</button>
                <button class="btn btn-ghost btn-sm" type="button" @click="edit = false">Cancel</button>
                <?php if ($editing && $stageErrs): ?><p class="error-text row-edit-error" role="alert"><?= e(implode(' ', array_unique(array_values($stageErrs)))) ?></p><?php endif; ?>
              </form>
            </td>
          </tr>
        </tbody>
        <?php endforeach; ?>
      </table>
    </div>
    <?php endif; ?>
  </div>
</div>
<?php endif; ?>

<div class="card mb-4" id="statuses">
  <div class="card-head"><h2>Item statuses</h2>
    <?php if ($type['uses_global_statuses']): ?>
      <form method="post" action="/admin/project-types/<?= $tid ?>/statuses/customize"><?= csrf_field() ?><button class="btn btn-secondary" type="submit">Customize for this type</button></form>
    <?php else: ?>
      <?= component('confirm-form', ['action' => '/admin/project-types/' . $tid . '/statuses/revert', 'label' => 'Use the shared list again', 'variant' => 'danger', 'title' => 'Go back to the shared statuses?', 'body' => 'This type\'s own statuses are removed and it uses the shared list again. Only possible while no item carries one of its own statuses.', 'confirm_label' => 'Yes, use the shared list']) ?>
    <?php endif; ?>
  </div>
  <div class="card-body">
    <?php if ($type['uses_global_statuses']): ?>
      <?= component('callout', ['type' => 'info', 'title' => 'Using the shared list', 'body' => 'These statuses are shared with every other type that has not been customized. Press "Customize for this type" to give ' . $type['label'] . ' its own copy to edit, or edit the shared list under Project types → Shared item statuses.']) ?>
      <div class="mb-4"></div>
    <?php endif; ?>
    <?= $view->partial('admin/project-types/_status-rows', ['statuses' => $type['statuses'], 'colors' => $colors, 'addAction' => '/admin/project-types/' . $tid . '/statuses', 'canEdit' => !$type['uses_global_statuses'], 'editId' => $editStatus]) ?>
  </div>
</div>

<?php if (!empty($history)): ?>
<div class="card"><div class="card-head"><h3>History</h3></div><div class="card-body">
  <ul class="feed small">
    <?php foreach ($history as $h): ?>
      <li><span class="muted nowrap"><?= e(fmt_datetime($h['created_at'], 'M j')) ?></span><span><?= e($h['note'] ?: $h['action']) ?> <span class="muted">· <?= e($h['user_name'] ?: 'system') ?></span></span></li>
    <?php endforeach; ?>
  </ul>
</div></div>
<?php endif; ?>
