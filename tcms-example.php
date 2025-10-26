<?php
require_once 'dist/autoload.php';
$totalcms = new TotalCMS\TotalCMS();
?>

<!DOCTYPE html>
<html>
	<head>
		<meta charset="utf-8">
		<meta http-equiv="X-UA-Compatible" content="IE=edge">
		<title>Total CMS Example</title>
		<meta name="description" content="">
		<meta name="viewport" content="width=device-width, initial-scale=1">

		<!-- Total CMS Content -->
		<link rel="stylesheet" href="{{cms.api}}/assets/content.css?v={{cms.version}}"/>
		<link rel="stylesheet" href="{{cms.api}}/assets/cms-grid.css?v={{cms.version}}"/>
		<link rel="stylesheet" href="{{cms.api}}/assets/gallery.css?v={{cms.version}}"/>
		<link rel="stylesheet" href="{{cms.api}}/assets/pagination.css?v={{cms.version}}"/>
		<link rel="preload" as="script" href="{{cms.api}}/assets/content.js?v={{cms.version}}" />
		<link rel="preload" as="script" href="{{cms.api}}/assets/gallery.js?v={{cms.version}}" />

		<!-- Load is using Total CMS Forms/Admin -->
		<link rel="stylesheet" href="{{cms.api}}/assets/admin.css?v={{cms.version}}"/>
		<link rel="preload" as="script" href="{{cms.api}}/assets/admin.js?v={{cms.version}}" />
	</head>
	<body>

		<h1>Total CMS Example Page</h1>


		<!-- Total CMS Content -->
		<script type="module" src="{{cms.api}}/assets/content.js?v={{cms.version}}"></script>
		<script type="module" src="{{cms.api}}/assets/gallery.js?v={{cms.version}}"></script>

		<!-- Load is using Total CMS Forms/Admin -->
		<script type="module" src="{{cms.api}}/assets/admin.js?v={{cms.version}}"></script>
	</body>
</html>