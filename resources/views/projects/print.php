<?php /** Printable project sheet + formula-card summary per item, with a QR code that opens the project. Uses layouts/plain. */
use Chroma\Domain\Projects\Projects;
$pid = (int) $project['id'];
?>
<link rel="stylesheet" href="/assets/css/projects.css">
<div class="print-page">
  <div class="print-only-actions no-print">
    <button type="button" class="btn btn-primary" onclick="window.print()">Print</button>
    <a class="btn btn-ghost" href="/projects/<?= $pid ?>">Back to the project</a>
  </div>
  <div class="print-head">
    <div>
      <div class="project-number"><?= e($companyName) ?> · Chroma</div>
      <h1>Project <?= (int) $project['project_number'] ?> · <?= e($project['title']) ?></h1>
      <div><?= e($project['type_label']) ?> · <?= e(Projects::STATUSES[$project['status']] ?? $project['status']) ?><?= (int) $project['confidential'] ? ' · CONFIDENTIAL' : '' ?> · <?= e($project['progress']['label']) ?></div>
    </div>
    <?php if ($qr): ?><div class="print-qr" title="<?= e($url) ?>"><?= $qr ?></div><?php endif; ?>
  </div>
  <div class="print-fields">
    <?php if ($project['customer_name']): ?><div><div class="k">Customer</div><div><?= e($project['customer_name']) ?></div></div><?php endif; ?>
    <div><div class="k">Tech / lead</div><div><?= e($project['tech_name'] ?: '—') ?></div></div>
    <div><div class="k">Submitted</div><div><?= e(fmt_date($project['date_submitted']) ?: '—') ?></div></div>
    <?php if ($project['date_completed']): ?><div><div class="k">Completed</div><div><?= e(fmt_date($project['date_completed'])) ?></div></div><?php endif; ?>
    <?php if ($project['target_date']): ?><div><div class="k">Target</div><div><?= e(fmt_date($project['target_date'])) ?></div></div><?php endif; ?>
    <?php if ($project['stage_label']): ?><div><div class="k">Stage</div><div><?= e($project['stage_label']) ?></div></div><?php endif; ?>
    <?php if ($team): ?><div><div class="k">Team</div><div><?= e(implode(', ', array_map(fn($u) => $u['display_name'] ?: $u['username'], $team))) ?></div></div><?php endif; ?>
    <?php foreach ($fields as $f): if ($f['type'] !== 'textarea' || $f['display'] === '') { continue; } ?>
      <div class="wide"><div class="k"><?= e($f['label']) ?></div><div><?= nl2br(e($f['display'])) ?></div></div>
    <?php endforeach; ?>
    <div class="wide"><div class="k">Open in Chroma</div><div><?= e($url) ?></div></div>
  </div>

  <h2 style="font-size:16px;margin:0 0 8px"><?= e($project['item_label']) ?> (<?= count($items) ?>)</h2>
  <?php if (!$items): ?><p class="muted">None yet.</p><?php endif; ?>
  <div class="cards">
    <?php foreach ($items as $it): ?>
      <div class="formula-card">
        <div class="fc-codes">
          <?php if ($it['sell_display']): ?><span class="fc-sell"><?= e($it['sell_display']) ?></span><?php else: ?><span class="fc-none">No sell number yet</span><?php endif; ?>
          <span class="fc-e"><?= e($it['internal_display'] ?: 'no E#') ?></span>
          <?php if ($it['hex_color']): ?><span class="fc-chip" style="background:<?= e($it['hex_color']) ?>" title="<?= e($it['hex_color']) ?>"></span><?php endif; ?>
        </div>
        <div class="fc-desc"><?= (int) $it['sequence'] ?>. <?= e($it['description']) ?><?= $it['pantone'] && !str_contains(mb_strtolower((string) $it['description']), mb_strtolower((string) $it['pantone'])) ? ' · Pantone ' . e($it['pantone']) : '' ?></div>
        <?php if ((int) $it['is_digital_match']): ?><div class="fc-digital">Digital Match</div><?php endif; ?>
        <div class="fc-row"><span class="k">Customer</span><?= e($project['customer_name'] ?: '—') ?></div>
        <div class="fc-row"><span class="k">Status</span><?= e($it['status_label'] ?: '—') ?></div>
        <div class="fc-row"><span class="k">Substrate</span><?= e($it['substrate_display'] ?: '—') ?></div>
        <div class="fc-row"><span class="k">Coating</span><?= e($it['coating_name'] ?: '—') ?></div>
        <div class="fc-row"><span class="k">Ink series</span><?= e($it['ink_series_name'] ?: '—') ?></div>
        <div class="fc-row"><span class="k">Proofs</span><?= (int) $it['proofs_generated'] ? 'Yes · ' . (int) $it['proof_count'] : 'No' ?></div>
        <?php if ($it['notes']): ?><div class="fc-row" style="grid-column:1/-1"><span class="k">Notes</span><?= nl2br(e($it['notes'])) ?></div><?php endif; ?>
      </div>
    <?php endforeach; ?>
  </div>
  <p class="muted" style="font-size:11px;margin-top:16px">Printed <?= e(fmt_datetime(gmdate('Y-m-d H:i:s'))) ?> by <?= e($auth->displayName()) ?>. Scan the code to open the project in Chroma.</p>
</div>
<script>if (location.search.indexOf('auto=1') !== -1) { window.addEventListener('load', function () { setTimeout(function () { window.print(); }, 300); }); }</script>
