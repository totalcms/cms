<?php include __DIR__ . '/_start.php'; ?>

	<h1>Total CMS Blog Form Demo</h1>

	{% import "totalform.twig" as form %}

	{{ form.blogForm('blog', {
		save   : true,
		delete : true,
		fields : {
			date       : true,
			summary    : true,
			content    : true,
			author     : true,
			tags       : true,
			featured   : true,
			draft      : true,
			image      : true,
			categories : false,
			extra      : false,
			extra2     : false,
			media      : false,
			genre      : false,
			labels     : false,
			archived   : false,
			gallery    : false,
		}
	}) }}

<?php include __DIR__ . '/_end.php'; ?>
