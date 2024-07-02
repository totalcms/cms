<?php include __DIR__ . '/_start.php'; ?>

<h1>Total CMS Form Demo</h1>

{% set form  = cms.objectFormBuilder({
	collection: 'feed',
	id: '2c715fc0-8652-397b-b4a1-0f006a966e9d',
}) %}

{{ form.addField({
	field: 'text',
	name: 'title',
}) }}
{{ form.addField({
	field: 'text',
	name: 'content',
}) }}

{{ form.build() | raw }}

<?php include __DIR__ . '/_end.php'; ?>