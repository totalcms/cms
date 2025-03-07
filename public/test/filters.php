<?php include __DIR__ . '/_start.php'; ?>

<h1>Total CMS Filter Test</h1>

{% set objects = cms.objects("blog") | filterCollection([
		{
			property : "image.size",
			operator : "lt",
			value    : 9000
		},
	]) | sortCollection([
		{ property : "featured", reverse : true },
		{ property : "date", reverse : true },
		{ property : "image.size", reverse : true },
]) %}

{% for object in objects %}
<ul>
<li>{{ object.id }}</li>
<li>{{ object.date }}</li>
<li>{{ object.image.size }}</li>
<li>{{ object.title }}</li>
<li>{% if object.featured %}featured{% endif %}</li>
</ul>
{% endfor %}


<?php include __DIR__ . '/_end.php'; ?>