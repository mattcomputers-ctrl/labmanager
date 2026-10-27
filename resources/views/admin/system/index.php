<?php
/** @var array<string,string> $info */
use Chroma\Domain\System\SystemInfo;
$view->start('head'); ?><link rel="stylesheet" href="/assets/css/system-admin.css"><script src="/assets/js/system-admin.js"></script><?php $view->end();
echo $view->partial('admin/_nav', ['current' => '/admin/system']);
echo component('page-header', ['title' => 'System', 'lede' => 'What version is running, updates, backups, the app log and API tokens. Every action here is written to the audit log.']);
echo $view->partial('admin/system/_subnav', ['current' => '/admin/system']);
$diskClass = $disk['percent_used'] >= 90 ? 'danger' : ($disk['percent_used'] >= 75 ? 'warn' : '');
?>

<?php if (!$helperAvailable): ?>
  <?= component('callout', ['type' => 'info', 'title' => 'Updates and backups are not available on this machine', 'body' => 'The system helper that runs them is installed by install.sh on the server. On a development copy you can still see the version, the log and manage API tokens.']) ?>
  <div class="mb-4"></div>
<?php endif; ?>

<div class="sys-grid">
  <section class="card" id="version">
    <div class="card-head"><h2>This installation</h2></div>
    <div class="card-body">
      <div class="sys-stats">
        <div class="stat"><div class="stat-value"><?= e($info['version_tag']) ?></div><div class="stat-label">Version</div></div>
        <div class="stat"><div class="stat-value"><code><?= e($info['version_commit']) ?></code></div><div class="stat-label">Commit, <?= e($info['version_date']) ?></div></div>
        <div class="stat"><div class="stat-value"><?= e(SystemInfo::humanBytes($disk['free'])) ?></div><div class="stat-label">Disk free of <?= e(SystemInfo::humanBytes($disk['total'])) ?></div>
          <div class="disk-bar <?= $diskClass ?>" role="progressbar" aria-valuenow="<?= (int) $disk['percent_used'] ?>" aria-valuemin="0" aria-valuemax="100" aria-label="Disk used"><span style="width:<?= (int) $disk['percent_used'] ?>%"></span></div></div>
      </div>
      <?= component('kv', ['rows' => [
        ['PHP', $info['php']],
        ['MariaDB', $info['mariadb']],
        ['Operating system', $info['os']],
        ['Server time', $info['server_time']],
        ['Config file', $info['config_path']],
        ['Log folder', $info['log_dir']],
        ['Attachments folder', $info['files_path']],
        ['Backup folder', $info['backup_dir']],
      ]]) ?>
      <p class="mt-4"><a class="btn btn-secondary" href="/admin/audit">Open the audit log</a> <a class="btn btn-secondary" href="/admin/settings">Settings</a></p>
    </div>
  </section>

  <section class="card" id="updates" x-data="updateJob({ job: <?= json_attr($job) ?> })">
    <div class="card-head"><h2>Updates</h2></div>
    <div class="card-body">
      <?php if ($helperAvailable): ?>
      <div x-data="updateCheck()">
        <p>Chroma updates itself from its Git repository. Checking is harmless; updating takes a backup first, runs the tests and rolls back by itself if they fail.</p>
        <div class="flex flex-wrap" style="gap:var(--s-2)">
          <button type="button" class="btn btn-secondary" @click="check()" :disabled="busy || running"><span x-show="!busy">Check for updates</span><span x-show="busy" x-cloak>Checking…</span></button>
          <button type="button" class="btn btn-primary" @click="confirm = true" :disabled="running">Update now</button>
        </div>
        <template x-if="result"><div class="mt-4"><div class="callout" :class="result.ok ? (result.state === 'update-available' ? 'callout-warning' : 'callout-success') : 'callout-danger'" role="status"><div x-text="result.message"></div></div></div></template>
      </div>
      <template x-teleport="body">
        <div class="modal-backdrop" x-show="confirm" x-cloak @click.self="confirm=false" @keydown.escape.window="confirm=false" x-trap.noscroll="confirm">
          <div class="modal" role="dialog" aria-modal="true" aria-labelledby="upd-title">
            <div class="modal-head"><h2 id="upd-title">Update Chroma now?</h2><button type="button" class="btn btn-ghost btn-icon" @click="confirm=false" aria-label="Close">&times;</button></div>
            <div class="modal-body">
              <p>Chroma will take a backup, fetch the newest version, run its tests and restart. Anyone using Chroma may see an error for a few seconds. If the tests fail, the old version is put back automatically.</p>
              <div class="field"><label class="label" for="upd-branch">Branch <span class="optional">(optional)</span></label><input type="text" id="upd-branch" x-model="branch" placeholder="main" autocomplete="off"><p class="help">Leave blank unless a developer told you to update from a different branch.</p></div>
            </div>
            <div class="modal-foot"><button type="button" class="btn btn-secondary" @click="confirm=false">Cancel</button><button type="button" class="btn btn-primary" @click="start()">Update now</button></div>
          </div>
        </div>
      </template>
      <?php else: ?>
        <p class="muted">Not available on this machine. On the server, Chroma updates from this screen or with <code>sudo chroma-update</code>.</p>
      <?php endif; ?>
      <div x-show="job" x-cloak class="mt-4">
        <div class="job-status"><span class="spinner" x-show="running"></span><span x-show="running">Update running… this page keeps showing the log.</span><span x-show="done && exit === 0" style="color:var(--c-green-700, #15803d)">Update finished. <a href="/admin/system">Reload</a> to see the new version.</span><span x-show="done && exit !== 0" style="color:#b91c1c">The update stopped with an error and the previous version was put back where possible. Read the log.</span><span x-show="error" style="color:#b91c1c" x-text="error"></span></div>
        <pre class="log-panel live" x-ref="panel" aria-live="polite"><template x-for="(l, i) in lines" :key="i"><span :class="lineClass(l)" x-text="l + '\n'"></span></template></pre>
      </div>
    </div>
  </section>

  <section class="card" id="backups">
    <div class="card-head"><h2>Backups</h2><span class="muted small">Kept in <?= e($backupDir) ?></span></div>
    <div class="card-body">
      <p>A backup is taken every night and before every update. You can take one at any time, for example before a big import.</p>
      <?php if ($helperAvailable): ?>
      <form method="post" action="/admin/system/backup" x-data="{ busy: false }" @submit="busy = true"><?= csrf_field() ?><button type="submit" class="btn btn-primary" :disabled="busy"><span x-show="!busy">Back up now</span><span x-show="busy" x-cloak>Backing up…</span></button></form>
      <?php else: ?>
      <p class="muted">Taking a backup from here is not available on this machine.</p>
      <?php endif; ?>
      <?php if ($backups): ?>
      <div class="table-wrap mt-4"><table class="table">
        <thead><tr><th>File</th><th class="num">Size</th><th>Taken</th><th class="actions"></th></tr></thead>
        <tbody>
        <?php foreach ($backups as $b): ?>
          <tr><td class="code"><?= e($b['name']) ?></td><td class="num"><?= e(SystemInfo::humanBytes((int) $b['bytes'])) ?></td><td class="nowrap"><?= e($b['created']) ?></td><td class="actions"><a class="btn btn-secondary btn-sm" href="/admin/system/backups/<?= rawurlencode($b['name']) ?>">Download</a></td></tr>
        <?php endforeach; ?>
        </tbody></table></div>
      <?php else: ?>
      <div class="mt-4"><?= component('empty-state', ['title' => 'No backups to show', 'text' => $helperAvailable ? 'The first nightly backup will appear here, or press Back up now.' : 'On the server, backups taken nightly and from this screen are listed here with a Download button.']) ?></div>
      <?php endif; ?>
    </div>
  </section>

  <section class="card" id="tokens">
    <div class="card-head"><h2>API tokens</h2></div>
    <div class="card-body">
      <p>Other Precision Ink systems (the ERP, Insights) can read codes and project status through Chroma's read-only JSON API with one of these tokens. A token is shown once, when it is made.</p>
      <?php if ($newToken): ?>
        <div class="callout callout-success" role="alert"><div><span class="callout-title">New token "<?= e($newToken['name']) ?>" — copy it now</span>This is the only time it is shown. Paste it into the other system's settings.
          <div class="token-reveal"><code id="new-token"><?= e($newToken['token']) ?></code><button type="button" class="btn btn-primary btn-sm" data-copy="<?= e($newToken['token']) ?>">Copy</button></div></div></div>
      <?php endif; ?>
      <form method="post" action="/admin/system/tokens" class="mt-4">
        <?= csrf_field() ?>
        <div class="input-row">
          <?= component('form/field', ['name' => 'name', 'label' => 'What will use this token?', 'placeholder' => 'e.g. ERP read access', 'required' => true, 'attrs' => ['maxlength' => 100], 'class' => 'grow mb-0']) ?>
        </div>
        <div class="form-actions"><button type="submit" class="btn btn-secondary">Create token</button></div>
      </form>
      <?php if ($tokens): ?>
      <div class="table-wrap mt-4"><table class="table">
        <thead><tr><th>Name</th><th>Starts with</th><th>Made</th><th>Last used</th><th>Status</th><th class="actions"></th></tr></thead>
        <tbody>
        <?php foreach ($tokens as $t): $revoked = $t['revoked_at'] !== null; ?>
          <tr class="<?= $revoked ? 'void-row' : '' ?>">
            <td><?= e($t['name']) ?><br><span class="muted small">by <?= e($t['created_by_name'] ?? '—') ?></span></td>
            <td class="code">chroma_<?= e($t['token_prefix']) ?>…</td>
            <td class="nowrap"><?= e(fmt_datetime($t['created_at'])) ?></td>
            <td class="nowrap"><?= $t['last_used_at'] ? e(fmt_ago($t['last_used_at'])) : '<span class="muted">never</span>' ?></td>
            <td><?= component('badge', ['label' => $revoked ? 'Revoked' : 'Active', 'color' => $revoked ? 'gray' : 'green']) ?></td>
            <td class="actions"><?php if (!$revoked): ?><?= component('confirm-form', ['action' => '/admin/system/tokens/' . (int) $t['id'] . '/revoke', 'label' => 'Revoke', 'size' => 'sm', 'title' => 'Revoke "' . $t['name'] . '"?', 'body' => 'Anything still using this token will be refused from now on. This cannot be undone; you can make a new token instead.', 'confirm_label' => 'Revoke token']) ?><?php endif; ?></td>
          </tr>
        <?php endforeach; ?>
        </tbody></table></div>
      <?php else: ?>
      <p class="muted small mt-4">No tokens yet. Nothing outside Chroma can read its data until you make one.</p>
      <?php endif; ?>
    </div>
  </section>

  <section class="card" id="log" x-data="logTail()" style="grid-column: 1 / -1">
    <div class="card-head"><h2>App log</h2><span class="muted small"><?= e($info['log_file']) ?> — last 100 lines</span></div>
    <div class="card-body">
      <p class="small">Errors Chroma ran into, newest at the bottom. An administrator or developer may ask you to read a line from here.</p>
      <button type="button" class="btn btn-secondary btn-sm" @click="refresh()" :disabled="busy">Refresh</button>
      <pre class="log-panel mt-4"><span x-show="lines.length === 0"><?= e(implode("\n", $logTail)) ?></span><template x-for="(l, i) in lines" :key="i"><span :class="/ERROR/.test(l) ? 'l-error' : (/WARN/.test(l) ? 'l-warn' : '')" x-text="l + '\n'"></span></template></pre>
    </div>
  </section>
</div>
