<?php /** Props: label, color (blue|green|red|amber|purple|gray or a status code), dot */ ?>
<span class="badge badge-<?= e($color ?? 'gray') ?><?= !empty($dot) ? ' badge-dot' : '' ?>"><?= e($label) ?></span>
