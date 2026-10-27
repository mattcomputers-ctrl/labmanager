<?php /** Edit one item (both modes). Posts to POST /projects/{id}/items/{iid} → Items::update(). */
$view->start('head'); ?><link rel="stylesheet" href="/assets/css/projects.css"><?php $view->end();
$pid = (int) $project['id']; $iid = (int) $item['id']; $errors = form_errors(); $singular = mb_strtolower((string) $project['item_label_singular']);
?>
<?= component('page-header', ['title' => 'Edit ' . $singular . ' ' . $item['sequence'], 'lede' => $item['description'], 'breadcrumbs' => [['Projects', '/projects'], ['Project ' . $project['project_number'], '/projects/' . $pid . '#items'], ['Edit ' . $singular, null]]]) ?>
<form method="post" action="/projects/<?= $pid ?>/items/<?= $iid ?>" class="card page-narrow" novalidate x-data="{ kind: <?= json_attr(old('kind', $item['kind'])) ?> }">
  <div class="card-body">
    <?= csrf_field() ?>
    <?php if (isset($errors['_'])): ?><?= component('callout', ['type' => 'danger', 'body' => $errors['_']]) ?><div class="mb-4"></div><?php endif; ?>
    <div class="form-grid">
      <?= component('form/field', ['name' => 'kind', 'label' => 'Kind', 'type' => 'select', 'value' => old('kind', $item['kind']), 'options' => array_map(fn($k, $l) => [$k, $l], array_keys($kinds), $kinds), 'attrs' => ['x-model' => 'kind']]) ?>
      <?= component('form/field', ['name' => 'description', 'label' => 'Description', 'required' => true, 'value' => old('description', $item['description']), 'attrs' => ['maxlength' => 500], 'autofocus' => true, 'size' => is_easy() ? 'lg' : null]) ?>
      <div class="field span-2" x-show="kind === 'existing_product'" x-cloak>
        <label class="label" for="f-existing_ref">Existing product</label>
        <?= component('form/lookup', ['name' => 'existing_ref', 'id' => 'f-existing_ref', 'url' => '/api/codes/search?q=', 'value' => '', 'label_value' => implode(' / ', array_filter([$item['internal_display'], $item['sell_display']])), 'placeholder' => 'E number or sell number']) ?>
        <p class="help">Pick a code to link this item to a product that already exists. Codes already linked stay unless you pick another.</p>
        <?php if (isset($errors['existing_ref'])): ?><p class="error-text" role="alert"><?= e($errors['existing_ref']) ?></p><?php endif; ?>
      </div>
      <?= component('form/field', ['name' => 'pantone', 'label' => 'Pantone or reference', 'value' => old('pantone', $item['pantone']), 'optional_text' => true, 'attrs' => ['maxlength' => 60]]) ?>
      <?= component('form/field', ['name' => 'hex_color', 'label' => 'Color (hex)', 'value' => old('hex_color', $item['hex_color']), 'optional_text' => true, 'placeholder' => '#1F6FEB', 'attrs' => ['maxlength' => 7], 'help' => 'Shown as a color chip next to the name.']) ?>
      <?= component('form/field', ['name' => 'ink_series_id', 'label' => 'Ink series', 'type' => 'select', 'placeholder' => 'Not set', 'value' => old('ink_series_id', $item['ink_series_id']), 'options' => array_map(fn($r) => [(int) $r['id'], $r['name']], $inkSeries)]) ?>
      <?= component('form/field', ['name' => 'substrate', 'label' => 'Substrate', 'value' => old('substrate', $item['substrate_text']), 'optional_text' => true, 'attrs' => ['list' => 'substrate-list', 'maxlength' => 200]]) ?>
      <?= component('form/field', ['name' => 'coating_id', 'label' => 'Coating', 'type' => 'select', 'placeholder' => 'Not set', 'value' => old('coating_id', $item['coating_id']), 'options' => array_map(fn($r) => [(int) $r['id'], $r['name']], $coatings)]) ?>
      <?= component('form/field', ['name' => 'proof_count', 'label' => 'Proofs made', 'type' => 'number', 'min' => 0, 'max' => 999, 'value' => old('proof_count', $item['proof_count']), 'help' => '0 means no proofs yet.']) ?>
      <?= component('form/field', ['name' => 'is_digital_match', 'label' => 'Digital match', 'type' => 'checkbox', 'value' => old('is_digital_match', $item['is_digital_match']), 'help' => 'The formula card says "Digital Match" and the iQC Delta E test is 0–1.']) ?>
      <?= component('form/field', ['name' => 'status_id', 'label' => 'Status', 'type' => 'select', 'value' => old('status_id', $item['status_id']), 'options' => array_map(fn($s) => [(int) $s['id'], $s['label']], $statuses)]) ?>
      <?= component('form/field', ['name' => 'assigned_user_id', 'label' => 'Tech', 'type' => 'select', 'placeholder' => 'Nobody', 'value' => old('assigned_user_id', $item['assigned_user_id']), 'options' => array_map(fn($u) => [(int) $u['id'], $u['display_name'] ?: $u['username']], $users)]) ?>
      <?= component('form/field', ['name' => 'date_started', 'label' => 'Started', 'type' => 'date', 'value' => old('date_started', $item['date_started']), 'optional_text' => true]) ?>
      <?= component('form/field', ['name' => 'date_completed', 'label' => 'Finished', 'type' => 'date', 'value' => old('date_completed', $item['date_completed']), 'optional_text' => true]) ?>
      <?= component('form/field', ['name' => 'notes', 'label' => 'Notes', 'type' => 'textarea', 'rows' => 4, 'value' => old('notes', $item['notes']), 'class' => 'span-all']) ?>
    </div>
    <datalist id="substrate-list"><?php foreach ($substrates as $s): ?><option value="<?= e($s['name']) ?>"><?php endforeach; ?></datalist>
    <?php if ($item['internal_display'] || $item['sell_display']): ?>
      <p class="help mt-4">Codes on this <?= e($singular) ?>: <?= e(implode(' and ', array_filter([$item['internal_display'], $item['sell_display']]))) ?>. Codes are changed from their own pages, never here.</p>
    <?php endif; ?>
  </div>
  <div class="card-foot form-actions" style="padding:var(--s-3) var(--s-4);border-top:1px solid var(--border);display:flex;gap:var(--s-3)">
    <button type="submit" class="btn btn-primary btn-lg">Save</button>
    <a class="btn btn-ghost btn-lg" href="/projects/<?= $pid ?>#items">Cancel</a>
  </div>
</form>
