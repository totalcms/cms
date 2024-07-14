<?php include __DIR__ . '/_start.php'; ?>

<h1>Total CMS Text Form Demo</h1>

{{ cms.form.text('mytext', {
	helpOnHover : true,
	helpOnFocus : true,
}) }}

{{ cms.form.text('mytext', {
	helpOnHover : true,
	helpOnFocus : true,
	helpStyle   : "label",
}) }}

{{ cms.form.text('mytext', {
	helpOnHover : true,
	helpOnFocus : true,
	helpStyle   : "box",
}) }}

{{ cms.form.text('mytext', {
	helpOnHover : true,
	helpOnFocus : true,
	helpStyle   : "tooltip",
}) }}

<?php include __DIR__ . '/_end.php'; ?>