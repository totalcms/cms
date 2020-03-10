<?php
	require 'totalcms.php';
	if (intval(ini_get('memory_limit')) < 256)	{
//		ini_set('memory_limit', '256M');
	}
	try {
//		$path = '/Users/joeworkman/Desktop/IMG_5754.jpg';
//		$path = '/Volumes/RAID/Downloads/Archiv/e7q00j_8n7a-nasa.jpg';
		$path = '/Volumes/RAID/Downloads/C07A2257.jpg';
		$imagine = new \Imagine\Gd\Imagine();
		$image = $imagine->open($path);
		echo 'hello';
	}
	catch (\Imagine\Exception\Exception $e) {
		// handle the exception
		echo $e;
	}
?>