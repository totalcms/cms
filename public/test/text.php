<?php include __DIR__ . '/_start.php'; ?>

<h1>Total CMS Text Form Demo</h1>

{{ cms.form.text("mytext") | raw }}

{{ cms.form.textarea("mytextarea", {
	save : "Save Me",
}) | raw }}

{{ cms.form.save() | raw }}

<?php include __DIR__ . '/_end.php'; ?>