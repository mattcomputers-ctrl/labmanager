<?= $view->partial('admin/_nav', ['current' => '/admin/import']) ?>
<?= $view->partial('admin/import/_steps', ['step' => 1]) ?>
<?= component('page-header', [
    'title' => 'Import legacy codes',
    'lede' => 'Bring the three spreadsheets into Chroma: the Code Cross Reference, the Security Product Code Log and the UV Product Code Log. Nothing is written until you have checked the dry run and pressed Commit.',
    'actions' => '<a class="btn btn-secondary" href="/admin/import/history">Past imports</a> <a class="btn btn-secondary" href="/admin/import/issues">Import issues' . ($openIssues ? ' <span class="badge badge-amber">' . (int) $openIssues . '</span>' : '') . '</a>',
]) ?>

<?php if ($pendingDryRun): ?>
  <?= component('callout', ['type' => 'info', 'title' => 'You have a dry run waiting', 'html' => true, 'body' => 'A dry run from ' . e(fmt_datetime($pendingDryRun['started_at'])) . ' has not been committed yet. <a href="/admin/import/review/' . (int) $pendingDryRun['id'] . '">Open it</a> to check and commit, or upload fresh files below.']) ?>
  <div class="mb-4"></div>
<?php endif; ?>

<div class="grid grid-2">
  <div class="card"><div class="card-body">
    <form method="post" action="/admin/import/upload" enctype="multipart/form-data" x-data="{ names: [], tooMany: false, busy: false, pick: function (files) { this.names = Array.from(files).map(function (f) { return f.name + ' (' + Math.round(f.size / 1024) + ' KB)'; }); this.tooMany = files.length > 3; } }" @submit="if (tooMany) { $event.preventDefault(); return; } busy = true">
      <?= csrf_field() ?>
      <div class="field">
        <label class="label" for="f-files">Spreadsheets <span class="req" aria-hidden="true">*</span></label>
        <div class="dropzone import-dropzone" :class="{ over: dragging }" x-data="{ dragging: false }" @dragover.prevent="dragging = true" @dragleave="dragging = false" @drop="dragging = false">
          <input type="file" id="f-files" name="files[]" multiple accept=".xls,.xlsx,application/vnd.ms-excel,application/vnd.openxmlformats-officedocument.spreadsheetml.sheet" @change="pick($event.target.files)" aria-describedby="f-files-help">
          <svg width="36" height="36" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M12 16V4M7 9l5-5 5 5M4 17v3h16v-3"/></svg>
          <p class="mb-0"><strong>Choose up to three files</strong> or drag them here.<br><span class="small">.xls or .xlsx, up to <?= (int) $maxMb ?> MB each.</span></p>
          <ul class="picked list" x-show="names.length" x-cloak>
            <template x-for="n in names" :key="n"><li><svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M4 4h10l6 6v10H4z"/></svg><span x-text="n"></span></li></template>
          </ul>
        </div>
        <p class="error-text" role="alert" x-show="tooMany" x-cloak><span>That is more than three files. Chroma reads at most three: the cross-reference, the security log and the UV log. Choose the files again, leaving the extra one out.</span></p>
        <?php $err = form_errors()['files'] ?? null; if ($err): ?><p class="error-text" role="alert"><?= e($err) ?></p><?php endif; ?>
        <p class="help" id="f-files-help">Chroma tells the sheets apart by their header row, not by the file name, so you can upload them in any order. The real spreadsheets stay in use until cutover: you can run this on a test install first and again on the day.</p>
      </div>
      <?= component('form/field', ['name' => 'map_uvv', 'type' => 'checkbox', 'label' => 'Import UVV rows under the UVVL prefix', 'value' => old('map_uvv', '1'), 'help' => 'The cross-reference spells the inherited UVVL prefix as UVV on many rows. Ticked: those rows become UVVL codes and the original spelling is kept. Unticked: they go under a new retired prefix called UVV.']) ?>
      <div class="form-actions">
        <button class="btn btn-primary<?= is_easy() ? ' btn-lg' : '' ?>" type="submit" :disabled="busy || tooMany"><span x-show="!busy">Upload and run a dry run</span><span x-show="busy" x-cloak>Reading the sheets…</span></button>
      </div>
      <p class="help mb-0">The dry run reads every row and reports what would be created, filled in or skipped, plus duplicates and rows it cannot read. It writes nothing.</p>
    </form>
  </div></div>

  <div class="stack">
    <div class="card"><div class="card-body">
      <h3>What the import does</h3>
      <ul class="small" style="padding-left:1.2em;margin:0 0 var(--s-3)">
        <li>Matches on the normalized code: <span class="code">y-1000</span>, <span class="code">Y 1000</span> and <span class="code">Y1000</span> are the same sell number; E numbers match on number and version (<span class="code">E6374-10</span>).</li>
        <li>Codes already in Chroma only get their <strong>empty</strong> fields filled in. Nothing is overwritten and nothing is deleted, so you can run it again later to catch up.</li>
        <li>A sell number listed twice keeps its first row; the other rows go to <a href="/admin/import/issues">Import issues</a> for you to sort out, along with junk like <span class="code">38-5000</span>.</li>
        <li>Prefixes that are not in Chroma yet are added as <em>retired</em> (visible in lookup, not assignable).</li>
        <li>Comments in the code logs become notes on the sell number. Legacy internal codes like <span class="code">2UY0480</span> are kept as text, not as E numbers.</li>
        <li>Afterwards every prefix counter and the next E number move past the highest number in use, unless you already set them higher.</li>
      </ul>
      <a href="/help/admin-import" class="small">Read the help page</a>
    </div></div>
    <?php if ($lastRun): ?>
    <div class="card"><div class="card-body">
      <h3>Last import</h3>
      <?php $s = json_decode((string) $lastRun['summary_json'], true); $a = $s['applied'] ?? []; ?>
      <p class="muted small mb-2"><?= e(fmt_datetime($lastRun['started_at'])) ?> by <?= e($lastRun['display_name'] ?? '') ?> · <?= component('badge', ['label' => $lastRun['status'] === 'done' ? 'Done' : ucfirst((string) $lastRun['status']), 'color' => $lastRun['status'] === 'done' ? 'green' : 'red']) ?></p>
      <?php if ($a): ?><p class="small mb-2"><?= number_format($a['create_internal'] ?? 0) ?> E numbers and <?= number_format($a['create_sell'] ?? 0) ?> sell numbers created, <?= number_format(($a['update_internal'] ?? 0) + ($a['update_sell'] ?? 0)) ?> filled in, <?= number_format($a['issues'] ?? 0) ?> issues.</p><?php endif; ?>
      <a class="btn btn-secondary btn-sm" href="/admin/import/history/<?= (int) $lastRun['id'] ?>">See the report</a>
    </div></div>
    <?php endif; ?>
    <p class="muted xs">The server accepts uploads up to <?= e($phpLimit) ?> per request (PHP setting). If a sheet is bigger, save it as .xlsx — it is usually a tenth of the size.</p>
  </div>
</div>
