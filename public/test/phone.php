<?php include __DIR__ . '/_start.php'; ?>

<h1>Total CMS Phone Form Demo</h1>

{% set form = cms.form.builder('text', {
	id     : 'phoneform',
	hideID : true,
}) %}

{{ form.addField('text', { field:'phone' }) }}

{{ form.build() }}

<?php include __DIR__ . '/_end.php'; ?>