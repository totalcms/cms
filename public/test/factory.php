<?php include __DIR__ . '/_start.php'; ?>

	<h1>Total CMS Feed Form Demo</h1>

	{% import "totalform.twig" as form %}

	{{ form.factoryForm('feed', 3) }}

	{% set posts = cms.objects('feed') %}

	<h2>Feed Posts</h2>
	{% for post in posts %}
		<article>
			<img = src="{{ cms.imagePath(post.id, {w:600}, 'feed','image') }}" alt="{{ cms.alt(post.id, 'feed','image') }}">
			<h4>{{ post.title }}</h4>
			<p>{{ post.created }}</p>
			<p>{{ post.content | raw }}</p>
		</article>
	{% endfor %}

<?php include __DIR__ . '/_end.php'; ?>
