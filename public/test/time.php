<?php include __DIR__ . '/_start.php'; ?>

<h1>Total CMS Time Form Demo</h1>

{% set numberform = cms.form.builder('text', {
	id     : 'mytime',
	hideID : true,
}) %}

{{ numberform.addField('text', {}, { field:'time' }) }}

{{ numberform.build() }}


<?php include __DIR__ . '/_end.php'; ?>