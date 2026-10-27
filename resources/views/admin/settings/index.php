<?php
$view->start('head'); ?><link rel="stylesheet" href="/assets/css/system-admin.css"><?php $view->end();
echo $view->partial('admin/_nav', ['current' => '/admin/system']);
echo component('page-header', ['title' => 'Settings', 'lede' => 'Names, time zone and limits that apply everywhere in Chroma. Changes are logged.']);
echo $view->partial('admin/system/_subnav', ['current' => '/admin/settings']);
$tz = [];
foreach ($timezones as $k => $label) { $tz[] = [$k, $label]; }
?>
<form method="post" action="/admin/settings">
  <?= csrf_field() ?>
  <div class="sys-grid">
    <section class="card"><div class="card-head"><h2>Names</h2></div><div class="card-body">
      <?= component('form/field', ['name' => 'company_name', 'label' => 'Company name', 'value' => old('company_name', $values['company_name']), 'required' => true, 'help' => 'Printed on formula cards and reports.', 'attrs' => ['maxlength' => 120]]) ?>
      <?= component('form/field', ['name' => 'app_name', 'label' => 'App name', 'value' => old('app_name', $values['app_name']), 'help' => 'Shown in the browser tab. Normally "Chroma".', 'attrs' => ['maxlength' => 40]]) ?>
      <?= component('form/field', ['name' => 'rnd_customer_name', 'label' => 'Internal customer for R&D projects', 'value' => old('rnd_customer_name', $values['rnd_customer_name']), 'required' => true, 'help' => 'R&D projects have no real customer; this name is used instead.', 'attrs' => ['maxlength' => 200]]) ?>
    </div></section>
    <section class="card"><div class="card-head"><h2>Time and links</h2></div><div class="card-body">
      <?= component('form/field', ['name' => 'timezone', 'label' => 'Time zone', 'type' => 'select', 'options' => $tz, 'value' => old('timezone', $values['timezone']), 'required' => true, 'help' => 'Dates and times are shown in this zone. Precision Ink is on Central time.']) ?>
      <?= component('form/field', ['name' => 'sds_base_url', 'label' => 'SDS system link', 'type' => 'text', 'value' => old('sds_base_url', $values['sds_base_url']), 'optional_text' => true, 'placeholder' => 'http://sds.precisionink.local/sheet/{E}', 'help' => 'Where an E number links to its safety data sheet. Put {E} where the E number goes (for example E9102); without {E} the number is added at the end. Leave blank for no link.']) ?>
    </div></section>
    <section class="card"><div class="card-head"><h2>Limits and lab rules</h2></div><div class="card-body">
      <?= component('form/field', ['name' => 'max_items_per_project', 'label' => 'Most colors or items in one project', 'type' => 'number', 'min' => 1, 'max' => 500, 'value' => old('max_items_per_project', $values['max_items_per_project']), 'required' => true, 'help' => 'The lab asked for 50. The + button on a project stops at this number.', 'attrs' => ['style' => 'max-width:160px']]) ?>
      <?= component('form/field', ['name' => 'max_upload_mb', 'label' => 'Largest attachment (MB)', 'type' => 'number', 'min' => 1, 'max' => 2048, 'value' => old('max_upload_mb', $values['max_upload_mb']), 'required' => true, 'help' => 'Drawdown photos and PDFs bigger than this are refused.', 'attrs' => ['style' => 'max-width:160px']]) ?>
      <?= component('form/field', ['name' => 'sell_number_is_iqc_standard', 'label' => 'The sell number is the name of the iQC standard', 'type' => 'checkbox', 'value' => old('sell_number_is_iqc_standard', $values['sell_number_is_iqc_standard']), 'help' => 'When on, the sell number is shown large on each color row so the lab can type it into iQC.']) ?>
    </div></section>
  </div>
  <div class="form-actions"><button type="submit" class="btn btn-primary btn-lg">Save settings</button><a class="btn btn-secondary" href="/admin">Cancel</a></div>
</form>
