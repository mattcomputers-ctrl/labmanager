<?php /** Props: text (one paragraph) */ ?>
<span x-data="{ open: false }" style="position:relative;display:inline-block" @click.outside="open=false" @chroma:escape.document="open=false">
  <button type="button" class="help-tip" @click="open = !open" aria-label="What is this?" :aria-expanded="open">?</button>
  <span class="help-pop" x-show="open" x-cloak x-transition.opacity role="tooltip"><?= e($text) ?></span>
</span>
