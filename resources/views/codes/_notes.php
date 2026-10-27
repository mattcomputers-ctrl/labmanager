<?php /** Notes card with an editor for people who can assign codes. @var array $code @var string $action @var bool $canEdit */ ?>
<div class="card" x-data="inlineEdit({ open: <?= form_errors() && isset(form_errors()['notes']) ? 'true' : 'false' ?> })">
  <div class="card-head"><h2>Notes</h2><?php if ($canEdit): ?><button type="button" class="btn btn-ghost btn-sm" @click="toggle()" x-text="open ? 'Cancel' : '<?= $code['notes'] ? 'Edit notes' : 'Add notes' ?>'"></button><?php endif; ?></div>
  <div class="card-body">
    <div x-show="!open">
      <?php if ($code['notes']): ?><div class="notes-view"><?= markdown($code['notes']) ?></div><?php else: ?><p class="muted mb-0">No notes. <?= $canEdit ? 'Anything worth knowing about this code — substrate quirks, who asked for it, where the drawdown is.' : '' ?></p><?php endif; ?>
    </div>
    <?php if ($canEdit): ?>
    <form method="post" action="<?= e($action) ?>" x-show="open" x-cloak>
      <?= csrf_field() ?>
      <?= component('form/field', ['name' => 'notes', 'type' => 'textarea', 'label' => 'Notes', 'value' => old('notes', $code['notes']), 'rows' => 5, 'help' => 'Simple formatting works: *italic*, **bold**, lists with -']) ?>
      <div class="form-actions" style="margin-top:var(--s-3);padding-top:var(--s-3)"><button type="submit" class="btn btn-primary">Save notes</button></div>
    </form>
    <?php endif; ?>
  </div>
</div>
