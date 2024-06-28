<?php include __DIR__ . '/_start.php'; ?>

	<h1>Total CMS Blog Form Demo</h1>

	{% set form  = cms.objectFormBuilder({method: "test"}) %}
	{% set formB = cms.objectFormBuilder({method: "put"}) %}

	{{ form.addField() }}
	{{ form.build() }}

	{{ formB.addField() }}
	{{ formB.addField() }}
	{{ formB.addField() }}
	{{ formB.addField() }}
	{{ formB.addField() }}
	{{ formB.build() }}

<?php include __DIR__ . '/_end.php'; ?>
