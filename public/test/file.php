<?php include __DIR__ . '/_start.php'; ?>

<h1>Total CMS File Form Demo</h1>

{{ cms.form.file("myfile", {},{
	settings : {}
}) }}

<!--
size        : {min:0,max:1000},
count       : {max:10},
filetype    : ['image/jpeg'],
filename    : ['image.jpg'],
-->

<?php include __DIR__ . '/_end.php'; ?>