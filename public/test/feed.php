<?php include __DIR__ . '/_start.php'; ?>

	<h1>Total CMS Feed Form Demo</h1>

	{% import "totalform.twig" as form %}

	{{ form.feedForm('feed', { class: "help-on-hover help-label", save: true, delete:true }) }}

<?php include __DIR__ . '/_end.php'; ?>
