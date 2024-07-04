<?php include __DIR__ . '/_start.php'; ?>

<h1>Total CMS Text Form Demo</h1>

{{ cms.form.select("myselect", {
	delete : "Clear Value"
}) }}

{% set selectform = cms.form.builder({
	id         : 'customselect',
	collection : 'text',
	hideID     : true,
}) %}

{{ selectform.addField('text', {
	label       : 'Select Something',
	help        : 'Choose your selection here.',
	placeholder : 'Click to Choose Something',
	field       : 'select',
	options     : [
		{ "value" : "1", "label" : "Custom Option 1" },
		{ "value" : "2", "label" : "Custom Option 2" },
		{ "value" : "3", "label" : "Custom Option 3" },
		{ "value" : "4", "label" : "Custom Option 4" },
		{ "value" : "5", "label" : "Custom Option 5" },
		{ "value" : "6", "label" : "Custom Option 6" }
	]
}) }}
{{ selectform.build() }}


<!--
The Select options can be defined inside of the collection meta

	"customProperties": {
		"myselect": {
			"text": {
				"label"       : "Select Something",
				"help"        : "Choose your selection here.",
				"field"       : "select",
				"placeholder" : "Click to Choose Something",
				"options" : [
					{ "value" : "1", "label" : "Option 1" },
					{ "value" : "2", "label" : "Option 2" },
					{ "value" : "3", "label" : "Option 3" },
					{ "value" : "4", "label" : "Option 4" },
					{ "value" : "5", "label" : "Option 5" },
					{ "value" : "6", "label" : "Option 6" }
				]
			}
		}
    }
-->

<?php include __DIR__ . '/_end.php'; ?>