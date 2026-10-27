<?php
/** Items / colors table on the project page. Expert: dense, inline new row, assign popovers, checkboxes for batch. Easy: fewer columns, larger rows, wizard links. */
$pid = (int) $project['id'];
$canI = can('codes.assign_internal') && $isOpen;
$canS = can('codes.assign_sell') && $isOpen;
$canAnyAssign = $canI || $canS;
$full = count($items) >= $maxItems;
$ids = array_map(fn($i) => (int) $i['id'], $items);
$defaultKind = $type['default_item_kind'] ?? 'color';
$showKind = fn(array $i) => $i['kind'] !== $defaultKind;
$addUrl = url('/wizard/add-item', ['project_id' => $pid]);
$addExistingUrl = url('/wizard/add-item', ['project_id' => $pid, 'kind' => 'existing_product']);
$batchUrl = '/projects/' . $pid . '/batch';
?>
<section id="items" class="card section-tabs" x-data="itemsSection(<?= json_attr(['ids' => $ids, 'url' => $batchUrl, 'reorderUrl' => '/projects/' . $pid . '/items/reorder']) ?>)">
  <div class="card-head" style="flex-wrap:wrap;gap:var(--s-2)">
    <h2><?= e($project['item_label']) ?> <span class="muted small"><?= count($items) ?> of <?= (int) $maxItems ?></span></h2>
    <div class="flex flex-wrap" style="gap:var(--s-2);align-items:center">
      <?php if ($canAnyAssign && count($items) > 1): ?>
        <?php if (is_expert()): ?>
          <button type="button" class="btn btn-secondary btn-sm" @click="go()" x-show="selected.length" x-cloak>Assign codes to <span x-text="selected.length"></span> selected</button>
          <a class="btn btn-secondary btn-sm" href="<?= e($batchUrl) ?>" x-show="!selected.length">Assign codes to several</a>
        <?php else: ?>
          <a class="btn btn-secondary" href="<?= e(url('/wizard/batch-assign', ['project_id' => $pid])) ?>">Assign codes to several</a>
        <?php endif; ?>
      <?php endif; ?>
      <?php if ($canItems && is_easy()): ?>
        <?php if ($defaultKind !== 'existing_product'): ?><a class="btn btn-ghost" href="<?= e($addExistingUrl) ?>"<?= $full ? ' aria-disabled="true" tabindex="-1"' : '' ?>>Add an existing product</a><?php endif; ?>
        <a class="btn btn-primary btn-lg" href="<?= e($full ? '#' : $addUrl) ?>"<?= $full ? ' aria-disabled="true" tabindex="-1" title="This project is full"' : '' ?>>Add <?= e($singular) ?></a>
      <?php endif; ?>
    </div>
  </div>
  <?php if (isset($errors['description']) || isset($errors['existing_ref']) || isset($errors['items'])): ?>
    <div class="card-body" style="padding-bottom:0"><?= component('callout', ['type' => 'danger', 'body' => $errors['items'] ?? $errors['description'] ?? $errors['existing_ref']]) ?></div>
  <?php endif; ?>

  <?php if (!$items && !($canItems && is_expert())): ?>
    <?= component('empty-state', ['title' => 'No ' . $plural . ' yet', 'text' => 'Add the ' . $plural . ' this project is about. Each one gets its own status, tech, notes and codes.', 'action_href' => $canItems ? $addUrl : null, 'action_label' => 'Add the first ' . $singular]) ?>
  <?php else: ?>
  <div class="table-wrap" style="border:0;box-shadow:none;border-radius:0">
    <table class="table items-table<?= is_expert() ? ' table-dense' : '' ?>">
      <thead>
        <tr>
          <?php if (is_expert() && $canAnyAssign): ?><th class="check-col"><label class="check check-cell"><input type="checkbox" aria-label="Select all" :checked="allOn" @change="toggleAll($event.target.checked)"></label></th><?php endif; ?>
          <th class="num-col">#</th>
          <th class="item-desc-col"><?= e($project['item_label_singular']) ?></th>
          <?php if (is_expert()): ?>
            <th>Proofs</th><th>Digital</th>
          <?php endif; ?>
          <th>E#</th>
          <th>Sell number</th>
          <th>Status</th>
          <?php if (is_expert()): ?><th>Notes</th><?php endif; ?>
          <th class="right"></th>
        </tr>
      </thead>
      <tbody data-items>
      <?php foreach ($items as $n => $it): $iid = (int) $it['id']; $base = '/projects/' . $pid . '/items/' . $iid; ?>
        <tr data-item-id="<?= $iid ?>" :class="{ dragover: over === <?= $iid ?> }" @dragenter.prevent="enter(<?= $iid ?>)" @dragover.prevent @drop.prevent="drop(<?= $iid ?>)" @dragend="end()">
          <?php if (is_expert() && $canAnyAssign): ?><td class="check-col"><label class="check check-cell"><input type="checkbox" value="<?= $iid ?>" x-model="selected" aria-label="Select <?= e($it['description']) ?>"<?= ($it['internal_code_id'] !== null && $it['sell_number_id'] !== null) ? ' disabled title="Already has both codes"' : '' ?>></label></td><?php endif; ?>
          <td class="num nowrap" data-label="#">
            <?php if ($canItems && count($items) > 1): ?>
              <span class="drag-handle" draggable="true" @dragstart="start(<?= $iid ?>)" title="Drag to reorder" aria-hidden="true">⋮⋮</span>
              <span class="order-btns">
                <form method="post" action="<?= e($base) ?>/reorder" style="margin:0"><?= csrf_field() ?><input type="hidden" name="direction" value="up"><button type="submit" class="btn btn-ghost btn-sm" aria-label="Move up"<?= $n === 0 ? ' disabled' : '' ?>>▲</button></form>
                <form method="post" action="<?= e($base) ?>/reorder" style="margin:0"><?= csrf_field() ?><input type="hidden" name="direction" value="down"><button type="submit" class="btn btn-ghost btn-sm" aria-label="Move down"<?= $n === count($items) - 1 ? ' disabled' : '' ?>>▼</button></form>
              </span>
            <?php endif; ?>
            <span class="n"><?= (int) $it['sequence'] ?></span>
          </td>
          <td class="item-desc-cell" data-label="<?= e($project['item_label_singular']) ?>">
            <div class="item-desc" data-n="<?= (int) $it['sequence'] ?>"><?= component('chip', ['hex' => $it['hex_color'], 'title' => $it['pantone'] ?: $it['hex_color']]) ?><?= e($it['description']) ?></div>
            <div class="item-sub">
              <?php if ($showKind($it)): ?><?= component('badge', ['label' => $it['kind_label'], 'color' => 'gray']) ?> <?php endif; ?>
              <?php
              // Only facts that differ from the project's own defaults (shown in the header) are repeated on the row.
              $sub = $it['pantone'] ? ['Pantone ' . $it['pantone']] : [];
              $differs = fn(?string $v, ?string $projectDefault) => ($v !== null && $v !== '' && mb_strtolower(trim($v)) !== mb_strtolower(trim((string) $projectDefault))) ? $v : null;
              $facts = [
                  $differs($it['ink_series_name'], $project['default_ink_series_name'] ?? null),
                  $differs($it['substrate_display'], $project['default_substrate_text'] ?? null),
                  $differs($it['coating_name'], $project['default_coating_name'] ?? null),
              ];
              $tech = $differs($it['tech_name'], $project['tech_name'] ?? null);
              ?>
              <?php if (is_easy()): ?>
                <?= e(implode(' · ', array_merge($sub, array_filter(array_merge($facts, [(int) $it['proofs_generated'] && (int) $it['proof_count'] > 0 ? 'Proofs: ' . (int) $it['proof_count'] : 'Proofs: none yet', (int) $it['is_digital_match'] ? 'Digital match' : null, $tech ? 'Tech: ' . $tech : null]))))) ?>
                <?php if ($it['notes']): ?><div class="muted small"><?= e(mb_strimwidth((string) $it['notes'], 0, 120, '…')) ?></div><?php endif; ?>
              <?php else: ?>
                <?= e(implode(' · ', array_merge($sub, array_filter(array_merge($facts, [$tech ? 'Tech: ' . $tech : null]))))) ?>
              <?php endif; ?>
            </div>
          </td>
          <?php if (is_expert()): ?>
            <td class="nowrap"><?= (int) $it['proofs_generated'] && (int) $it['proof_count'] > 0 ? (int) $it['proof_count'] : '<span class="muted">none</span>' ?></td>
            <td><?= (int) $it['is_digital_match'] ? '<span class="digital">Digital</span>' : '<span class="muted">—</span>' ?></td>
          <?php endif; ?>
          <td class="nowrap" data-label="E#">
            <?php if ($it['internal_display']): ?>
              <?= component('code-pill', ['code' => $it['internal_display'], 'href' => '/codes/e/' . (int) $it['internal_code_id'], 'void' => (int) $it['internal_voided'] === 1]) ?>
            <?php elseif ($canI): ?>
              <?php if (is_easy()): ?><a class="btn btn-secondary btn-sm" href="<?= e(url('/wizard/assign-item-code', ['item_id' => $iid, 'which' => 'internal'])) ?>">Assign E#</a>
              <?php else: ?>
              <div class="assign-pop" x-data="inlineAssign(<?= json_attr(['which' => 'internal', 'internalUrl' => $base . '/assign-internal', 'sellUrl' => $base . '/assign-sell', 'description' => $it['description']]) ?>)" @click.outside="open = false" @chroma:escape.document="open = false">
                <button type="button" class="btn btn-secondary btn-sm" @click="show('internal')">Assign E#</button>
                <div class="pop" x-show="open" x-cloak>
                  <div class="field"><label class="label">Next E number</label><div class="preview" x-text="preview || '…'"></div></div>
                  <div class="field"><label class="label" for="ai-d-<?= $iid ?>">Description</label><input type="text" id="ai-d-<?= $iid ?>" x-model="description" maxlength="500" data-assign-focus @keydown.enter.prevent="submit()"></div>
                  <div class="flex" style="gap:var(--s-2)"><button type="button" class="btn btn-primary btn-sm" @click="submit()" :disabled="busy || !description.trim()">Assign E number</button><button type="button" class="btn btn-ghost btn-sm" @click="open = false">Cancel</button></div>
                </div>
              </div>
              <?php endif; ?>
            <?php else: ?><span class="muted">—</span><?php endif; ?>
          </td>
          <td class="nowrap sell-cell" data-label="Sell number">
            <?php if ($it['sell_display']): ?>
              <?= component('code-pill', ['code' => $it['sell_display'], 'href' => '/codes/s/' . (int) $it['sell_number_id'], 'void' => (int) $it['sell_voided'] === 1, 'copy' => true]) ?>
              <?php if ($iqcHint): ?><span class="iqc">iQC standard</span><?php endif; ?>
            <?php elseif ($canS): ?>
              <?php if (is_easy()): ?><a class="btn btn-secondary btn-sm" href="<?= e(url('/wizard/assign-item-code', ['item_id' => $iid, 'which' => 'sell'])) ?>">Assign sell number</a>
              <?php else: ?>
              <div class="assign-pop" x-data="inlineAssign(<?= json_attr(['which' => 'sell', 'internalUrl' => $base . '/assign-internal', 'sellUrl' => $base . '/assign-sell', 'description' => $it['description'], 'defaultPrefix' => $project['default_prefix_id'], 'prefixSuffixes' => (object) $prefixSuffixes]) ?>)" @click.outside="open = false" @chroma:escape.document="open = false">
                <button type="button" class="btn btn-secondary btn-sm" @click="show('sell')">Assign sell number</button>
                <div class="pop" x-show="open" x-cloak>
                  <div class="field"><label class="label" for="as-p-<?= $iid ?>">Prefix</label>
                    <select id="as-p-<?= $iid ?>" x-model="prefix_id" data-assign-focus @change="suffix_id = ''; refreshPreview()"><option value="">Choose a prefix</option><?php foreach ($prefixes as $p): ?><option value="<?= (int) $p['id'] ?>"><?= e($p['code'] . ' — ' . $p['label']) ?></option><?php endforeach; ?></select></div>
                  <div class="field" x-show="suffixes.length"><label class="label" for="as-s-<?= $iid ?>">Suffix</label>
                    <select id="as-s-<?= $iid ?>" x-model="suffix_id"><option value="">(none)</option><template x-for="s in suffixes" :key="s.id"><option :value="s.id" x-text="s.code + ' — ' + s.label"></option></template></select></div>
                  <div class="field"><label class="label">Next number</label><div class="preview" x-text="preview || (prefix_id ? '…' : 'pick a prefix')"></div></div>
                  <div class="field"><label class="label" for="as-d-<?= $iid ?>">Description</label><input type="text" id="as-d-<?= $iid ?>" x-model="description" maxlength="500" @keydown.enter.prevent="submit()"></div>
                  <div class="flex" style="gap:var(--s-2)"><button type="button" class="btn btn-primary btn-sm" @click="submit()" :disabled="busy || !prefix_id || !description.trim()">Assign sell number</button><button type="button" class="btn btn-ghost btn-sm" @click="open = false">Cancel</button></div>
                </div>
              </div>
              <?php endif; ?>
            <?php else: ?><span class="muted">—</span><?php endif; ?>
          </td>
          <td data-label="Status">
            <?php if ($canItems): ?>
              <div x-data="itemStatus(<?= json_attr(['url' => $base . '/status', 'current' => (string) $it['status_id'], 'description' => $it['description']]) ?>)">
                <select id="st-<?= $iid ?>" class="status-select" aria-label="Status of <?= e($it['description']) ?>" @change="change($event)" :disabled="busy">
                  <?php foreach ($statuses as $s): ?><option value="<?= (int) $s['id'] ?>"<?= (int) $s['id'] === (int) $it['status_id'] ? ' selected' : '' ?>><?= e($s['label']) ?></option><?php endforeach; ?>
                </select>
              </div>
            <?php else: ?>
              <?= component('badge', ['label' => $it['status_label'] ?? '—', 'color' => $it['status_color'] ?? 'gray', 'dot' => true]) ?>
            <?php endif; ?>
          </td>
          <?php if (is_expert()): ?>
            <td class="truncate" style="max-width:160px" title="<?= e($it['notes'] ?? '') ?>"><?= e($it['notes'] ?? '') ?: '<span class="muted">—</span>' ?></td>
          <?php endif; ?>
          <td class="right actions-cell" data-label="Actions">
            <div class="row-actions">
              <?php if ($canItems): ?>
                <a class="btn btn-ghost btn-sm" href="<?= e($base) ?>/edit">Edit</a>
                <a class="btn btn-ghost btn-sm" href="<?= e($base) ?>/move" title="Move to another project">Move</a>
                <?php if ($it['internal_code_id'] === null && $it['sell_number_id'] === null): ?>
                  <?= component('confirm-form', ['action' => $base . '/remove', 'label' => 'Remove', 'size' => 'sm', 'ghost' => is_expert(), 'title' => 'Remove "' . $it['description'] . '"?', 'body' => 'It has no codes, so it can be removed. Its notes go with it. If it is simply not needed any more, set its status to "Not needed" instead.', 'confirm_label' => 'Remove it']) ?>
                <?php endif; ?>
              <?php endif; ?>
            </div>
          </td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
  </div>
  <?php if ($canItems && is_expert()): ?>
    <?php if ($full): ?>
      <div class="card-body muted small" style="border-top:1px solid var(--border)">This project holds the maximum of <?= (int) $maxItems ?> <?= e($plural) ?>. Start another project for the rest.</div>
    <?php else: ?>
    <form id="new-item-form" method="post" action="/projects/<?= $pid ?>/items" class="quick-add" x-data="Object.assign({ kind: <?= json_attr($defaultKind) ?> }, asyncForm({ onSuccess: function () { location.reload(); } }))" @submit.prevent="submit($event)">
      <?= csrf_field() ?>
      <span class="quick-add-label">Add <?= e($singular) ?></span>
      <select name="kind" x-model="kind" aria-label="Kind"><?php foreach ($kinds as $k => $l): ?><option value="<?= e($k) ?>"><?= e($l) ?></option><?php endforeach; ?></select>
      <input type="text" name="description" maxlength="500" class="grow" :placeholder="kind === 'existing_product' ? 'Description (fills in from the code)' : (kind === 'color' ? 'Description, e.g. Pantone 300 C' : (kind === 'trial' ? 'Description, e.g. Trial 3: 2% more wax' : 'Description, e.g. Sample from the press run'))" aria-label="Description" :aria-invalid="!!errors.description">
      <template x-if="kind === 'existing_product'">
        <div class="lookup" style="flex:1 1 220px" x-data="lookup({ name: 'existing_ref', url: '/api/codes/search?q=', initialValue: '', initialLabel: '', minChars: 1 })" @click.outside="open = false">
          <input type="hidden" name="existing_ref" :value="value">
          <input type="text" x-model="q" @input="onInput()" @keydown.down.prevent="move(1)" @keydown.up.prevent="move(-1)" @keydown.enter="enter($event)" placeholder="Existing E# or sell number" autocomplete="off" aria-label="Existing code">
          <div class="lookup-list" x-show="open" x-cloak><template x-for="(item, k) in results" :key="item.id"><div class="lookup-item" :class="{ active: k === active }" @mousedown.prevent="choose(item)"><span x-text="item.label"></span> <span class="sub" x-text="item.sub"></span></div></template><div class="lookup-empty" x-show="!loading && results.length === 0">No code matches yet.</div></div>
        </div>
      </template>
      <input type="text" name="pantone" maxlength="60" placeholder="Pantone" style="width:110px" aria-label="Pantone">
      <input type="text" name="hex_color" maxlength="7" placeholder="#RRGGBB" style="width:100px" aria-label="Hex color">
      <select name="ink_series_id" aria-label="Ink series" style="width:auto"><option value="">Ink: project default</option><?php foreach ($inkSeries as $r): ?><option value="<?= (int) $r['id'] ?>"<?= (int) $r['id'] === (int) $project['default_ink_series_id'] ? ' selected' : '' ?>><?= e($r['name']) ?></option><?php endforeach; ?></select>
      <input type="text" name="substrate" value="<?= e($project['default_substrate_text'] ?? '') ?>" list="substrate-list" placeholder="Substrate" style="width:130px" aria-label="Substrate">
      <select name="coating_id" aria-label="Coating" style="width:auto"><option value="">Coating: default</option><?php foreach ($coatings as $r): ?><option value="<?= (int) $r['id'] ?>"<?= (int) $r['id'] === (int) $project['default_coating_id'] ? ' selected' : '' ?>><?= e($r['name']) ?></option><?php endforeach; ?></select>
      <input type="hidden" name="assigned_user_id" value="<?= (int) ($project['assigned_user_id'] ?? 0) ?: '' ?>">
      <button type="submit" class="btn btn-primary" :disabled="busy">Add</button>
      <span class="error-text" x-show="error" x-cloak x-text="error"></span>
    </form>
    <?php endif; ?>
  <?php endif; ?>
  <?php if ($items): ?>
  <div class="card-foot muted small" style="padding:var(--s-2) var(--s-4);border-top:1px solid var(--border)">
    <?= $canItems ? 'Change a status from the dropdown; the project completes itself when every ' . e($singular) . ' is done or not needed. ' : '' ?>
    <?= $iqcHint ? 'The sell number is the name of the iQC standard.' : '' ?>
  </div>
  <?php endif; ?>
  <?php endif; ?>
</section>
