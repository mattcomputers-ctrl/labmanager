<?= component('page-header', ['title' => 'Style guide', 'lede' => 'The building blocks every Chroma screen is made of. Use these components; do not invent new ones.']) ?>
<div class="stack" style="gap: var(--s-8)">
<section class="card"><div class="card-head"><h2>Colors</h2></div><div class="card-body">
  <h4>Accent (chroma blue)</h4>
  <div class="grid grid-4 mb-4">
    <?php foreach ([50, 100, 200, 300, 400, 500, 600, 700, 800, 900] as $s): ?><div class="swatch" style="background: var(--c-primary-<?= $s ?>); color: <?= $s >= 500 ? '#fff' : '#000' ?>">primary-<?= $s ?></div><?php endforeach; ?>
  </div>
  <h4>Neutrals</h4>
  <div class="grid grid-4 mb-4">
    <?php foreach ([50, 100, 200, 300, 400, 500, 600, 700, 800, 900] as $s): ?><div class="swatch" style="background: var(--c-gray-<?= $s ?>); color: <?= $s >= 500 ? '#fff' : '#000' ?>">gray-<?= $s ?></div><?php endforeach; ?>
  </div>
  <h4>Status</h4>
  <div class="grid grid-4">
    <div class="swatch" style="background: var(--success); color:#fff">success</div>
    <div class="swatch" style="background: var(--danger); color:#fff">danger</div>
    <div class="swatch" style="background: var(--warning); color:#fff">warning</div>
    <div class="swatch" style="background: var(--c-purple-600); color:#fff">purple (R&amp;D)</div>
  </div>
</div></section>

<section class="card"><div class="card-head"><h2>Type scale</h2></div><div class="card-body">
  <div class="type-sample" style="font-size:var(--fs-4xl);font-weight:700">Display 38px — Chroma</div>
  <h1>Heading 1 — 30px</h1><h2>Heading 2 — 24px</h2><h3>Heading 3 — 20px</h3><h4>Heading 4 — 18px</h4>
  <p>Body 16px. Many users are not comfortable on computers, so the body text never goes below 16px and lines stay short. <a href="#">Links look like this.</a></p>
  <p class="small">Small 14px — for helper text and table headers only.</p>
  <p><span class="code">E9102</span> <span class="code-pill">Y1715UV</span> <kbd>Enter</kbd> submits, <kbd>Esc</kbd> cancels.</p>
</div></section>

<section class="card"><div class="card-head"><h2>Buttons</h2><span class="muted small">One primary action per screen. Label with a verb.</span></div><div class="card-body">
  <div class="flex flex-wrap mb-4">
    <button class="btn btn-primary">Assign sell number</button>
    <button class="btn btn-secondary">Save changes</button>
    <button class="btn btn-ghost">Cancel</button>
    <button class="btn btn-danger">Void this code</button>
    <button class="btn btn-danger-outline">Retire prefix</button>
    <button class="btn btn-primary" disabled>Disabled</button>
  </div>
  <div class="flex flex-wrap">
    <button class="btn btn-primary btn-lg">Large — wizard Next ›</button>
    <button class="btn btn-secondary btn-sm">Small</button>
    <button class="btn btn-secondary btn-icon" aria-label="Copy"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><rect x="9" y="9" width="13" height="13" rx="2"/><path d="M5 15H4a2 2 0 0 1-2-2V4a2 2 0 0 1 2-2h9a2 2 0 0 1 2 2v1"/></svg></button>
  </div>
</div></section>

<section class="card"><div class="card-head"><h2>Form fields</h2></div><div class="card-body">
  <div class="form-grid">
    <?= component('form/field', ['name' => 'sg_text', 'label' => 'Description', 'required' => true, 'help' => 'Helper text sits under the field.', 'placeholder' => 'e.g. Blister Pantone 663 Purple']) ?>
    <?= component('form/field', ['name' => 'sg_err', 'label' => 'With an error', 'value' => 'Y1715', 'error' => 'That sell number is already used by E7982 — pick a different suffix or search for it.']) ?>
    <?= component('form/field', ['name' => 'sg_select', 'label' => 'Prefix', 'type' => 'select', 'options' => [['Y', 'Y — Yellow'], ['R', 'R — Red']], 'placeholder' => 'Choose a prefix', 'tip' => 'The prefix decides which counter the number comes from.']) ?>
    <?= component('form/field', ['name' => 'sg_date', 'label' => 'Date submitted', 'type' => 'date', 'value' => today()]) ?>
    <?= component('form/field', ['name' => 'sg_num', 'label' => 'Proof count', 'type' => 'number', 'min' => 0, 'value' => 2]) ?>
    <?= component('form/field', ['name' => 'sg_lookup', 'label' => 'Customer', 'type' => 'lookup', 'lookup' => '/api/customers?q=', 'placeholder' => 'Start typing a customer name', 'allow_new' => true]) ?>
    <div class="span-all"><?= component('form/field', ['name' => 'sg_area', 'label' => 'Notes', 'type' => 'textarea', 'optional_text' => true]) ?></div>
    <div class="span-all"><?= component('form/field', ['name' => 'sg_radio', 'label' => 'What kind of project is this?', 'type' => 'radio', 'value' => 'color_match', 'options' => [['color_match', 'Color match', 'Match one or more colors for a customer.'], ['customer_issue', 'Customer issue', 'Diagnose a problem a customer is having.']]]) ?></div>
    <?= component('form/field', ['name' => 'sg_check', 'label' => 'Digital match', 'type' => 'checkbox', 'value' => 1, 'help' => 'The formula card will say "Digital Match".']) ?>
  </div>
</div></section>

<section class="card"><div class="card-head"><h2>Badges, chips and codes</h2></div><div class="card-body">
  <p class="flex flex-wrap">
    <?= component('badge', ['label' => 'Open', 'color' => 'open']) ?> <?= component('badge', ['label' => 'Completed', 'color' => 'completed']) ?> <?= component('badge', ['label' => 'Cancelled', 'color' => 'cancelled']) ?>
    <?= component('badge', ['label' => 'Pending', 'color' => 'pending']) ?> <?= component('badge', ['label' => 'In progress', 'color' => 'in_progress', 'dot' => true]) ?> <?= component('badge', ['label' => 'Matched', 'color' => 'matched']) ?> <?= component('badge', ['label' => 'R&D', 'color' => 'purple']) ?>
  </p>
  <p><?= component('chip', ['hex' => '#f2c037']) ?>Pantone 109 C &nbsp; <?= component('chip', ['hex' => '#2f4f9e']) ?>Pantone 2746 C &nbsp; <?= component('chip', ['hex' => '#6b3fd1']) ?>Pantone 663 Purple</p>
  <p><?= component('code-pill', ['code' => 'E9102', 'href' => '#', 'copy' => true]) ?> <?= component('code-pill', ['code' => 'Y1715UV', 'href' => '#', 'copy' => true]) ?> <?= component('code-pill', ['code' => 'X104044', 'void' => true]) ?> (voided)</p>
  <?= component('code-display', ['code' => 'Y1715UV', 'label' => 'Sell number reserved', 'description' => 'Blister Pantone 663 Purple']) ?>
</div></section>

<section class="card"><div class="card-head"><h2>Callouts and empty states</h2></div><div class="card-body stack">
  <?= component('callout', ['type' => 'info', 'title' => 'What is a sell number?', 'body' => 'The code printed on the can and used on orders. It starts with a prefix like Y for yellow and counts up.']) ?>
  <?= component('callout', ['type' => 'success', 'body' => 'Saved. The project is open and ready for colors.']) ?>
  <?= component('callout', ['type' => 'warning', 'title' => 'Browser will need to reconnect', 'body' => 'After the IP address changes, open Chroma at the new address.']) ?>
  <?= component('callout', ['type' => 'danger', 'title' => 'This cannot be undone', 'body' => 'Voiding keeps the number visible but greyed. The number is never used again.']) ?>
  <div class="card"><?= component('empty-state', ['title' => 'No projects yet', 'text' => 'Projects track color matches, customer issues, evaluations and R&D work. Start the first one.', 'action_href' => '#', 'action_label' => 'Start a project']) ?></div>
</div></section>

<section class="card"><div class="card-head"><h2>Tables</h2><span class="muted small">Zebra rows, sticky header, comfortable height.</span></div><div class="card-body">
  <div class="table-toolbar"><input type="search" placeholder="Search…" class="grow"><div class="filters"><a class="filter-chip on" href="#">Open</a><a class="filter-chip" href="#">Completed</a><a class="filter-chip" href="#">All</a></div><button class="btn btn-secondary btn-sm">Export</button></div>
  <div class="table-wrap"><table class="table">
    <thead><tr><th>#</th><th>Customer</th><th>Description</th><th>Status</th><th class="num">Items</th><th class="actions"></th></tr></thead>
    <tbody>
      <tr class="clickable" data-href="#"><td class="num">101</td><td>Example Packaging</td><td>4-color process set</td><td><?= component('badge', ['label' => 'Open', 'color' => 'open']) ?></td><td class="num">4</td><td class="actions"><a class="btn btn-ghost btn-sm" href="#">Open</a></td></tr>
      <tr class="clickable" data-href="#"><td class="num">102</td><td>Example Labels</td><td>Blister Pantone 663 Purple</td><td><?= component('badge', ['label' => 'Completed', 'color' => 'completed']) ?></td><td class="num">1</td><td class="actions"><a class="btn btn-ghost btn-sm" href="#">Open</a></td></tr>
      <tr class="void-row"><td class="num">103</td><td>Example Print</td><td class="code">B2971</td><td><?= component('badge', ['label' => 'Voided', 'color' => 'void']) ?></td><td class="num">0</td><td class="actions"></td></tr>
    </tbody>
  </table></div>
  <?= component('pagination', ['page' => 2, 'per_page' => 50, 'total' => 8123, 'base' => '/styleguide', 'query' => []]) ?>
</div></section>

<section class="card"><div class="card-head"><h2>Confirmation dialog</h2><span class="muted small">Destructive actions are red, ask for confirmation and explain the consequence.</span></div><div class="card-body">
  <?= component('confirm-form', ['action' => '/styleguide', 'label' => 'Void this code', 'title' => 'Void Y1715UV?', 'body' => 'The number stays in the logs, greyed out, and is never reused. Anyone looking it up will see it was voided and why.', 'confirm_label' => 'Yes, void it', 'reason' => true]) ?>
</div></section>

<section class="card"><div class="card-head"><h2>Wizard shell (Easy mode)</h2></div><div class="card-body">
  <div class="wizard-progress"><span>Step 2 of 4</span><div class="bar"><span style="width:50%"></span></div></div>
  <div class="card wizard-card">
    <h1>Which prefix should this sell number use?</h1>
    <p class="wizard-intro">The prefix tells everyone what kind of product it is, and decides which counter the number comes from.</p>
    <?= component('form/field', ['name' => 'sg_wprefix', 'label' => 'Prefix', 'type' => 'radio', 'options' => [['Y', 'Y — Yellow', 'Next number: Y1715'], ['B', 'B — Blue', 'Next number: B2971']]]) ?>
    <div class="wizard-actions"><button class="btn btn-secondary btn-lg">‹ Back</button><button class="btn btn-primary btn-lg">Next ›</button></div>
  </div>
</div></section>
</div>
