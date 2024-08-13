<?php include __DIR__ . '/_start.php'; ?>

<h1>Total CMS Filter Test</h1>

{% set objects = cms.objects("blog") | filterProps([
	{
		"property" : "title",
		"operator" : "contains",
		"value"    : "test"
	},
	{
		"property" : "tags",
		"operator" : "contains",
		"value"    : cms.getParams.tags
	},
	{
		"property" : "date",
		"operator" : "greaterThan",
		"value"    : "2019-01-01"
	}
]) | sortByProps([
	{
		"property" : "date",
		"direction": "desc"
	},
	{
		"property" : "title",
		"direction": "asc"
	}
]) %}

{% for object in objects %}

{% endfor %}


<?php include __DIR__ . '/_end.php'; ?>