<?php include __DIR__ . '/_start.php'; ?>

<h1>Total CMS Text Form Demo</h1>

{{ cms.form.text("mytext") }}

{{ cms.form.textarea("mytextarea", {
	save : "Save Me",
}) }}

{{ cms.form.save("Save ALL") }}

<?php include __DIR__ . '/_end.php'; ?>