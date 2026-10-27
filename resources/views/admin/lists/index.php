<?php $view->start('head'); ?><link rel="stylesheet" href="/assets/css/admin-users.css"><?php $view->end(); ?>
<?= $view->partial('admin/_nav', ['current' => '/admin/lists']) ?>
<?php
$base = '/admin/lists?tab=' . $tab;
$errs = form_errors();
$cols = is_expert() ? 6 : 4;
// When a row edit was rejected we come back with ?edit={id}: the typed values and the message belong to that row, not to the Add box.
$rowErrs = $editId !== null ? $errs : [];
$addErrs = $editId === null ? $errs : [];
// '' (not null) so the field component does not fall back to old()/form_errors() itself while a row is being re-edited.
$addOld = fn(string $k, mixed $d = '') => $editId === null ? old($k, $d) : $d;
?>
<?= component('page-header', [
    'title' => 'Lists',
    'lede' => 'The pick-lists used on projects and colors. Deactivate an entry to hide it from pickers without touching old work.',
    'actions' => '<a class="btn btn-ghost btn-sm" href="' . e($base . '&export=csv') . '">CSV</a><a class="btn btn-ghost btn-sm" href="' . e($base . '&export=xlsx') . '">Excel</a>',
]) ?>

<div class="grid grid-2 mb-6 list-cards">
  <a class="quick-action" href="/admin/project-types"><span class="qa-title">Project types</span><span class="qa-desc"><?= plural($typeCount, 'active type') ?> · stages, item statuses and which fields each type uses.</span></a>
  <a class="quick-action" href="/admin/customers"><span class="qa-title">Customers</span><span class="qa-desc"><?= plural($customerCount, 'active customer') ?> · add, rename, merge duplicates.</span></a>
</div>

<div class="tabs" role="tablist" aria-label="Lists">
  <?php foreach (\Chroma\Domain\Lists\ListAdmin::KINDS as $k => $def): ?>
    <a href="/admin/lists?tab=<?= e($k) ?>" class="<?= $tab === $k ? 'active' : '' ?>" role="tab" aria-selected="<?= $tab === $k ? 'true' : 'false' ?>"><?= e($def['plural']) ?></a>
  <?php endforeach; ?>
</div>

<div class="card mb-4"><div class="card-body">
  <form method="post" action="/admin/lists/<?= e($tab) ?>" class="inline-add">
    <?= csrf_field() ?>
    <?= component('form/field', ['name' => 'name', 'label' => 'Add ' . ($tab === 'ink-series' ? 'an ink series' : 'a ' . $kind['singular']), 'required' => true, 'placeholder' => 'Type a name', 'value' => $addOld('name'), 'error' => $addErrs['name'] ?? '', 'attrs' => ['maxlength' => 100]]) ?>
    <?php if (is_expert()): ?>
      <?= component('form/field', ['name' => 'notes', 'label' => 'Notes', 'optional_text' => true, 'value' => $addOld('notes'), 'error' => $addErrs['notes'] ?? '', 'attrs' => ['maxlength' => 255]]) ?>
      <?= component('form/field', ['name' => 'sort_order', 'label' => 'Sort', 'type' => 'number', 'value' => $addOld('sort_order', 100), 'error' => $addErrs['sort_order'] ?? '', 'help' => 'Lower comes first.', 'class' => 'narrow']) ?>
    <?php endif; ?>
    <div class="field"><span class="label" aria-hidden="true">&nbsp;</span><button class="btn btn-primary" type="submit">Add to list</button></div>
  </form>
</div></div>

<?php if (!$entries): ?>
  <div class="card"><?= component('empty-state', ['title' => 'No ' . strtolower($kind['plural']) . ' yet', 'text' => 'Add the ones the lab uses with the box above. They appear in the pickers on projects and colors straight away.']) ?></div>
<?php else: ?>
<div class="table-wrap">
  <table class="table table-stack">
    <thead><tr><th>Name</th><?php if (is_expert()): ?><th>Notes</th><th class="num">Sort</th><?php endif; ?><th class="num">Used by</th><th>Status</th><th class="actions"></th></tr></thead>
    <?php foreach ($entries as $r): $id = (int) $r['id']; $isActive = (int) $r['active'] === 1; $editing = $editId === $id;
      // The row being re-edited after a refused save shows what was typed; every other row shows what is saved.
      $editName = $editing ? (string) old('name', $r['name']) : $r['name'];
      $editNotes = $editing ? (string) old('notes', $r['notes']) : (string) $r['notes'];
      $editSort = $editing ? (string) old('sort_order', $r['sort_order']) : (string) (int) $r['sort_order'];
    ?>
    <tbody x-data="{ edit: <?= $editing ? 'true' : 'false' ?> }" id="entry-<?= $id ?>">
      <tr x-show="!edit" class="<?= $isActive ? '' : 'void-row' ?>">
        <td data-label="Name"><strong><?= e($r['name']) ?></strong><?php if (is_easy() && $r['notes']): ?><br><span class="muted small"><?= e($r['notes']) ?></span><?php endif; ?></td>
        <?php if (is_expert()): ?><td class="muted" data-label="Notes"><?= e($r['notes']) ?></td><td class="num" data-label="Sort"><?= (int) $r['sort_order'] ?></td><?php endif; ?>
        <td class="num" data-label="Used by" title="Projects and colors using this"><?= (int) $r['usage_count'] ?></td>
        <td data-label="Status"><?= $isActive ? component('badge', ['label' => 'Active', 'color' => 'green']) : component('badge', ['label' => 'Inactive', 'color' => 'gray']) ?></td>
        <td class="actions">
          <button type="button" class="btn btn-ghost btn-sm" @click="edit = true">Edit</button>
          <?php if ($isActive): ?>
            <form method="post" action="/admin/lists/<?= e($tab) ?>/<?= $id ?>/deactivate" style="display:inline"><?= csrf_field() ?><button class="btn btn-ghost btn-sm" type="submit">Deactivate</button></form>
          <?php else: ?>
            <form method="post" action="/admin/lists/<?= e($tab) ?>/<?= $id ?>/reactivate" style="display:inline"><?= csrf_field() ?><button class="btn btn-ghost btn-sm" type="submit">Reactivate</button></form>
          <?php endif; ?>
          <?php if ((int) $r['usage_count'] === 0): ?>
            <?= component('confirm-form', ['action' => '/admin/lists/' . $tab . '/' . $id . '/delete', 'label' => 'Delete', 'size' => 'sm', 'title' => 'Delete "' . $r['name'] . '"?', 'body' => 'Nothing uses it yet, so it can be removed completely. If you only want to hide it, choose Deactivate instead.', 'confirm_label' => 'Yes, delete it']) ?>
          <?php endif; ?>
        </td>
      </tr>
      <tr x-show="edit" x-cloak class="row-edit-row">
        <td colspan="<?= $cols ?>">
          <form method="post" action="/admin/lists/<?= e($tab) ?>/<?= $id ?>" class="row-edit">
            <?= csrf_field() ?>
            <input type="hidden" name="active" value="<?= $isActive ? 1 : 0 ?>">
            <label class="sr-only" for="name-<?= $id ?>">Name</label>
            <input class="input" id="name-<?= $id ?>" type="text" name="name" value="<?= e($editName) ?>" required maxlength="100"<?= $editing ? ' autofocus' : '' ?><?= isset($rowErrs['name']) && $editing ? ' aria-invalid="true"' : '' ?>>
            <label class="sr-only" for="notes-<?= $id ?>">Notes</label>
            <input class="input" type="text" id="notes-<?= $id ?>" name="notes" value="<?= e($editNotes) ?>" placeholder="Notes" maxlength="255">
            <label class="sr-only" for="sort-<?= $id ?>">Sort order</label>
            <input class="input narrow" type="number" id="sort-<?= $id ?>" name="sort_order" value="<?= e($editSort) ?>" title="Sort order; lower comes first"<?= isset($rowErrs['sort_order']) && $editing ? ' aria-invalid="true"' : '' ?>>
            <button class="btn btn-primary btn-sm" type="submit">Save</button>
            <button class="btn btn-ghost btn-sm" type="button" @click="edit = false">Cancel</button>
            <?php if ($editing && $rowErrs): ?>
              <p class="error-text row-edit-error" role="alert"><?= e(implode(' ', array_unique(array_values($rowErrs)))) ?></p>
            <?php endif; ?>
          </form>
        </td>
      </tr>
    </tbody>
    <?php endforeach; ?>
  </table>
</div>
<?php endif; ?>
<p class="help mt-4">
  <?php if ($inactiveCount > 0 && !$showInactive): ?><a href="<?= e($base . '&inactive=1') ?>">Show <?= plural($inactiveCount, 'inactive entry', 'inactive entries') ?></a> ·<?php elseif ($showInactive): ?><a href="<?= e($base) ?>">Hide inactive entries</a> ·<?php endif; ?>
  An entry that is used by a project or color can be deactivated but not deleted, so old work keeps reading correctly.
</p>
