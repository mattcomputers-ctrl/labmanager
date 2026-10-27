<?php
/**
 * A button that opens a confirmation dialog before posting. For destructive actions.
 * Props: action (url), label (button text), title, body (plain words about the consequence), confirm_label,
 *        variant (danger|primary), reason (bool: ask for a reason, posted as "reason"), fields (extra hidden fields array), size, class
 */
$variant = $variant ?? 'danger';
$uid = 'cf' . substr(md5($action . $label), 0, 8);
?>
<form method="post" action="<?= e($action) ?>" x-data="confirmForm()" @submit.prevent="ask()" class="<?= e($class ?? '') ?>" style="display:inline">
  <?= csrf_field() ?>
  <?php foreach (($fields ?? []) as $fk => $fv): ?><input type="hidden" name="<?= e($fk) ?>" value="<?= e($fv) ?>"><?php endforeach; ?>
  <button type="submit" class="btn btn-<?= e($variant === 'danger' ? 'danger-outline' : $variant) ?><?= !empty($size) ? ' btn-' . e($size) : '' ?>"><?= e($label) ?></button>
  <template x-teleport="body">
    <div class="modal-backdrop" x-show="open" x-cloak @click.self="open=false" @keydown.escape.window="open=false" x-trap.noscroll="open">
      <div class="modal" role="dialog" aria-modal="true" aria-labelledby="<?= $uid ?>-t">
        <div class="modal-head"><h2 id="<?= $uid ?>-t"><?= e($title ?? $label) ?></h2><button type="button" class="btn btn-ghost btn-icon" @click="open=false" aria-label="Close">&times;</button></div>
        <div class="modal-body">
          <p><?= e($body ?? 'Are you sure?') ?></p>
          <?php if (!empty($reason)): ?>
          <div class="field"><label class="label" for="<?= $uid ?>-r">Reason <span class="req">*</span></label>
            <input type="text" id="<?= $uid ?>-r" x-model="reason" data-confirm-focus required maxlength="500" placeholder="<?= e($reason_placeholder ?? 'Why? This is kept in the log.') ?>" @keydown.enter.prevent="if (reason.trim()) proceed()">
            <input type="hidden" name="reason" :value="reason">
          </div>
          <?php endif; ?>
        </div>
        <div class="modal-foot">
          <button type="button" class="btn btn-secondary" @click="open=false">Cancel</button>
          <button type="button" class="btn btn-<?= e($variant) ?>" @click="proceed()" <?= !empty($reason) ? ':disabled="!reason.trim()"' : 'data-confirm-focus' ?>><?= e($confirm_label ?? $label) ?></button>
        </div>
      </div>
    </div>
  </template>
</form>
