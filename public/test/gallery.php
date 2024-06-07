<?php include __DIR__ . '/_start.php'; ?>

	<h1>Total CMS Gallery Form Demo</h1>

	{% import "totalform.twig" as form %}

	{{ form.galleryForm('mygallery') }}

	{% import "content.twig" as content %}

	<!-- {{ cms.galleryImage("mygallery", "vw-van5.jpg") | raw }} -->

	{{ content.gallery("mygallery") }}

	<script type="module" src="{{ cms.api }}/assets/gallery.js"></script>
	<link rel="stylesheet" href="{{ cms.api }}/assets/gallery.css">

<?php include __DIR__ . '/_end.php'; ?>
