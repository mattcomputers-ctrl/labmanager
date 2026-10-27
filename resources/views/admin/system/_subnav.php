<?php /** Sub-navigation shared by System, Settings and Audit log. Prop: current */ $current = $current ?? ''; ?>
<nav class="sys-subnav" aria-label="System pages">
  <a href="/admin/system" class="<?= $current === '/admin/system' ? 'active' : '' ?>">System</a>
  <a href="/admin/settings" class="<?= $current === '/admin/settings' ? 'active' : '' ?>">Settings</a>
  <a href="/admin/audit" class="<?= $current === '/admin/audit' ? 'active' : '' ?>">Audit log</a>
</nav>
