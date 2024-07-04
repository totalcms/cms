<?php include __DIR__ . '/_start.php'; ?>

<h1>Total CMS Number Form Demo</h1>

{{ cms.form.number('mynumber') }}

{% set numberform = cms.form.builder('number', {
	id     : 'customnumber',
	hideID : true,
}) %}

{{ numberform.addField('number', { min:1, max:10 }) }}

{{ numberform.build() }}


<?php include __DIR__ . '/_end.php'; ?>