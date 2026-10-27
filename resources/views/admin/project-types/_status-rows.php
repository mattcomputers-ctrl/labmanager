<?php
/**
 * Item-status editor rows. Props: statuses (list), colors, addAction (url), canEdit (bool: false when showing the shared list
 * on a type that has not customized it).
 */
$canEdit = $canEdit ?? true;
$editId = $editId ?? null; // status row to reopen after a refused save (its typed values are in old())
$rowErrs = $editId !== null ? form_errors() : [];
?>
<?php if ($canEdit): ?>
<form method="post" action="<?= e($addAction) ?>" class="inline-add mb-4">
  <?= csrf_field() ?>
  <?= component('form/field', ['name' => 'label', 'id' => 'st-label', 'label' => 'Add a status', 'required' => true, 'placeholder' => 'e.g. Sent to customer', 'value' => '', 'error' => null, 'attrs' => ['maxlength' => 100]]) ?>
  <?= component('form/field', ['name' => 'color', 'id' => 'st-color', 'label' => 'Colour', 'type' => 'select', 'value' => 'gray', 'error' => null, 'options' => array_map(fn($k, $l) => [$k, $l], array_keys($colors), $colors)]) ?>
  <?= component('form/field', ['name' => 'is_done', 'id' => 'st-done', 'label' => 'Means the item is finished', 'type' => 'checkbox', 'value' => 0, 'error' => null]) ?>
  <?= component('form/field', ['name' => 'is_success', 'id' => 'st-success', 'label' => 'Counts as a success', 'type' => 'checkbox', 'value' => 0, 'error' => null]) ?>
  <div class="field"><span class="label" aria-hidden="true">&nbsp;</span><button class="btn btn-secondary" type="submit">Add status</button></div>
</form>
<?php endif; ?>
<div class="table-wrap">
  <table class="table table-dense table-stack">
    <thead><tr><th>Order</th><th>Status</th><th>Finished?</th><th>Success?</th><th class="num">Items</th><th>Active</th><?php if ($canEdit): ?><th class="actions"></th><?php endif; ?></tr></thead>
    <?php foreach ($statuses as $i => $s): $sid = (int) $s['id']; $editing = $canEdit && $editId === $sid; $ov = fn(string $k, mixed $d) => $editing ? old($k, $d) : $d; ?>
    <tbody x-data="{ edit: <?= $editing ? 'true' : 'false' ?> }" id="status-<?= $sid ?>">
      <tr x-show="!edit" class="<?= (int) $s['active'] ? '' : 'void-row' ?>">
        <td class="nowrap" data-label="Order">
          <?php if ($canEdit): ?>
            <form method="post" action="/admin/item-statuses/<?= $sid ?>/move" style="display:inline"><?= csrf_field() ?><input type="hidden" name="direction" value="up"><button class="btn btn-ghost btn-sm btn-icon" type="submit" aria-label="Move up"<?= $i === 0 ? ' disabled' : '' ?>>↑</button></form>
            <form method="post" action="/admin/item-statuses/<?= $sid ?>/move" style="display:inline"><?= csrf_field() ?><input type="hidden" name="direction" value="down"><button class="btn btn-ghost btn-sm btn-icon" type="submit" aria-label="Move down"<?= $i === count($statuses) - 1 ? ' disabled' : '' ?>>↓</button></form>
          <?php else: ?><?= $i + 1 ?><?php endif; ?>
        </td>
        <td data-label="Status"><?= component('badge', ['label' => $s['label'], 'color' => $s['color']]) ?> <span class="muted xs code"><?= e($s['code']) ?></span></td>
        <td data-label="Finished?"><?= (int) $s['is_done'] ? 'Yes' : '<span class="muted">No</span>' ?></td>
        <td data-label="Success?"><?= (int) $s['is_success'] ? 'Yes' : '<span class="muted">No</span>' ?></td>
        <td class="num" data-label="Items"><?= (int) $s['usage_count'] ?></td>
        <td data-label="Active"><?= (int) $s['active'] ? 'Yes' : '<span class="muted">No</span>' ?></td>
        <?php if ($canEdit): ?>
        <td class="actions">
          <button type="button" class="btn btn-ghost btn-sm" @click="edit = true">Edit</button>
          <?php if ((int) $s['usage_count'] === 0): ?>
            <?= component('confirm-form', ['action' => '/admin/item-statuses/' . $sid . '/delete', 'label' => 'Remove', 'size' => 'sm', 'title' => 'Remove "' . $s['label'] . '"?', 'body' => 'No item uses it, so it can be removed. To keep it for old work but stop offering it, edit it and untick Active instead.', 'confirm_label' => 'Yes, remove it']) ?>
          <?php endif; ?>
        </td>
        <?php endif; ?>
      </tr>
      <?php if ($canEdit): ?>
      <tr x-show="edit" x-cloak>
        <td colspan="7">
          <form method="post" action="/admin/item-statuses/<?= $sid ?>" class="row-edit">
            <?= csrf_field() ?>
            <label class="sr-only" for="sl-<?= $sid ?>">Name</label>
            <input class="input" id="sl-<?= $sid ?>" type="text" name="label" value="<?= e((string) $ov('label', $s['label'])) ?>" required maxlength="100"<?= $editing ? ' autofocus' : '' ?><?= $editing && isset($rowErrs['label']) ? ' aria-invalid="true"' : '' ?>>
            <label class="sr-only" for="sc-<?= $sid ?>">Colour</label>
            <select id="sc-<?= $sid ?>" name="color"><?php foreach ($colors as $k => $l): ?><option value="<?= e($k) ?>"<?= (string) $ov('color', $s['color']) === $k ? ' selected' : '' ?>><?= e($l) ?></option><?php endforeach; ?></select>
            <label class="check small"><input type="hidden" name="is_done" value="0"><input type="checkbox" name="is_done" value="1"<?= (string) $ov('is_done', $s['is_done']) === '1' ? ' checked' : '' ?>> <span>Finished</span></label>
            <label class="check small"><input type="hidden" name="is_success" value="0"><input type="checkbox" name="is_success" value="1"<?= (string) $ov('is_success', $s['is_success']) === '1' ? ' checked' : '' ?>> <span>Success</span></label>
            <label class="check small"><input type="hidden" name="active" value="0"><input type="checkbox" name="active" value="1"<?= (string) $ov('active', $s['active']) === '1' ? ' checked' : '' ?>> <span>Active</span></label>
            <button class="btn btn-primary btn-sm" type="submit">Save</button>
            <button class="btn btn-ghost btn-sm" type="button" @click="edit = false">Cancel</button>
            <?php if ($editing && $rowErrs): ?><p class="error-text row-edit-error" role="alert"><?= e(implode(' ', array_unique(array_values($rowErrs)))) ?></p><?php endif; ?>
          </form>
        </td>
      </tr>
      <?php endif; ?>
    </tbody>
    <?php endforeach; ?>
  </table>
</div>
<p class="help mt-2">"Finished" statuses count towards the project progress bar. A project completes itself when every item is finished or marked not needed. A status an item already uses can be deactivated but not removed.</p>
