<?php include __DIR__ . '/_start.php'; ?>

<h1>Total CMS Pagination Demo</h1>

{% set currentPage = getParams.p ? getParams.p | int : 1 %}
{% set posts = cms.objects('blog') %}
{% set totalObjects = posts | length %}
{% set pageLimit = 2 %}
{% set pageKey = 'p' %}
{% set posts = posts | paginate(pageLimit, currentPage) %}

<div style="display:grid;gap:2rem;grid-template-columns:1fr 1fr">
	{% for post in posts %}
	<article style="padding:1.5rem;background:#f2f2f2;display:grid;gap:1rem;">
		{{ cms.image(post.id, {width: 600}, { collection:'blog' }) }}
		<h2>{{ post.title }}</h2>
		<p>{{ post.summary }}</p>
	</article>
	{% endfor %}
</div>

{{ cms.paginationSimple(totalObjects, currentPage, pageLimit, 'p', 'Prev', 'Next', getParams) }}

<?php include __DIR__ . '/_end.php'; ?>