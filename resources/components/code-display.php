<?php /** Big code result. Props: code, label, description */ ?>
<div class="code-display">
  <?php if (!empty($label)): ?><div class="code-label"><?= e($label) ?></div><?php endif; ?>
  <div class="big-code" id="assigned-code"><?= e($code) ?></div>
  <?php if (!empty($description)): ?><div class="code-desc"><?= e($description) ?></div><?php endif; ?>
  <div class="mt-4"><button type="button" class="btn btn-secondary" data-copy="<?= e($code) ?>"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect x="9" y="9" width="13" height="13" rx="2"/><path d="M5 15H4a2 2 0 0 1-2-2V4a2 2 0 0 1 2-2h9a2 2 0 0 1 2 2v1"/></svg>Copy <?= e($code) ?></button></div>
</div>
