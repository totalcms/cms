<?php include __DIR__ . '/_start.php'; ?>

	<h1>Total CMS URL Form Demo</h1>

	{% import "totalform.twig" as form %}

	{{ form.urlForm('myurl') }}

	<button class="cms-save">Save</button>

<?php include __DIR__ . '/_end.php'; ?>
