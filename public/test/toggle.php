<?php include __DIR__ . '/_start.php'; ?>

	<h1>Total CMS Toggle Form Demo</h1>

	{% import "totalform.twig" as form %}

	{{ form.checkboxForm('mycheck') }}

	{{ form.toggleForm('mytoggle') }}


	<button class="cms-save">Save</button>

<?php include __DIR__ . '/_end.php'; ?>
