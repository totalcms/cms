<?php include __DIR__ . '/_start.php'; ?>

	<h1>Total CMS Text Form Demo</h1>

	{% import "totalform.twig" as form %}

	{{ form.textForm('mytext', {
		class: "help-on-hover help-on-focus",
	}) }}

	{{ form.textForm('mytext', {
		class: "help-on-hover help-on-focus help-label",
	}) }}

	{{ form.textForm('mytext', {
		class: "help-on-hover help-on-focus help-box",
	}) }}

	{{ form.textForm('mytext', {
		class: "help-on-hover help-on-focus help-tooltip",
	}) }}

	<button class="cms-save">Save</button>

<?php include __DIR__ . '/_end.php'; ?>
