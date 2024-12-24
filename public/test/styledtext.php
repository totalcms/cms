<?php include __DIR__ . '/_start.php'; ?>

<h1>Total CMS Styled Text Form Demo</h1>

{{ cms.form.styledtext('mytext', {}, {
	label: 'Upload Testing',
	settings : {
		fileUpload     : true,
		imageUpload    : true,
		videoUpload    : true,
		toolbarButtons : [
			['bold', 'italic', 'underline'],
			["insertImage", "insertFile", "insertVideo"],
		],
		imageInsertButtons : ['imageBack', '|', 'imageUpload', 'imageByURL'],
	},
}) }}

<?php include __DIR__ . '/_end.php'; ?>