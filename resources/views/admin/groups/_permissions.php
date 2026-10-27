<?php
/**
 * Permission tick boxes grouped by area. Props: areas (GroupAdmin::catalogueByArea()), checked (list of keys),
 * disabled (bool), name (input name prefix, default "permissions")
 */
$checked = array_flip((array) ($checked ?? []));
$disabled = !empty($disabled);
$name = $name ?? 'permissions';
?>
<div class="perm-areas">
  <?php foreach ($areas as $area => $perms): ?>
    <fieldset class="perm-area">
      <legend><?= e($area) ?></legend>
      <?php foreach ($perms as $key => $p): $id = 'perm-' . preg_replace('/[^a-z0-9]+/i', '-', $key); ?>
        <label class="check perm" for="<?= e($id) ?>">
          <input type="hidden" name="<?= e($name) ?>[<?= e($key) ?>]" value="0">
          <input type="checkbox" id="<?= e($id) ?>" name="<?= e($name) ?>[<?= e($key) ?>]" value="1"<?= isset($checked[$key]) ? ' checked' : '' ?><?= $disabled ? ' disabled' : '' ?>>
          <span class="check-text"><span><?= e($p['label']) ?></span><span class="help"><?= e($p['help']) ?></span></span>
        </label>
      <?php endforeach; ?>
    </fieldset>
  <?php endforeach; ?>
</div>
