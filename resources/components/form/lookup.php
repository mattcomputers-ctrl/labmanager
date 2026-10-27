<?php
/** Typeahead picker. Props: name, id, url (returns {results:[{id,label,sub}]}), value, label_value, placeholder, allow_new, required, size, autofocus */
$id = $id ?? 'f-' . $name;
$cfg = ['name' => $name, 'url' => $url, 'initialValue' => (string) ($value ?? ''), 'initialLabel' => (string) ($label_value ?? ''), 'allowNew' => !empty($allow_new), 'minChars' => 1];
?>
<div class="lookup" x-data="lookup(<?= json_attr($cfg) ?>)" @click.outside="open = false">
  <input type="hidden" name="<?= e($name) ?>" :value="value">
  <input type="hidden" name="<?= e($name) ?>_label" :value="value ? q : ''">
  <?php if (!empty($allow_new)): ?><input type="hidden" name="<?= e($name) ?>_text" :value="value ? '' : q"><?php endif; ?>
  <div class="input-row">
    <input type="text" id="<?= e($id) ?>" x-model="q" @input="onInput()" @focus="if (q && !value) search()" @keydown.down.prevent="move(1)" @keydown.up.prevent="move(-1)" @keydown.enter="enter($event)" @keydown.escape="open=false"
      placeholder="<?= e($placeholder ?? 'Start typing to search…') ?>" autocomplete="off" role="combobox" :aria-expanded="open" aria-autocomplete="list" class="input<?= ($size ?? '') === 'lg' ? ' input-lg' : '' ?>"<?= !empty($required) ? ' required' : '' ?><?= !empty($autofocus) ? ' data-autofocus' : '' ?>>
    <button type="button" class="btn btn-secondary btn-icon" x-show="q" x-cloak @click="clear(); $el.previousElementSibling.focus()" aria-label="Clear">&times;</button>
  </div>
  <div class="lookup-list" x-show="open" x-cloak role="listbox">
    <template x-for="(item, i) in results" :key="item.id">
      <div class="lookup-item" :class="{ active: i === active }" role="option" @mousedown.prevent="choose(item)" @mouseenter="active = i">
        <span x-text="item.label"></span>
        <span class="sub" x-show="item.sub" x-text="item.sub"></span>
      </div>
    </template>
    <div class="lookup-empty" x-show="!loading && results.length === 0">
      <?php if (!empty($allow_new)): ?>No match. Keep typing to use "<span x-text="q"></span>" as a new entry.<?php else: ?>Nothing matches yet. Try fewer letters.<?php endif; ?>
    </div>
  </div>
</div>
