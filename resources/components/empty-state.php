<?php /** Props: title, text, action_label, action_href, icon (svg) */ ?>
<div class="empty-state">
  <?= $icon ?? '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round"><path d="M21 8v13H3V8M1 3h22v5H1zM10 12h4"/></svg>' ?>
  <h3><?= e($title) ?></h3>
  <?php if (!empty($text)): ?><p><?= e($text) ?></p><?php endif; ?>
  <?php if (!empty($action_href)): ?><a class="btn btn-primary" href="<?= e($action_href) ?>"><?= e($action_label ?? 'Get started') ?></a><?php endif; ?>
</div>
