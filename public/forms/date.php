<?php include __DIR__ . '/_start.php'; ?>

	<h1>Total CMS Date Form Demo</h1>

	{% import "totalform.twig" as form %}

	{{ form.dateForm('mydate') }}

	{{ form.datetimeForm('mydatetime') }}

	<button class="cms-save">Save</button>

<?php include __DIR__ . '/_end.php'; ?>
