<?php
/**
 * A destructive action that needs a reason: the reason field sits inside the
 * form on the page (so it is always posted), and pressing the button opens a
 * confirmation dialog that explains the consequence before submitting.
 * (confirm-form's reason input lives inside the teleported modal, outside the
 * form, so browsers do not post it — see docs/requests/import.md.)
 *
 * Props: action (url), label (button text), title, body (plain words about the consequence),
 *        confirm_label, placeholder, fields (extra hidden fields), variant (danger|primary), size, class, help
 */
$variant = $variant ?? 'danger';
$uid = 'cr' . substr(md5($action . $label), 0, 8);
$err = form_errors()['reason'] ?? null;
?>
<form method="post" action="<?= e($action) ?>" x-data="{ open: false, reason: <?= json_attr(old('reason', '')) ?> }" @submit.prevent="if (reason.trim()) open = true" class="<?= e($class ?? '') ?>">
  <?= csrf_field() ?>
  <?php foreach (($fields ?? []) as $fk => $fv): ?><input type="hidden" name="<?= e($fk) ?>" value="<?= e($fv) ?>"><?php endforeach; ?>
  <div class="field">
    <label class="label" for="<?= $uid ?>-r">Reason <span class="req" aria-hidden="true">*</span></label>
    <input type="text" id="<?= $uid ?>-r" name="reason" x-model="reason" required maxlength="500" placeholder="<?= e($placeholder ?? 'Why? This is kept in the log.') ?>"<?= $err ? ' aria-invalid="true"' : '' ?>>
    <?php if (!empty($help)): ?><p class="help"><?= e($help) ?></p><?php endif; ?>
    <?php if ($err): ?><p class="error-text" role="alert"><?= e($err) ?></p><?php endif; ?>
  </div>
  <button type="submit" class="btn btn-<?= e($variant === 'danger' ? 'danger-outline' : $variant) ?><?= !empty($size) ? ' btn-' . e($size) : '' ?>" :disabled="!reason.trim()"><?= e($label) ?></button>
  <template x-teleport="body">
    <div class="modal-backdrop" x-show="open" x-cloak @click.self="open=false" @keydown.escape.window="open=false" x-trap.noscroll="open">
      <div class="modal" role="dialog" aria-modal="true" aria-labelledby="<?= $uid ?>-t">
        <div class="modal-head"><h2 id="<?= $uid ?>-t"><?= e($title ?? $label) ?></h2><button type="button" class="btn btn-ghost btn-icon" @click="open=false" aria-label="Close">&times;</button></div>
        <div class="modal-body"><p><?= e($body ?? 'Are you sure?') ?></p><p class="small muted mb-0">Reason: <span x-text="reason"></span></p></div>
        <div class="modal-foot">
          <button type="button" class="btn btn-secondary" @click="open=false">Cancel</button>
          <button type="button" class="btn btn-<?= e($variant) ?>" data-confirm-focus @click="open=false; $nextTick(() => $root.submit())"><?= e($confirm_label ?? $label) ?></button>
        </div>
      </div>
    </div>
  </template>
</form>
