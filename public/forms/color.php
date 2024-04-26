<?php include __DIR__ . '/_start.php'; ?>

	<h1>Total CMS Color Form Demo</h1>

	{% import "totalform.twig" as form %}

	{{ form.colorForm('mycolor') }}

	<button class="cms-save">Save</button>

<?php include __DIR__ . '/_end.php'; ?>
