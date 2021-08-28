<?php
$locale = setlocale(LC_ALL, 0);
if (strpos($locale, 'UTF-8') === false) {
	setlocale(LC_ALL, 'C.UTF-8');
	if ($locale !== 'C.UTF-8') {
		// A2 Hosting does not support C.UTF-8 trying to fallback
		setlocale(LC_ALL, "en_US.UTF-8");
	}
}

if (!ini_get('date.timezone')) date_default_timezone_set('Europe/London');
error_reporting(E_ALL);

if (isset($_SERVER["HTTP_CF_CONNECTING_IP"])) {
	$_SERVER['REMOTE_ADDR'] = $_SERVER["HTTP_CF_CONNECTING_IP"];
}

if (isset($_SERVER['SUBDOMAIN_DOCUMENT_ROOT']) && is_dir($_SERVER['SUBDOMAIN_DOCUMENT_ROOT'])) {
	# GoDaddy Hackery
	$_SERVER['DOCUMENT_ROOT'] = $_SERVER['SUBDOMAIN_DOCUMENT_ROOT'];
}
elseif (isset($_SERVER['CONTEXT_DOCUMENT_ROOT']) && is_dir($_SERVER['CONTEXT_DOCUMENT_ROOT'])) {
	# Hack for some other stupid damn host
	$_SERVER['DOCUMENT_ROOT'] = $_SERVER['CONTEXT_DOCUMENT_ROOT'];
}

require_once('vendor/autoload.php');
require_once('autoload.php');

use TotalCMS\Component\Alt;
use TotalCMS\Component\Blog;
use TotalCMS\Component\Component;
use TotalCMS\Component\DataStore;
use TotalCMS\Component\Date;
use TotalCMS\Component\Depot;
use TotalCMS\Component\Feed;
use TotalCMS\Component\File;
use TotalCMS\Component\Gallery;
use TotalCMS\Component\HipDepot;
use TotalCMS\Component\HipGallery;
use TotalCMS\Component\Image;
use TotalCMS\Component\Ratings;
use TotalCMS\Component\Text;
use TotalCMS\Component\Toggle;
use TotalCMS\Component\Video;
use TotalCMS\ReplaceText;
