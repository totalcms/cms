<?php include __DIR__ . '/_start.php'; ?>

<h1>Total CMS Form Demo</h1>

{% set form  = cms.objectFormBuilder({
	collection: 'email',
}) %}

{{ form.addField({
	field : 'email',
	name  : 'email',
}) }}

{{ form.build() | raw }}

<?php include __DIR__ . '/_end.php'; ?>