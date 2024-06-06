<?php include __DIR__ . '/_start.php'; ?>

	<h1>Total CMS Gallery Form Demo</h1>

	{% import "totalform.twig" as form %}

	<!-- {{ cms.galleryImage("mygallery", "vw-van5.jpg") | raw }} -->

	{{ form.galleryForm('mygallery') }}

<?php include __DIR__ . '/_end.php'; ?>
