<?= $view->partial('admin/_nav', ['current' => '/admin/import']) ?>
<?php $view->start('head'); ?><link rel="stylesheet" href="/assets/css/import.css"><?php $view->end(); ?>
<?php $isDry = (int) $run['dry_run'] === 1; $label = match ($run['status']) { 'done' => 'Done', 'failed' => 'Failed', 'dry_run' => 'Waiting to commit', 'committed' => 'Committed', 'superseded' => 'Replaced', 'running' => 'Running', 'committing' => 'Committing', default => ucfirst((string) $run['status']) }; ?>
<?= component('page-header', [
    'title' => ($isDry ? 'Dry run' : 'Import') . ' #' . (int) $run['id'],
    'badge' => component('badge', ['label' => $label, 'color' => in_array($run['status'], ['done', 'committed'], true) ? 'green' : ($run['status'] === 'failed' ? 'red' : 'blue')]),
    'breadcrumbs' => [['Admin', '/admin'], ['Import legacy codes', '/admin/import'], ['Past imports', '/admin/import/history'], ['#' . (int) $run['id'], null]],
    'actions' => $run['status'] === 'dry_run' ? '<a class="btn btn-primary" href="/admin/import/review/' . (int) $run['id'] . '">Review and commit</a>' : ($issueCount ? '<a class="btn btn-secondary" href="' . e(url('/admin/import/issues', ['import_id' => (int) $run['id'], 'status' => 'all'])) . '">Issues from this run (' . (int) $issueCount . ')</a>' : ''),
]) ?>
<div class="card mb-6"><div class="card-body">
  <?= component('kv', ['rows' => [
      ['Started', fmt_datetime($run['started_at'])],
      ['Finished', fmt_datetime($run['finished_at'])],
      ['By', $run['display_name'] ?? ''],
      ['Files', implode(', ', array_map(fn($f) => ($f['name'] ?? '') . ' (' . ($f['label'] ?? '') . ')', $run['files']))],
      ['UVV rows', !empty($summary['options']['map_uvv']) ? 'imported under UVVL' : 'kept as a separate retired prefix UVV'],
  ]]) ?>
</div></div>
<?php if ($summary): ?>
  <?= $view->partial('admin/import/_report', ['summary' => $summary]) ?>
<?php endif; ?>
<details class="import-section mt-6" <?= $run['status'] === 'failed' ? 'open' : '' ?>><summary>Full text report</summary>
  <pre class="import-log"><?= e((string) $run['log_text']) ?></pre>
</details>
