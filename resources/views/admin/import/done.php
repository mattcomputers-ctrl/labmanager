<?= $view->partial('admin/_nav', ['current' => '/admin/import']) ?>
<?= $view->partial('admin/import/_steps', ['step' => 3]) ?>
<?php $a = $summary['applied'] ?? []; $failed = $run['status'] === 'failed'; ?>
<?php if ($failed): ?>
  <?= component('page-header', ['title' => 'The import did not finish', 'lede' => 'Nothing from this run was kept: the whole import is one transaction, so a failure part-way leaves Chroma exactly as it was.', 'breadcrumbs' => [['Admin', '/admin'], ['Import legacy codes', '/admin/import'], ['Run #' . (int) $run['id'], null]]]) ?>
  <?= component('callout', ['type' => 'danger', 'title' => 'What went wrong', 'body' => trim((string) preg_replace('/^.*FAILED: /ms', '', (string) $run['log_text'])) ?: 'The server log has the details.']) ?>
  <div class="import-actions"><a class="btn btn-primary" href="/admin/import">Try the upload again</a><a class="btn btn-secondary" href="/admin/import/history/<?= (int) $run['id'] ?>">See the log</a></div>
<?php else: ?>
  <div class="wizard"><div class="card wizard-card success-screen">
    <div class="success-icon"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><path d="m5 12 5 5L20 7"/></svg></div>
    <h1>Import finished</h1>
    <p class="wizard-intro">Run #<?= (int) $run['id'] ?> · <?= e(fmt_datetime($run['finished_at'])) ?> by <?= e($run['display_name'] ?? '') ?><?= isset($a['seconds']) ? ' · took ' . e((string) $a['seconds']) . ' s' : '' ?></p>
    <div style="text-align:left;max-width:560px;margin:0 auto var(--s-4)">
      <?= component('kv', ['rows' => [
          ['E numbers created', number_format((int) ($a['create_internal'] ?? 0))],
          ['Sell numbers created', number_format((int) ($a['create_sell'] ?? 0))],
          ['Existing codes filled in', number_format((int) ($a['update_internal'] ?? 0) + (int) ($a['update_sell'] ?? 0))],
          ['Links to E numbers added', number_format((int) ($a['links'] ?? 0))],
          ['Customers created', number_format((int) ($a['customers'] ?? 0))],
          ['Retired prefixes created', number_format((int) ($a['prefixes'] ?? 0))],
          ['Rows refused by the code rules', number_format((int) ($a['refused'] ?? 0))],
          ['Issues to resolve', number_format((int) ($a['issues'] ?? 0))],
          ['Counters moved', $a['counters'] ? implode('<br>', array_map('e', $a['counters'])) : 'None needed', true],
      ]]) ?>
    </div>
    <div class="next-actions">
      <?php if ($openIssues > 0): ?>
        <a class="btn btn-primary btn-lg" href="<?= e(url('/admin/import/issues', ['import_id' => (int) $run['id']])) ?>">Resolve the <?= number_format($openIssues) ?> open <?= $openIssues === 1 ? 'issue' : 'issues' ?></a>
        <a class="btn btn-secondary" href="/codes">Open code lookup</a>
      <?php else: ?>
        <a class="btn btn-primary btn-lg" href="/codes">Open code lookup</a>
      <?php endif; ?>
      <a class="btn btn-secondary" href="/admin/prefixes">Check prefixes and counters</a>
      <a class="btn btn-secondary" href="/admin/import/history/<?= (int) $run['id'] ?>">Full report</a>
      <a class="btn btn-secondary" href="/admin/import">Run another import</a>
    </div>
  </div></div>
  <div class="mt-8"><?= $view->partial('admin/import/_report', ['summary' => $summary, 'compact' => true]) ?></div>
<?php endif; ?>
