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

{{ cms.gallery("mygallery", { w:300 }, {}, {
	plugins  : ["zoom", "thumbnail", "hash"],
	download : false,
	speed    : 500,
}) }}


<!--
Mode: lg-slide lg-fade lg-zoom-in lg-zoom-in-big lg-zoom-out lg-zoom-out-big lg-zoom-out-in lg-zoom-in-out lg-soft-zoom lg-scale-up lg-slide-circular lg-slide-circular-vertical lg-slide-vertical lg-slide-vertical-growth lg-slide-skew-only lg-slide-skew-only-rev lg-slide-skew-only-y lg-slide-skew-only-y-rev lg-slide-skew lg-slide-skew-rev lg-slide-skew-cross lg-slide-skew-cross-rev lg-slide-skew-ver lg-slide-skew-ver-rev lg-slide-skew-ver-cross lg-slide-skew-ver-cross-rev lg-lollipop lg-lollipop-rev lg-rotate lg-rotate-rev lg-tube
-->

<script type="module" src="{{ cms.api }}/assets/gallery.js"></script>
<link rel="stylesheet" href="{{ cms.api }}/assets/gallery.css">

<?php include __DIR__ . '/_end.php'; ?>