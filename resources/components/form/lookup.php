<?php
/**
 * Typeahead picker. Props: name, id, url (returns {results:[{id,label,sub}]}), value, label_value, placeholder, allow_new, required, size, autofocus,
 * new_noun (what a typed name becomes when allow_new: "customer"), new_hint (when that happens: "when you continue" / "when you press Enter").
 */
$id = $id ?? 'f-' . $name;
$newNoun = $new_noun ?? 'customer';
$newHint = $new_hint ?? 'when you continue';
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
  <div class="lookup-list" x-show="open && results.length" x-cloak role="listbox">
    <template x-for="(item, i) in results" :key="item.id">
      <div class="lookup-item" :class="{ active: i === active }" role="option" @mousedown.prevent="choose(item)" @mouseenter="active = i">
        <span x-text="item.label"></span>
        <span class="sub" x-show="item.sub" x-text="item.sub"></span>
      </div>
    </template>
    <?php if (!empty($allow_new)): ?>
    <div class="lookup-item lookup-item-new" :class="{ active: active === newIndex }" role="option" @mousedown.prevent="chooseNew()" @mouseenter="active = newIndex">
      <span>Add "<span x-text="q"></span>" as a new <?= e($newNoun) ?></span>
      <span class="sub">Not one of the matches above</span>
    </div>
    <?php endif; ?>
  </div>
  <?php /* Static, so it never covers the buttons below the field. */ ?>
  <div class="lookup-hint small" x-show="noMatch" x-cloak aria-live="polite">
    <?php if (!empty($allow_new)): ?>Not in the list yet. "<strong x-text="q"></strong>" will be added as a new <?= e($newNoun) ?> <?= e($newHint) ?>.<?php else: ?>Nothing matches "<span x-text="q"></span>". Try fewer letters.<?php endif; ?>
  </div>
</div>
