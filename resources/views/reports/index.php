<?php $view->start('head'); ?><link rel="stylesheet" href="/assets/css/reports.css"><?php $view->end(); ?>
<?= component('page-header', [
    'title' => 'Reports',
    'lede' => is_easy()
        ? 'Pick a report to see how the lab is doing. Every report has filters at the top and can be downloaded as a spreadsheet.'
        : 'Turnaround, aging, codes per month, proofs, R&D stages and item progress. Filter, then export as CSV or Excel.',
]) ?>
<div class="report-cards">
  <?php foreach ($catalogue as $key => $c): $h = $headlines[$key] ?? null; ?>
    <a class="card report-card" href="/reports/<?= e($key) ?>">
      <div class="report-card-body">
        <h2><?= e($c['title']) ?></h2>
        <?php if ($h): ?><div class="report-card-stat"><span class="report-card-value"><?= e($h['value']) ?></span><span class="report-card-label"><?= e($h['label']) ?></span></div><?php endif; ?>
        <p><?= e($c['text']) ?></p>
      </div>
      <div class="report-card-foot">Open report ›</div>
    </a>
  <?php endforeach; ?>
</div>
<?php if (is_easy()): ?>
<?= component('callout', ['type' => 'info', 'title' => 'Where do these numbers come from?', 'body' => 'Reports read the projects, items and codes that are already in Chroma; nothing here changes anything. Dates are the calendar dates on the project or code, shown in the lab\'s own timezone. See the Reports help page for what each one means.']) ?>
<?php endif; ?>
