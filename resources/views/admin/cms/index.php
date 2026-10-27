<?php
$view->start('head'); ?><link rel="stylesheet" href="/assets/css/system-admin.css"><?php $view->end();
echo $view->partial('admin/_nav', ['current' => '/admin/cms']);
echo component('page-header', ['title' => 'CMS connection', 'lede' => 'A read-only link to the Mar-Kov CMS so the lab picks real customer names. Chroma only ever reads from it; nothing is written back.']);
$driverOk = $driverStatus === 'ok';
$fieldHelp = 'Ask the CMS administrator. Chroma does not guess table or column names.';
?>

<?php if (!$driverOk): ?>
  <?= component('callout', ['type' => 'warning', 'title' => 'SQL Server driver not installed', 'body' => $driverText]) ?>
  <div class="mb-4"></div>
<?php endif; ?>

<div class="sys-grid">
  <section class="card" id="status">
    <div class="card-head"><h2>Status</h2></div>
    <div class="card-body">
      <div class="sys-stats">
        <div class="stat"><div class="stat-value"><?= component('badge', ['label' => $enabled ? 'On' : 'Off', 'color' => $enabled ? 'green' : 'gray']) ?></div><div class="stat-label">Connection</div></div>
        <div class="stat"><div class="stat-value"><?= component('badge', ['label' => $driverOk ? 'Installed' : 'Missing', 'color' => $driverOk ? 'green' : 'red']) ?></div><div class="stat-label">SQL Server driver</div></div>
        <div class="stat"><div class="stat-value"><?= (int) ($customerCounts['cms'] ?? 0) ?></div><div class="stat-label">Customers from CMS (of <?= (int) ($customerCounts['total'] ?? 0) ?>)</div></div>
      </div>
      <?= component('kv', ['rows' => [
        ['Server', $conn['host'] !== '' ? $conn['host'] . ':' . $conn['port'] : 'not set'],
        ['Database', $conn['database']],
        ['Username', $conn['user'] !== '' ? $conn['user'] : 'not set'],
        ['Password', $conn['password_state']],
        ['Last sync', $lastSyncAt !== '' ? fmt_datetime($lastSyncAt) . ' — ' . $lastSyncResult : 'never'],
      ]]) ?>
      <div class="mt-4 flex flex-wrap" style="gap:var(--s-2)">
        <form method="post" action="/admin/cms/test" x-data="{ busy: false }" @submit="busy = true"><?= csrf_field() ?><button type="submit" class="btn btn-secondary" :disabled="busy"><span x-show="!busy">Test connection</span><span x-show="busy" x-cloak>Testing…</span></button></form>
        <?php if ($enabled): ?>
          <?= component('confirm-form', ['action' => '/admin/cms/sync', 'label' => 'Sync customers now', 'variant' => 'primary', 'title' => 'Sync customers from the CMS?', 'body' => 'Chroma reads the customer list from the CMS and adds any customers it does not have yet, and updates the names of customers that came from the CMS before. Nothing is deleted and nothing is written to the CMS.', 'confirm_label' => 'Sync now']) ?>
        <?php else: ?>
          <button type="button" class="btn btn-primary" disabled title="Turn the connection on first">Sync customers now</button>
        <?php endif; ?>
      </div>
      <?php if (!$enabled): ?><p class="help mt-4">The connection is off. Chroma works normally without it; customers can be added by hand. Turn it on below once the details are filled in and the test shows the right rows.</p><?php endif; ?>
    </div>
  </section>

  <section class="card" id="test">
    <div class="card-head"><h2>Test result</h2></div>
    <div class="card-body">
      <?php if ($preview): ?>
        <?= component('callout', ['type' => $preview['ok'] ? ($preview['rows'] ? 'success' : 'warning') : 'danger', 'body' => $preview['message']]) ?>
        <?php if ($preview['rows']): $cols = array_keys($preview['rows'][0]); ?>
          <div class="table-wrap mt-4"><table class="table preview-table">
            <thead><tr><?php foreach ($cols as $c): ?><th><?= e($c === 'cms_id' ? 'ID' : ($c === 'cms_name' ? 'Name' : ($c === 'cms_active' ? 'Active' : $c))) ?></th><?php endforeach; ?></tr></thead>
            <tbody><?php foreach ($preview['rows'] as $row): ?><tr><?php foreach ($cols as $c): ?><td><?= e(is_scalar($row[$c] ?? null) ? (string) $row[$c] : json_encode($row[$c])) ?></td><?php endforeach; ?></tr><?php endforeach; ?></tbody>
          </table></div>
          <p class="help">If the ID and Name columns look like real customer IDs and names, the mapping is right and it is safe to sync.</p>
        <?php endif; ?>
        <?php if ($preview['query'] !== ''): ?><details class="mt-4 audit-detail"><summary>Show the query Chroma runs</summary><pre class="sql-box"><?= e($preview['query']) ?></pre></details><?php endif; ?>
      <?php else: ?>
        <?= component('empty-state', ['title' => 'Nothing tested yet', 'text' => 'Press Test connection to see the first 10 customers the mapping returns. Check them before syncing.']) ?>
      <?php endif; ?>
    </div>
  </section>

  <section class="card" id="settings" style="grid-column: 1 / -1">
    <div class="card-head"><h2>Connection details and mapping</h2></div>
    <div class="card-body">
      <form method="post" action="/admin/cms" novalidate>
        <?= csrf_field() ?>
        <div class="grid grid-2">
          <div>
            <h3>SQL Server</h3>
            <p class="help">The password is kept in the server's config file, never in the database. Leave it blank to keep the one already saved.</p>
            <?= component('form/field', ['name' => 'host', 'label' => 'Server host', 'value' => old('host', $conn['host']), 'placeholder' => '10.10.5.30 or MARKOV-SQL', 'help' => 'Name or IP address of the SQL Server 2016 machine.']) ?>
            <div class="form-grid">
              <?= component('form/field', ['name' => 'port', 'label' => 'Port', 'type' => 'number', 'min' => 1, 'max' => 65535, 'value' => old('port', $conn['port'])]) ?>
              <?= component('form/field', ['name' => 'database', 'label' => 'Database', 'value' => old('database', $conn['database']), 'help' => 'Usually CMS.']) ?>
            </div>
            <?= component('form/field', ['name' => 'user', 'label' => 'Read-only username', 'value' => old('user', $conn['user']), 'attrs' => ['autocomplete' => 'off']]) ?>
            <?= component('form/field', ['name' => 'password', 'label' => 'Password', 'type' => 'password', 'value' => '', 'placeholder' => $conn['password_state'] === 'set' ? '(unchanged — a password is set)' : '(not set)', 'help' => 'Currently: ' . $conn['password_state'] . '. No double quotes or backslashes (the config file cannot store them).', 'attrs' => ['autocomplete' => 'new-password']]) ?>
            <?php if ($conn['password_state'] === 'set'): ?><?= component('form/field', ['name' => 'clear_password', 'label' => 'Remove the saved password', 'type' => 'checkbox', 'value' => '0']) ?><?php endif; ?>
          </div>
          <div>
            <h3>Where customers live in the CMS</h3>
            <p class="help"><?= e($fieldHelp) ?> The names below are typed exactly as they are in SQL Server.</p>
            <?= component('form/field', ['name' => 'cms_customer_table', 'label' => 'Customer table', 'value' => old('cms_customer_table', $mapping['cms_customer_table']), 'placeholder' => 'ask the CMS administrator, e.g. dbo.Customers', 'help' => 'Schema and table, such as dbo.Customers.']) ?>
            <div class="form-grid">
              <?= component('form/field', ['name' => 'cms_customer_id_column', 'label' => 'Customer ID column', 'value' => old('cms_customer_id_column', $mapping['cms_customer_id_column']), 'placeholder' => 'ask the CMS administrator']) ?>
              <?= component('form/field', ['name' => 'cms_customer_name_column', 'label' => 'Customer name column', 'value' => old('cms_customer_name_column', $mapping['cms_customer_name_column']), 'placeholder' => 'ask the CMS administrator']) ?>
            </div>
            <?= component('form/field', ['name' => 'cms_customer_active_column', 'label' => 'Active flag column', 'value' => old('cms_customer_active_column', $mapping['cms_customer_active_column']), 'optional_text' => true, 'placeholder' => 'leave blank if there is none', 'help' => 'A column that is 1 / Y / true for customers still in use.']) ?>
            <?= component('form/field', ['name' => 'cms_customer_filter', 'label' => 'Filter', 'value' => old('cms_customer_filter', $mapping['cms_customer_filter']), 'optional_text' => true, 'placeholder' => "e.g. Type = 'CUST'", 'help' => 'Only the part after WHERE, if the CMS administrator says some rows should be left out.']) ?>
            <?php if ($query !== ''): ?><details class="audit-detail"><summary>Show the query this mapping builds</summary><pre class="sql-box"><?= e($query) ?></pre></details><?php endif; ?>
          </div>
        </div>
        <hr style="margin:var(--s-4) 0">
        <?= component('form/field', ['name' => 'enabled', 'label' => 'Connection on: sync customers every night and allow Sync now', 'type' => 'checkbox', 'value' => old('enabled', $enabled ? '1' : '0'), 'help' => 'Needs the host, username, a saved password and the customer table filled in; Chroma refuses to turn it on otherwise.']) ?>
        <?= component('form/field', ['name' => 'deactivate_missing', 'label' => 'Mark customers that disappear from the CMS as inactive', 'type' => 'checkbox', 'value' => old('deactivate_missing', $deactivateMissing ? '1' : '0'), 'help' => 'Only customers that came from the CMS. Off by default: nothing is ever deleted either way.']) ?>
        <div class="form-actions"><button type="submit" class="btn btn-primary btn-lg">Save CMS settings</button></div>
      </form>
    </div>
  </section>

  <section class="card" id="sync" style="grid-column: 1 / -1">
    <div class="card-head"><h2>Recent syncs</h2></div>
    <div class="card-body">
      <?php if ($runs): ?>
      <div class="table-wrap"><table class="table">
        <thead><tr><th>Started</th><th>By</th><th>Result</th><th class="num">New</th><th class="num">Renamed</th><th>Details</th></tr></thead>
        <tbody>
        <?php foreach ($runs as $r): ?>
          <tr><td class="nowrap"><?= e(fmt_datetime($r['started_at'])) ?></td><td><?= e($r['started_by_name'] ?? 'nightly') ?></td><td><?= component('badge', ['label' => $r['status'] === 'ok' ? 'OK' : ($r['status'] === 'running' ? 'Running' : 'Failed'), 'color' => $r['status'] === 'ok' ? 'green' : ($r['status'] === 'running' ? 'blue' : 'red')]) ?></td><td class="num"><?= (int) $r['created_count'] ?></td><td class="num"><?= (int) $r['updated_count'] ?></td><td class="small"><?= e($r['message']) ?></td></tr>
        <?php endforeach; ?>
        </tbody></table></div>
      <?php else: ?>
        <?= component('empty-state', ['title' => 'No syncs yet', 'text' => 'Each nightly or manual sync is listed here with what it added or changed.']) ?>
      <?php endif; ?>
    </div>
  </section>
</div>
