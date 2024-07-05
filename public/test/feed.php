<?php include __DIR__ . '/_start.php'; ?>

<h1>Total CMS Feed Form Demo</h1>

{{ cms.form.feed({
	helpOnHover : true,
	helpStyle   : "help-label",
	save        : "Save",
	delete      : "Delete",
}) }}

<h2>Feed Posts</h2>
{% for post in cms.objects('feed') %}
<article>
	<img src="{{ cms.imagePath(post.id, {w:600}, {collection: 'feed', property: 'image'}) }}" alt="{{ cms.alt(post.id, {collection: 'feed', property: 'image'}) }}" />
	<h4>{{ post.title }}</h4>
	<p>{{ post.created }}</p>
	<p>{{ post.content }}</p>
</article>
{% endfor %}

<?php include __DIR__ . '/_end.php'; ?>