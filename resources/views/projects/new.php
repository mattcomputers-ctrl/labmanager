<?php
/**
 * Expert mode: new project on one page. Every field visible, items grid with one empty line,
 * + adds a line up to the cap, × removes unsaved lines, pasting a list fills one line per row.
 * Posts to POST /projects → Projects::create() (same as the Easy wizard).
 */
use Chroma\Domain\ProjectTypes\ProjectTypes;
$view->start('head'); ?><link rel="stylesheet" href="/assets/css/projects.css"><script src="/assets/js/projects.js"></script><?php $view->end();
$errors = form_errors();
$me = $auth->user();
$oldItems = old('items', $items);
$gridRows = [];
foreach ((array) $oldItems as $it) {
    if (is_array($it)) {
        $gridRows[] = ['description' => (string) ($it['description'] ?? ''), 'pantone' => (string) ($it['pantone'] ?? ''), 'hex_color' => (string) ($it['hex_color'] ?? ''), 'kind' => (string) ($it['kind'] ?? ''), 'existing_ref' => (string) ($it['existing_ref'] ?? ''), 'existing_label' => (string) ($it['existing_label'] ?? ''), 'proof_count' => (string) ($it['proof_count'] ?? ''), 'is_digital_match' => !empty($it['is_digital_match']), 'notes' => (string) ($it['notes'] ?? '')];
    }
}
$typeNow = old('type', $typeCode);
$typeMeta = [];
foreach ($types as $code => $t) {
    $typeMeta[$code] = ['has_customer' => (int) $t['has_customer'] === 1, 'customer_required' => (int) $t['customer_required'] === 1, 'item_label' => $t['item_label'], 'item_singular' => $t['item_label_singular'], 'kind' => $t['default_item_kind'] ?? 'color', 'has_team' => (int) $t['has_team'] === 1];
}
$itemErrors = [];
foreach ($errors as $k => $v) { if (str_starts_with($k, 'items.')) { $itemErrors[] = $v; } }
?>
<?= component('page-header', ['title' => 'New project', 'breadcrumbs' => [['Projects', '/projects'], ['New project', null]], 'lede' => 'Everything on one page. Only the type, title and customer are required; add the ' . 'colors or items below and press Create.']) ?>

<form method="post" action="/projects" class="stack" novalidate data-mode-carry="/wizard/new-project"
      x-data="itemsGrid(<?= json_attr(['rows' => $gridRows, 'cap' => $maxItems, 'defaultKind' => $typeMeta[$typeNow]['kind'] ?? 'color', 'type' => $typeNow, 'meta' => $typeMeta]) ?>)"
      x-init="$watch('type', t => setKind(meta[t].kind))"
      @keydown.ctrl.enter.prevent="$el.requestSubmit()" @keydown.meta.enter.prevent="$el.requestSubmit()">
  <?= csrf_field() ?>
  <?php if (isset($errors['_'])): ?><?= component('callout', ['type' => 'danger', 'body' => $errors['_']]) ?><?php endif; ?>

  <div class="card"><div class="card-body">
    <div class="form-grid">
      <div class="field">
        <label class="label" for="f-type">Project type <span class="req">*</span></label>
        <select id="f-type" name="type" x-model="type" data-autofocus>
          <?php foreach ($types as $code => $t): ?><option value="<?= e($code) ?>"><?= e($t['label']) ?> — <?= e($t['description']) ?></option><?php endforeach; ?>
        </select>
        <?php if (isset($errors['type'])): ?><p class="error-text" role="alert"><?= e($errors['type']) ?></p><?php endif; ?>
      </div>
      <div class="field" x-show="meta[type].has_customer">
        <label class="label" for="f-customer_id">Customer <span class="req" x-show="meta[type].customer_required">*</span></label>
        <?= component('form/lookup', ['name' => 'customer_id', 'id' => 'f-customer_id', 'url' => '/api/customers?q=', 'value' => old('customer_id', $defaults['customer_id'] ?? ''), 'label_value' => old('customer_id_text', $customerLabel ?: ($defaults['customer_text'] ?? '')), 'allow_new' => true, 'placeholder' => 'Start typing the customer name']) ?>
        <p class="help">Pick from the list, or keep typing a new name to add it.</p>
        <?php if (isset($errors['customer_id'])): ?><p class="error-text" role="alert"><?= e($errors['customer_id']) ?></p><?php endif; ?>
      </div>
      <?= component('form/field', ['name' => 'title', 'label' => 'Title', 'required' => true, 'value' => old('title', $defaults['title'] ?? ''), 'placeholder' => 'e.g. Spring carton colors', 'attrs' => ['maxlength' => 200], 'class' => 'span-2']) ?>

      <?php foreach ($types as $code => $t): ?>
        <?php foreach ($fieldsByType[$code] as $spec): $n = $spec['name']; $dis = "type !== '" . $code . "'"; ?>
          <template x-if="type === '<?= e($code) ?>'">
            <?php
            $common = ['name' => $n, 'label' => $spec['label'], 'help' => $spec['help'], 'tip' => $spec['tip'] ?? null, 'error' => $errors[$n] ?? null, 'id' => 'f-' . $code . '-' . $n];
            switch ($spec['type']) {
                case 'textarea':
                    echo component('form/field', $common + ['type' => 'textarea', 'rows' => 3, 'value' => old($n, $defaults[$n] ?? ''), 'class' => 'span-2']);
                    break;
                case 'date':
                    echo component('form/field', $common + ['type' => 'date', 'value' => old($n, $defaults[$n] ?? ($n === 'date_submitted' ? today() : ''))]);
                    break;
                case 'user':
                    $uid = old($n, $defaults[$n] ?? ($me['id'] ?? ''));
                    $ulabel = '';
                    foreach ($users as $u) { if ((int) $u['id'] === (int) $uid) { $ulabel = $u['display_name'] ?: $u['username']; } }
                    echo '<div class="field"><label class="label" for="' . e($common['id']) . '">' . e($spec['label']) . '</label>' . component('form/lookup', ['name' => $n, 'id' => $common['id'], 'url' => '/api/users?q=', 'value' => $uid, 'label_value' => $ulabel, 'placeholder' => 'Type a name']) . '<p class="help">' . e($spec['help']) . '</p>' . (isset($errors[$n]) ? '<p class="error-text" role="alert">' . e($errors[$n]) . '</p>' : '') . '</div>';
                    break;
                case 'stage':
                    echo component('form/field', $common + ['type' => 'select', 'placeholder' => 'Not set yet', 'value' => old($n, $defaults[$n] ?? ''), 'options' => array_map(fn($s) => [(int) $s['id'], $s['label']], $t['stages'])]);
                    break;
                case 'checkbox':
                    echo component('form/field', $common + ['type' => 'checkbox', 'value' => old($n, $defaults[$n] ?? 0)]);
                    break;
                case 'ink_series':
                    echo component('form/field', $common + ['type' => 'select', 'placeholder' => 'Not set', 'value' => old($n, $defaults[$n] ?? ''), 'options' => array_map(fn($r) => [(int) $r['id'], $r['name']], $inkSeries)]);
                    break;
                case 'substrate':
                    echo component('form/field', $common + ['name' => 'default_substrate', 'type' => 'text', 'value' => old('default_substrate', $defaults['default_substrate'] ?? ''), 'placeholder' => 'e.g. SBS board, PE film', 'attrs' => ['list' => 'substrate-list']]);
                    break;
                case 'coating':
                    echo component('form/field', $common + ['type' => 'select', 'placeholder' => 'Not set', 'value' => old($n, $defaults[$n] ?? ''), 'options' => array_map(fn($r) => [(int) $r['id'], $r['name']], $coatings)]);
                    break;
            }
            ?>
          </template>
        <?php endforeach; ?>
        <?php if ((int) $t['has_team'] === 1): ?>
          <template x-if="type === '<?= e($code) ?>'">
            <div class="field span-2">
              <label class="label" for="f-team-<?= e($code) ?>">Team members <span class="optional">(optional)</span></label>
              <select id="f-team-<?= e($code) ?>" name="team[]" multiple size="4">
                <?php foreach ($users as $u): ?><option value="<?= (int) $u['id'] ?>"<?= in_array((string) $u['id'], (array) old('team', []), true) ? ' selected' : '' ?>><?= e($u['display_name'] ?: $u['username']) ?></option><?php endforeach; ?>
              </select>
              <p class="help">Hold Ctrl (or Cmd) to pick several. Team members can open the project even when it is confidential.</p>
            </div>
          </template>
        <?php endif; ?>
      <?php endforeach; ?>
    </div>
    <datalist id="substrate-list"><?php foreach ($substrates as $s): ?><option value="<?= e($s['name']) ?>"><?php endforeach; ?></datalist>
  </div></div>

  <div class="card">
    <div class="card-head">
      <h2><span x-text="meta[type].item_label"></span> <span class="muted small" x-text="rows.length + ' of ' + cap"></span></h2>
      <span class="muted small">Enter moves to the next line and adds one after the last. Paste a list into a description to fill several lines. Ctrl+Enter creates the project.</span>
    </div>
    <?php if ($itemErrors || isset($errors['items'])): ?><div class="card-body" style="padding-bottom:0"><?= component('callout', ['type' => 'danger', 'body' => implode("\n", array_unique(array_merge($itemErrors, isset($errors['items']) ? [$errors['items']] : [])))]) ?></div><?php endif; ?>
    <div class="table-wrap" style="border:0;box-shadow:none;border-radius:0">
      <table class="items-grid">
        <thead><tr><th>#</th><th>Kind</th><th style="min-width:260px">Description</th><th>Existing code</th><th>Pantone / ref</th><th>Hex</th><th>Proofs</th><th>Digital</th><th>Notes</th><th></th></tr></thead>
        <tbody>
          <template x-for="(row, i) in rows" :key="i">
            <tr :data-row="i">
              <td class="n" x-text="i + 1"></td>
              <td><select :name="'items[' + i + '][kind]'" x-model="row.kind" aria-label="Kind"><?php foreach (ProjectTypes::ITEM_KINDS as $k => $l): ?><option value="<?= e($k) ?>"><?= e($l) ?></option><?php endforeach; ?></select></td>
              <td><input type="text" :name="'items[' + i + '][description]'" x-model="row.description" data-first maxlength="500" :placeholder="row.kind === 'existing_product' ? 'Fills in from the code' : (row.kind === 'color' ? 'e.g. Pantone 300 C' : 'e.g. Trial 1: 2% more wax')" @paste="paste($event, i)" @keydown="enterNext($event, i)" aria-label="Description"></td>
              <td>
                <template x-if="row.kind === 'existing_product'">
                  <div class="lookup existing-lookup" x-data="lookup({ name: 'existing', url: '/api/codes/search?q=', initialValue: row.existing_ref, initialLabel: row.existing_label, minChars: 1 })" @click.outside="open = false" @lookup-chosen="pickExisting(i, $event.detail.item)">
                    <input type="hidden" :name="'items[' + i + '][existing_ref]'" :value="value">
                    <input type="hidden" :name="'items[' + i + '][existing_label]'" :value="q">
                    <input type="text" x-model="q" @input="onInput()" @keydown.down.prevent="move(1)" @keydown.up.prevent="move(-1)" @keydown.enter="enter($event); if (!$event.defaultPrevented) enterNext($event, i)" placeholder="E# or sell number" autocomplete="off" aria-label="Existing code">
                    <div class="lookup-list" x-show="open" x-cloak>
                      <template x-for="(item, k) in results" :key="item.id"><div class="lookup-item" :class="{ active: k === active }" @mousedown.prevent="choose(item)"><span x-text="item.label"></span> <span class="sub" x-text="item.sub"></span></div></template>
                      <div class="lookup-empty" x-show="!loading && results.length === 0">No code matches yet.</div>
                    </div>
                  </div>
                </template>
                <span class="muted small" x-show="row.kind !== 'existing_product'">—</span>
              </td>
              <td><input type="text" :name="'items[' + i + '][pantone]'" x-model="row.pantone" maxlength="60" style="width:110px" aria-label="Pantone" @keydown="enterNext($event, i)"></td>
              <td><input type="text" :name="'items[' + i + '][hex_color]'" x-model="row.hex_color" maxlength="7" placeholder="#RRGGBB" style="width:96px" aria-label="Hex color" @keydown="enterNext($event, i)"></td>
              <td><input type="number" :name="'items[' + i + '][proof_count]'" x-model="row.proof_count" min="0" max="999" style="width:70px" aria-label="Proofs" @keydown="enterNext($event, i)"></td>
              <td><input type="hidden" :name="'items[' + i + '][is_digital_match]'" value="0"><input type="checkbox" :name="'items[' + i + '][is_digital_match]'" value="1" x-model="row.is_digital_match" aria-label="Digital match" @keydown="enterNext($event, i)"></td>
              <td><input type="text" :name="'items[' + i + '][notes]'" x-model="row.notes" style="min-width:140px" aria-label="Notes" @keydown="lastCell($event, i)"></td>
              <td class="rm"><button type="button" class="btn btn-ghost btn-icon" @click="remove(i)" aria-label="Remove line" title="Remove this line">&times;</button></td>
            </tr>
          </template>
        </tbody>
      </table>
    </div>
    <div class="card-body grid-foot">
      <button type="button" class="btn btn-secondary" @click="add(true)" :disabled="full">+ Add a line</button>
      <span class="muted small" x-show="full" x-cloak>That is the limit of <span x-text="cap"></span> per project. Start another project for the rest.</span>
    </div>
  </div>

  <div class="form-actions sticky-bottom">
    <button type="submit" class="btn btn-primary btn-lg">Create project</button>
    <a class="btn btn-ghost" href="/projects">Cancel</a>
    <span class="muted small">The project gets the next number. No codes are assigned until you assign them.</span>
  </div>
</form>
