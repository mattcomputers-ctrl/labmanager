<?php /** Definition list. Props: rows (list of [label, value, html?]) */ ?>
<dl class="kv">
<?php foreach ($rows as $row): [$l, $v] = $row; $h = $row[2] ?? false; if ($v === null || $v === '') { continue; } ?>
  <dt><?= e($l) ?></dt><dd><?= $h ? $v : e($v) ?></dd>
<?php endforeach; ?>
</dl>
