<?php include __DIR__ . '/_start.php'; ?>

<h1>Total CMS Form Demo</h1>

{% set form  = cms.objectFormBuilder({collection: 'blog'}) %}

{{ form.addField({
	type: 'text',
	name: 'title',
}) }}
{{ form.addField({
	type: 'text',
	name: 'summary',
}) }}

{{ form.build() | raw }}

<?php include __DIR__ . '/_end.php'; ?>