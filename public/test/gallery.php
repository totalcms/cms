<?php include __DIR__ . '/_start.php'; ?>

<h1>Total CMS Gallery Form Demo</h1>

<!--
height      : {min:500,max:1000 },
width       : {min:500,max:1000},
size        : {min:0,max:1000},
count       : {max:10},
orientation : 'landscape',
aspectratio : '4:3',
filetype    : ['image/jpeg'],
filename    : ['image.jpg'],
-->

{{ cms.form.gallery('mygallery', {}, {
	settings : {
		rules : {
			orientation : 'landscape',
		}
	}
}) }}

<h3>Gallery Image</h3>

{{ cms.galleryImage("mygallery", "vw-van5.jpg") }}

<h3>Gallery Content</h3>

{{ cms.gallery("mygallery") }}

<script type="module" src="{{ cms.api }}/assets/gallery.js"></script>
<link rel="stylesheet" href="{{ cms.api }}/assets/gallery.css">

<?php include __DIR__ . '/_end.php'; ?>