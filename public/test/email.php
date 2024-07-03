<?php include __DIR__ . '/_start.php'; ?>

<h1>Total CMS Email Form Demo</h1>

{{ cms.form.email("myemail", {
	save   : "Save",
}) }}

<?php include __DIR__ . '/_end.php'; ?>