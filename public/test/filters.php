<?php include __DIR__ . '/_start.php'; ?>

<h1>Total CMS Filter Test</h1>

{% set objects = cms.objects("blog") | filterCollection([
	{
		property : "image.size",
		operator : "lt",
		value    : getParams.size ?? ""
	},
]) | sortCollection([
	{
		property : "image.size",
		reverse  : true,
	},
]) %}

{% for object in objects %}
<p>{{ object.image.size }}</p>
{% endfor %}


<?php include __DIR__ . '/_end.php'; ?>