<?php include __DIR__ . '/_start.php'; ?>

<h1>Total CMS Filter Test</h1>

{% set objects = cms.objects("blog") | filterCollection([
	{
		"property" : "date",
		"operator" : "before",
		"value"    : "2000-01-01"
	},
]) %}

{% for object in objects %}
<p>{{ object.id }}: {{ object.date }}</p>
{% endfor %}


<?php include __DIR__ . '/_end.php'; ?>