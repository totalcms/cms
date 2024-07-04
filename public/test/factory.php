<?php include __DIR__ . '/_start.php'; ?>

<h1>Total CMS Factory Demo</h1>

{{ cms.form.factory('blog', {
	refresh: true,
}) }}

{% set posts = cms.objects('blog') %}

<h2>Blog Posts</h2>
{% for post in posts %}
<article>
	<img src="{{ cms.imagePath(post.id, {w:600}, 'blog','image') }}" alt="{{ cms.alt(post.id, 'blog','image') }}" />
	<h4>{{ post.title }}</h4>
	<p>{{ post.created }}</p>
	<p>{{ post.summary }}</p>
</article>
{% endfor %}

<?php include __DIR__ . '/_end.php'; ?>