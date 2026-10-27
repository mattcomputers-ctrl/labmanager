<?php
/** One product card: the E number (if any) with all its sell numbers. @var array $product */
$i = $product['internal'];
$sells = $product['sells'];
$showAssignedBy = is_expert();
$visibility = new \Chroma\Domain\Projects\Visibility(app()->db, app()->auth);
?>
<article class="card product-card<?= $product['voided'] ? ' is-void' : '' ?>">
  <div class="product-head">
    <div class="product-code">
      <?php if ($i): ?>
        <?= component('code-pill', ['code' => $i['display'], 'href' => '/codes/e/' . (int) $i['id'], 'void' => (int) $i['voided'] === 1]) ?>
        <?php if ((int) $i['voided'] === 1): ?><?= component('badge', ['label' => 'Voided', 'color' => 'red']) ?><?php endif; ?>
        <?php if ((int) $i['resale'] === 1): ?><?= component('badge', ['label' => 'Resale', 'color' => 'purple']) ?><?php endif; ?>
      <?php else: ?>
        <?= component('code-pill', ['code' => $sells[0]['display'], 'href' => '/codes/s/' . (int) $sells[0]['id'], 'void' => (int) $sells[0]['voided'] === 1]) ?>
        <?php if ((int) $sells[0]['voided'] === 1): ?><?= component('badge', ['label' => 'Voided', 'color' => 'red']) ?><?php endif; ?>
        <?= component('badge', ['label' => 'No E number yet', 'color' => 'amber']) ?>
      <?php endif; ?>
    </div>
    <div class="product-desc"><?= e($product['description'] ?: '(no description)') ?></div>
    <div class="product-meta">
      <?php if ($product['customer']): ?><span>Customer <b><?= e($product['customer']) ?></b></span><?php endif; ?>
      <?php if ($i): ?>
        <span>E# assigned <b><?= e(fmt_date($i['date_assigned']) ?: '—') ?></b><?= $showAssignedBy && $i['assigned_by_name'] ? ' by ' . e($i['assigned_by_name']) : '' ?></span>
        <?php if ((int) $i['resale'] === 1 && ($i['vendor_name'] || $i['vendor_code'])): ?><span>Vendor <b><?= e(trim($i['vendor_name'] . ' ' . $i['vendor_code'])) ?></b></span><?php endif; ?>
        <?php if ($i['legacy_handschy_number']): ?><span>Handschy # <b><?= e($i['legacy_handschy_number']) ?></b></span><?php endif; ?>
      <?php endif; ?>
      <?php if ($i && !$sells): ?><span class="muted">No sell number yet</span><?php endif; ?>
    </div>
  </div>
  <?php if ($sells): ?>
  <div class="table-wrap">
    <table class="table<?= is_expert() ? ' table-dense' : '' ?>">
      <thead><tr><th>Sell number</th><th>Description</th><th>Date</th><th>Customer</th><th>Reason</th><?php if ($showAssignedBy): ?><th>Assigned by</th><?php endif; ?></tr></thead>
      <tbody>
      <?php foreach ($sells as $s): $void = (int) $s['voided'] === 1; ?>
        <tr class="clickable<?= $void ? ' void-row' : '' ?>" data-href="/codes/s/<?= (int) $s['id'] ?>">
          <td class="nowrap"><?= component('code-pill', ['code' => $s['display'], 'href' => '/codes/s/' . (int) $s['id'], 'void' => $void]) ?><?= $void ? ' ' . component('badge', ['label' => 'Voided', 'color' => 'red']) : '' ?><?= $s['legacy_text'] && $s['legacy_text'] !== $s['display'] ? ' <span class="muted xs" title="As written on the old sheet">(' . e($s['legacy_text']) . ')</span>' : '' ?></td>
          <td><?= e($s['description']) ?></td>
          <td class="nowrap"><?= e(fmt_date($s['date_assigned'])) ?></td>
          <td><?= e($s['customer'] ?? '') ?></td>
          <td class="small"><?= e($s['reason'] ? (\Chroma\Domain\Codes\CodeAssigner::REASONS[$s['reason']] ?? $s['reason']) : '') ?></td>
          <?php if ($showAssignedBy): ?><td class="small"><?= e($s['assigned_by_name'] ?? '') ?></td><?php endif; ?>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
  </div>
  <?php endif; ?>
  <?php if ($product['origins']): ?>
  <div class="product-origin">
    <span>From</span>
    <?php foreach ($product['origins'] as $o): $hidden = !$visibility->canViewOrigin($o); ?>
      <?php if ($hidden): ?><span>a confidential project</span><?php else: ?>
      <a href="/projects/<?= (int) $o['project_id'] ?>">Project <?= (int) $o['project_number'] ?><?= $o['project_title'] ? ' · ' . e($o['project_title']) : '' ?></a><?= $o['item_description'] ? ' <span class="muted">(item ' . (int) $o['sequence'] . ': ' . e($o['item_description']) . ')</span>' : '' ?>
      <?php endif; ?>
    <?php endforeach; ?>
  </div>
  <?php endif; ?>
</article>
