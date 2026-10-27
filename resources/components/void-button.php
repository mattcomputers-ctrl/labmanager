<?php
/**
 * "Void" button for a code page: opens a dialog that explains the consequence and asks for the reason, then posts.
 * The whole <form> lives inside the teleported dialog, so the reason is always part of the POST (confirm-form's
 * hidden reason input is teleported out of its form and never posted — see docs/requests/D.md).
 * Props: action (url), label (button text), title, body (plain words about the consequence), confirm_label,
 *        variant (danger|primary), fields (extra hidden fields array), reason_placeholder, size, class
 */
$variant = $variant ?? 'danger';
$uid = 'vb' . substr(md5($action . $label), 0, 8);
?>
<div x-data="{ open: false, reason: '' }" class="<?= e($class ?? '') ?>" style="display:inline">
  <button type="button" class="btn btn-<?= e($variant === 'danger' ? 'danger-outline' : $variant) ?><?= !empty($size) ? ' btn-' . e($size) : '' ?>" @click="open = true; $nextTick(() => { const el = document.getElementById('<?= $uid ?>-r'); if (el) el.focus(); })"><?= e($label) ?></button>
  <template x-teleport="body">
    <div class="modal-backdrop" x-show="open" x-cloak @click.self="open = false" @keydown.escape.window="open = false" x-trap.noscroll="open">
      <form method="post" action="<?= e($action) ?>" class="modal" role="dialog" aria-modal="true" aria-labelledby="<?= $uid ?>-t" @submit="if (!reason.trim()) $event.preventDefault()">
        <?= csrf_field() ?>
        <?php foreach (($fields ?? []) as $fk => $fv): ?><input type="hidden" name="<?= e($fk) ?>" value="<?= e($fv) ?>"><?php endforeach; ?>
        <div class="modal-head"><h2 id="<?= $uid ?>-t"><?= e($title ?? $label) ?></h2><button type="button" class="btn btn-ghost btn-icon" @click="open = false" aria-label="Close">&times;</button></div>
        <div class="modal-body">
          <p><?= e($body ?? 'Are you sure?') ?></p>
          <div class="field">
            <label class="label" for="<?= $uid ?>-r">Reason <span class="req" aria-hidden="true">*</span></label>
            <input type="text" id="<?= $uid ?>-r" name="reason" x-model="reason" required maxlength="500" class="input" placeholder="<?= e($reason_placeholder ?? 'Why? This is kept in the log.') ?>">
          </div>
        </div>
        <div class="modal-foot">
          <button type="button" class="btn btn-secondary" @click="open = false">Cancel</button>
          <button type="submit" class="btn btn-<?= e($variant) ?>" :disabled="!reason.trim()"><?= e($confirm_label ?? $label) ?></button>
        </div>
      </form>
    </div>
  </template>
</div>
