<?php include __DIR__ . '/_start.php'; ?>

<h1>Total CMS Password Form Demo</h1>

{% set form = cms.form.builder('text', {
	id     : 'passwordform',
	hideID : true,
}) %}

{{ form.addField('text', { field:'password', minlength : 5 }) }}

{{ form.build() }}

<?php include __DIR__ . '/_end.php'; ?>