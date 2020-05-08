<?php
header('X-Robots-Tag: noindex');
include 'totalcms.php';

use TotalCMS\Component\File;
use TotalCMS\Component\Depot;
use TotalCMS\Component\DataStore;

//-------------------------------------------
// GET Requests
//-------------------------------------------
if ($_SERVER['REQUEST_METHOD'] === 'GET') {
	// The type must be specified
	if(!isset($_GET['type'])) exit;

	$mime_type = array(
		'zip'=>'application/zip',
		'pdf'=>'application/pdf',
		'rtf'=>'application/rtf',
		'eps'=>'application/postscript',
		'psd'=>'application/octet-stream',
		'doc'=>'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
		'xls'=>'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
		'ppt'=>'application/vnd.openxmlformats-officedocument.presentationml.presentation',
		'docx'=>'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
		'xlsx'=>'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
		'pptx'=>'application/vnd.openxmlformats-officedocument.presentationml.presentation',
		'mp3'=>'audio/mpeg',
		'mp4'=>'video/mp4',
		'ogg'=>'audio/ogg',
		'ogv'=>'video/ogg',
		'txt'=>'text/plain',
		'html'=>'text/html',
		'css'=>'text/css',
		'csv'=>'text/csv',
		'js'=>'text/javascript',
		'jpg'=>'image/jpeg',
		'png'=>'image/png',
		'gif'=>'image/gif'
	);

	function file_not_found() {
		header('HTTP/1.0 404 Not Found');
	    echo "<h1>Error 404 Not Found</h1>";
    	echo "The requested file could not be found.";
	}

	function file_download($file,$mime,$path) {
		header("Content-disposition: attachment; filename=$file");
		header("Content-type: $mime");
		readfile($path);
	}

	switch ($_GET['type']) {

	    case 'depot':
			$totaldepot = new Depot($_GET['slug'],$_GET);

			$file = $_GET['filename'];
			$ext = pathinfo($file,\PATHINFO_EXTENSION);
			$mime = isset($mime_type[$ext]) ? $mime_type[$ext] : 'application/octet-stream';
			$path = $totaldepot->target_path();
	        break;

        case 'file':
    		$totalfile = new File($_GET['slug'],$_GET);

			$file = $_GET['slug'].'.'.$_GET['ext'];
			$ext  = $_GET['ext'];
			$mime = isset($mime_type[$ext]) ? $mime_type[$ext] : 'application/octet-stream';
			$path = $totalfile->target_path();
            break;

        case 'datastore':
    		$totalstore = new DataStore($_GET['slug'],$_GET);
			$file = $_GET['slug'].'.csv';
			$mime = $mime_type['csv'];
			$path = $totalstore->target_path();
            break;

        default:
        	file_not_found();
        	exit();
	}

	if (file_exists($path)){
		file_download($file,$mime,$path);
	}
	else {
		file_not_found();
	}
}

exit();
