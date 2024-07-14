<?php include __DIR__ . '/_start.php'; ?>

<h1>Total CMS Time Form Demo</h1>

{% set form = cms.form.builder('text', {
	id     : 'mytime',
	hideID : true,
}) %}

{{ form.addField('text', { field:'time' }) }}

{{ form.build() }}


<?php include __DIR__ . '/_end.php'; ?>