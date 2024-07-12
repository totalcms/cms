<?php include __DIR__ . '/_start.php'; ?>

<h1>Total CMS Text Form Demo</h1>

{{ cms.form.text("mytext") }}

{{ cms.form.textarea("mytextarea", {
	save : "Save Me",
}) }}

<!-- This has custom properties set in text collection -->
{{ cms.form.select("myselect") }}

{{ cms.form.select("myselect2", {
	options : {
		"1" : "One",
		"2" : "Two",
		"3" : "Three",
	},
}) }}

<!-- List all of the objects from another collection -->
{{ cms.form.select("relational", {
	options : {
		"1" : "One",
		"2" : "Two",
		"3" : "Three",
	},
	"settings": {
		relationalOptions : {
			collection : "feed",
			label      : "title",
		}
	},
}) }}

<?php include __DIR__ . '/_end.php'; ?>