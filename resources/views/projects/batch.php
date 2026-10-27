<?php /** Expert batch assignment: one review page, one transaction per item on submit. Prefix per row defaults to the row above. */
$view->start('head'); ?><link rel="stylesheet" href="/assets/css/projects.css"><script src="/assets/js/projects.js"></script><?php $view->end();
$pid = (int) $project['id']; $errors = form_errors();
$rowsCfg = array_map(fn($i) => ['id' => (int) $i['id'], 'canInternal' => $canInternal && $i['internal_code_id'] === null, 'canSell' => $canSell && $i['sell_number_id'] === null, 'internal' => $canInternal && $i['internal_code_id'] === null, 'sell' => $canSell && $i['sell_number_id'] === null, 'prefix' => $project['default_prefix_id']], $selected);
?>
<?= component('page-header', ['title' => 'Assign codes to several ' . mb_strtolower((string) $project['item_label']), 'lede' => 'Tick what each one gets and press Assign. Each item is handled on its own, so one failure does not stop the others. Numbers are reserved permanently.', 'breadcrumbs' => [['Projects', '/projects'], ['Project ' . $project['project_number'], '/projects/' . $pid . '#items'], ['Assign codes', null]]]) ?>
<?php if (!$selected): ?>
  <?= component('empty-state', ['title' => 'Nothing to assign', 'text' => 'Every selected ' . mb_strtolower((string) $project['item_label_singular']) . ' already has both codes. Pick others from the project page.', 'action_href' => '/projects/' . $pid . '#items', 'action_label' => 'Back to the project']) ?>
<?php else: ?>
<form method="post" action="/projects/<?= $pid ?>/batch" class="card" x-data="batchForm(<?= json_attr(['rows' => $rowsCfg, 'prefixSuffixes' => (object) $prefixSuffixes]) ?>)">
  <?= csrf_field() ?>
  <?php if (isset($errors['_'])): ?><div class="card-body"><?= component('callout', ['type' => 'danger', 'body' => $errors['_']]) ?></div><?php endif; ?>
  <div class="table-wrap" style="border:0;box-shadow:none;border-radius:0">
    <table class="table batch-table table-dense">
      <thead><tr>
        <th>#</th><th><?= e($project['item_label_singular']) ?></th>
        <?php if ($canInternal): ?><th>E#<br><a href="#" class="small" @click.prevent="setAll('internal', true)">all</a> · <a href="#" class="small" @click.prevent="setAll('internal', false)">none</a></th><?php endif; ?>
        <?php if ($canSell): ?><th>Sell number<br><a href="#" class="small" @click.prevent="setAll('sell', true)">all</a> · <a href="#" class="small" @click.prevent="setAll('sell', false)">none</a></th><th>Prefix</th><th>Suffix</th><?php endif; ?>
        <th>Description for the code</th>
      </tr></thead>
      <tbody>
      <?php foreach ($selected as $it): $iid = (int) $it['id']; ?>
        <tr>
          <td class="num"><?= (int) $it['sequence'] ?></td>
          <td><div class="item-desc"><?= component('chip', ['hex' => $it['hex_color']]) ?><?= e($it['description']) ?></div><div class="item-sub"><?= e(implode(' · ', array_filter([$it['internal_display'] ? 'has ' . $it['internal_display'] : null, $it['sell_display'] ? 'has ' . $it['sell_display'] : null]))) ?></div></td>
          <?php if ($canInternal): ?><td><?php if ($it['internal_code_id'] === null): ?><input type="checkbox" name="rows[<?= $iid ?>][internal]" value="1" x-model="r[<?= $iid ?>].internal" aria-label="E number for <?= e($it['description']) ?>"><?php else: ?><span class="muted small">has one</span><?php endif; ?></td><?php endif; ?>
          <?php if ($canSell): ?>
            <td><?php if ($it['sell_number_id'] === null): ?><input type="checkbox" name="rows[<?= $iid ?>][sell]" value="1" x-model="r[<?= $iid ?>].sell" aria-label="Sell number for <?= e($it['description']) ?>"><?php else: ?><span class="muted small">has one</span><?php endif; ?></td>
            <td><?php if ($it['sell_number_id'] === null): ?><select name="rows[<?= $iid ?>][prefix_id]" x-model="r[<?= $iid ?>].prefix" @change="prefixChanged(<?= $iid ?>)" :disabled="!r[<?= $iid ?>].sell" aria-label="Prefix"><option value="">Choose…</option><?php foreach ($prefixes as $p): ?><option value="<?= (int) $p['id'] ?>"><?= e($p['code'] . ' — ' . $p['label']) ?></option><?php endforeach; ?></select><?php endif; ?></td>
            <td><?php if ($it['sell_number_id'] === null): ?><select name="rows[<?= $iid ?>][suffix_id]" x-model="r[<?= $iid ?>].suffix" @change="suffixChanged(<?= $iid ?>)" x-show="suffixes(<?= $iid ?>).length" :disabled="!r[<?= $iid ?>].sell" aria-label="Suffix"><option value="">(none)</option><template x-for="s in suffixes(<?= $iid ?>)" :key="s.id"><option :value="s.id" x-text="s.code"></option></template></select><span class="muted small" x-show="!suffixes(<?= $iid ?>).length">—</span><?php endif; ?></td>
          <?php endif; ?>
          <td><input type="text" name="rows[<?= $iid ?>][description]" value="<?= e($it['description']) ?>" maxlength="500" style="min-width:220px" aria-label="Description"></td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
  </div>
  <div class="card-foot form-actions sticky-bottom">
    <button type="submit" class="btn btn-primary btn-lg" :disabled="!valid"><span x-text="'Assign ' + count + (count === 1 ? ' code' : ' codes')"></span></button>
    <a class="btn btn-ghost" href="/projects/<?= $pid ?>#items">Cancel</a>
    <span class="muted small" x-show="!valid && count">Pick a prefix for every sell number.</span>
    <span class="muted small"><?= count($selected) ?> of <?= (int) $allCount ?> <?= e(mb_strtolower((string) $project['item_label'])) ?> selected. The prefix of a row fills down to the rows below until you change them.</span>
  </div>
</form>
<?php endif; ?>
