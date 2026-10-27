<?php
/**
 * A button that opens a confirmation dialog before posting. For destructive actions.
 * Props: action (url), label (button text), title, body (plain words about the consequence), confirm_label,
 *        variant (danger|primary), reason (bool: ask for a reason, posted as "reason"), fields (extra hidden fields array), size, class
 */
$variant = $variant ?? 'danger';
// Unique per render (not per action+label): the focus lookup in chroma.js finds THIS form's dialog among the teleported ones.
$uid = 'cf' . substr(md5($action . $label . uniqid('', true)), 0, 10);
// ghost: a quiet trigger (grey, red on hover) for row actions where a red outline on every line would shout; the dialog stays red.
$triggerClass = !empty($ghost) ? 'btn-ghost btn-danger-ghost' : 'btn-' . e($variant === 'danger' ? 'danger-outline' : $variant);
?>
<form method="post" action="<?= e($action) ?>" x-data="confirmForm({ uid: '<?= $uid ?>' })" @submit.prevent="ask()" class="<?= e($class ?? '') ?>" style="display:inline">
  <?= csrf_field() ?>
  <?php if (!empty($reason)): ?><input type="hidden" name="reason" :value="reason"><?php endif; ?>
  <?php foreach (($fields ?? []) as $fk => $fv): ?><input type="hidden" name="<?= e($fk) ?>" value="<?= e($fv) ?>"><?php endforeach; ?>
  <button type="submit" class="btn <?= $triggerClass ?><?= !empty($size) ? ' btn-' . e($size) : '' ?>"><?= e($label) ?></button>
  <template x-teleport="body">
    <div class="modal-backdrop" x-show="open" x-cloak @click.self="open=false" @keydown.escape.window="open=false" @keydown.enter="enter($event)" x-trap.noscroll="open" data-confirm-modal="<?= $uid ?>">
      <div class="modal" role="dialog" aria-modal="true" aria-labelledby="<?= $uid ?>-t">
        <div class="modal-head"><h2 id="<?= $uid ?>-t"><?= e($title ?? $label) ?></h2><button type="button" class="btn btn-ghost btn-icon" @click="open=false" aria-label="Close" tabindex="-1">&times;</button></div>
        <div class="modal-body">
          <p><?= e($body ?? 'Are you sure?') ?></p>
          <?php if (!empty($reason)): ?>
          <div class="field"><label class="label" for="<?= $uid ?>-r">Reason <span class="req">*</span></label>
            <input type="text" id="<?= $uid ?>-r" x-model="reason" data-confirm-focus autofocus required maxlength="500" placeholder="<?= e($reason_placeholder ?? 'Why? This is kept in the log.') ?>" @keydown.enter.prevent="if (reason.trim()) proceed()">
          </div>
          <?php endif; ?>
        </div>
        <div class="modal-foot">
          <button type="button" class="btn btn-secondary" @click="open=false">Cancel</button>
          <button type="button" class="btn btn-<?= e($variant) ?>" @click="proceed()" <?= !empty($reason) ? ':disabled="!reason.trim()"' : 'data-confirm-focus autofocus' ?>><?= e($confirm_label ?? $label) ?></button>
        </div>
      </div>
    </div>
  </template>
</form>
