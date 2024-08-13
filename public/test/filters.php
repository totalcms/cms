<?php include __DIR__ . '/_start.php'; ?>

<h1>Total CMS Filter Test</h1>

{% set objects = cms.objects("blog") | filterCollection([
	{
		property : "date",
		operator : "before",
		value    : "2000-01-01"
	},
	{
		property : "image.size",
		operator : "lt",
		value    : 4000
	},
]) | sortCollection([
	{
		property : "date",
	},
	{
		property : "image.size",
		reverse  : true,
	},
]) %}

{% for object in objects %}
<p>{{ object.id }}: {{ object.date }}: {{ object.image.size }}</p>
{% endfor %}


<?php include __DIR__ . '/_end.php'; ?>