<?php include __DIR__ . '/_start.php'; ?>

<h1>Total CMS Styled Text Form Demo</h1>

{{ cms.form.styledtext('mytext', {}, {
	label: 'Upload Testing',
	settings : {
		toolbarButtons : [
			['bold', 'italic', 'underline', 'html'],
			["insertLink", "insertImage", "insertFile", "insertVideo"],
			['inlineClass', 'clearFormatting'],
		],
		inlineClasses: {
			'fr-class-code'         : 'Code',
			'fr-class-highlighted'  : 'Highlighted',
			'fr-class-transparency' : 'Transparent'
		},
		linkStyles: {
    		button: 'Button',
		},
		imageInsertButtons : ['imageBack', '|', 'imageUpload', 'imageByURL'],
		imageUploadParams  : { w:300 },
	},
}) }}

<?php include __DIR__ . '/_end.php'; ?>