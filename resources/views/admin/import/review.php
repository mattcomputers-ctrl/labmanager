<?= $view->partial('admin/_nav', ['current' => '/admin/import']) ?>
<?= $view->partial('admin/import/_steps', ['step' => 2]) ?>
<?= component('page-header', [
    'title' => 'Check the dry run',
    'lede' => 'Nothing has been written. Read through what would happen, then commit at the bottom of the page.',
    'breadcrumbs' => [['Admin', '/admin'], ['Import legacy codes', '/admin/import'], ['Dry run #' . (int) $run['id'], null]],
]) ?>

<?php if ($committedAs): ?>
  <?= component('callout', ['type' => 'success', 'title' => 'This dry run has been committed', 'html' => true, 'body' => 'See <a href="/admin/import/done/' . (int) $committedAs['id'] . '">the result</a>, or <a href="/admin/import">upload files again</a> for a catch-up import.']) ?>
  <div class="mb-4"></div>
<?php elseif ($run['status'] === 'committing'): ?>
  <?= component('callout', ['type' => 'info', 'title' => 'This dry run is being committed right now', 'html' => true, 'body' => 'Someone (perhaps you, in another tab) pressed Commit a moment ago. It usually takes under a minute. <a href="/admin/import/history">Open Past imports</a> to see the result when it finishes; do not commit it again.']) ?>
  <div class="mb-4"></div>
<?php elseif ($run['status'] !== 'dry_run'): ?>
  <?= component('callout', ['type' => 'warning', 'title' => 'This dry run was replaced', 'html' => true, 'body' => 'A newer dry run of the same files exists. Open <a href="/admin/import/history">Past imports</a> to find it, or <a href="/admin/import">upload the files again</a>.']) ?>
  <div class="mb-4"></div>
<?php elseif (!$filesOk): ?>
  <?= component('callout', ['type' => 'danger', 'title' => 'The uploaded files are no longer on the server', 'html' => true, 'body' => 'This can happen after a cleanup or an update. <a href="/admin/import">Upload them again</a> to get a fresh dry run.']) ?>
  <div class="mb-4"></div>
<?php endif; ?>

<p class="muted small">Dry run #<?= (int) $run['id'] ?> · <?= e(fmt_datetime($run['started_at'])) ?> by <?= e($run['display_name'] ?? '') ?></p>
<?= $view->partial('admin/import/_report', ['summary' => $summary]) ?>

<?php if ($run['status'] === 'dry_run' && $filesOk): ?>
<form method="post" action="/admin/import/commit/<?= (int) $run['id'] ?>" class="import-confirm" x-data="{ busy: false }" @submit="busy = true">
  <?= csrf_field() ?>
  <h3>Ready to commit?</h3>
  <p class="small">Committing creates the codes listed above, fills in empty fields on codes Chroma already has, writes the issues, and moves the counters past the highest numbers in use. It does not delete or overwrite anything. It usually takes well under a minute; please do not close the page while it runs.</p>
  <?php if ($needsUvvConfirm): ?>
    <?= component('form/field', ['name' => 'confirm_uvv', 'type' => 'checkbox', 'label' => 'Yes, import the ' . number_format((int) ($summary['lists']['uvv_rows']['total'] ?? 0)) . ' UVV rows under the UVVL prefix', 'value' => old('confirm_uvv', '0'), 'help' => 'Matt: the cross-reference spells UVVL as UVV on these rows. Untick and use the button below if they should stay as a separate UVV prefix.']) ?>
  <?php endif; ?>
  <?= component('form/field', ['name' => 'confirm_commit', 'type' => 'checkbox', 'label' => 'I have checked the dry run above', 'value' => old('confirm_commit', '0')]) ?>
  <div class="import-actions">
    <a class="btn btn-secondary" href="/admin/import">‹ Upload different files</a>
    <span class="spacer"></span>
    <button class="btn btn-primary<?= is_easy() ? ' btn-lg' : '' ?>" type="submit" :disabled="busy"><span x-show="!busy">Commit the import</span><span x-show="busy" x-cloak>Importing… please wait</span></button>
  </div>
</form>
<form method="post" action="/admin/import/review/<?= (int) $run['id'] ?>/rerun" class="mt-4">
  <?= csrf_field() ?>
  <input type="hidden" name="map_uvv" value="<?= !empty($summary['options']['map_uvv']) ? '0' : '1' ?>">
  <p class="small muted mb-2">Changed your mind about the UVV rows? Run the dry run again with the mapping <?= !empty($summary['options']['map_uvv']) ? 'switched off (UVV becomes its own retired prefix)' : 'switched on (UVV rows become UVVL codes)' ?>.</p>
  <button class="btn btn-secondary btn-sm" type="submit">Run the dry run again with UVV → UVVL <?= !empty($summary['options']['map_uvv']) ? 'off' : 'on' ?></button>
</form>
<?php endif; ?>
<p class="mt-6"><a class="small" href="/admin/import/history/<?= (int) $run['id'] ?>">See the full text report</a></p>
