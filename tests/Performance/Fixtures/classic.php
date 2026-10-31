<?php
// Hot path: a classic hand-built PHP page (the Stacks / non-Site-Builder way).
// Copied to the test site's public/classic.php. TotalCMS buffers the page and
// renders the Twig in it at the end, outside the Slim middleware stack.
define('TCMS_PROJECT_ROOT', dirname(__DIR__));
require_once TCMS_PROJECT_ROOT . '/vendor/autoload.php';
$totalcms = new TotalCMS\TotalCMS();
?>
<!DOCTYPE html>
<html lang="en">
<head>
	<meta charset="utf-8">
	<title>Classic listing</title>
</head>
<body>
	<h1>Classic PHP listing</h1>
	{% for post in cms.collection.objects('posts') | sortCollection([{property: "date", reverse: true}]) | paginate(20, 1) %}
		<article>
			<h2><a href="{{ cms.collection.objectUrl('posts', post) }}">{{ post.title }}</a></h2>
			<div>{{ post.summary | raw }}</div>
		</article>
	{% endfor %}
</body>
</html>
<?php echo $totalcms->processBufferMacros();
