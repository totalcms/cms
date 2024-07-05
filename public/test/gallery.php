<?php include __DIR__ . '/_start.php'; ?>

<h1>Total CMS Gallery Form Demo</h1>

{{ cms.form.gallery('mygallery') }}

{#
{% import "content.twig" as content %}

<h3>Gallery Image</h3>

{{ cms.galleryImage("mygallery", "vw-van5.jpg") }}

<h3>Gallery Content</h3>

{{ content.gallery("mygallery") }}

<script type="module" src="{{ cms.api }}/assets/gallery.js"></script>
<link rel="stylesheet" href="{{ cms.api }}/assets/gallery.css">
#}

<?php include __DIR__ . '/_end.php'; ?>