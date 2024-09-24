<?php include __DIR__ . '/_start.php'; ?>

<h1>Total CMS Image Form Demo</h1>

{{ cms.form.image("myimage", {},{
	settings : {
		rules : {
			size : {min:0,max:300},
		}
	}
}) }}

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


<?php include __DIR__ . '/_end.php'; ?>