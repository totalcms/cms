<?php
header('X-Robots-Tag: noindex');

function get_all_the_headers()
{
    $all_headers = array();

    if (function_exists('getallheaders')) {
        $all_headers = getallheaders();
    } elseif (function_exists('apache_request_headers')) {
        $all_headers = apache_request_headers();
    } else {
        foreach ($_SERVER as $name => $value) {
            if (substr($name, 0, 5)==='HTTP_') {
                $name=substr($name, 5);
                $name=str_replace('_', ' ', $name);
                $name=strtolower($name);
                $name=ucwords($name);
                $name=str_replace(' ', '-', $name);
                $all_headers[$name] = $value;
            }
        }
    }
    return array_change_key_case($all_headers, CASE_LOWER);
}
$header = get_all_the_headers();

$origin = '';
if (isset($header["origin"])) {
    $origin = $header["origin"];
}
if (isset($header["referer"])) {
    $origin = $header["referer"];
}

if (strpos($origin, '127.0.0.1') !== false) {
    // only do cross origin for localhost calls
    header("Access-Control-Allow-Origin:*");
}

// header("Access-Control-Max-Age: 86400");
header("Access-Control-Allow-Methods: GET, POST, OPTIONS");
header("Access-Control-Allow-Headers: Total-Key");

include 'totalcms.php';

use TotalCMS\Component\Blog;
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
use TotalCMS\Passport;

$data = '';
$method = $_SERVER['REQUEST_METHOD'];

if (!isset($_POST['nodecode'])) {
    // base64 decode content that may contain HTML to get by paranoid host firewall rules
    $base64_fields = ['text','content','summary','extra','feed'];
    foreach ($base64_fields as $field) {
        if (isset($_POST[$field])) {
            $_POST[$field] = base64_decode($_POST[$field]);
        }
    }
    if (isset($_POST['datastore'])) {
        if (is_array($_POST['datastore'])) {
            foreach ($_POST['datastore'] as $index => $value) {
                $_POST[$index] = base64_decode($value);
            }
        } else {
            $_POST['datastore'] = base64_decode($_POST['datastore']);
        }
    }
}

if ($method === 'POST') {
    // PUT and DELETE work around for paranoid hosts
    if (isset($_POST['_METHOD'])) {
        if (isset($_POST['_METHOD']) && $_POST['_METHOD'] == 'PUT') {
            $method = 'PUT';
        }
        if (isset($_POST['_METHOD']) && $_POST['_METHOD'] == 'DELETE') {
            $method = 'DELETE';
        }
    }
    $type = isset($_POST['type']) ? $_POST['type'] : 'none';

    $passport = new Passport();
    if (php_sapi_name() !== 'cli' && $type !== 'passport') {
        $passport->check();
    }
    // Dont check passports when using the CLI or when possibly registering the passport

    if ($passport->in_trial()) {
        // $passport->log_message('Allowing trial request');
    } else {
        if (empty($header['total-key'])) {
            $passport->log_message('Error: No License defined for API request.');
            // $passport->log_message('Headers:'.json_encode($header));
            return_error('No License defined for API request.');
        } elseif ($header['total-key'] !== $passport->apikey()) {
            $passport->log_message('Error: Invalid License provided for API request.');
            // $passport->log_message('Headers:'.json_encode($header));
            return_error('Invalid License provided for API request.');
        }
    }
}

//-------------------------------------------
// POST Requests
//-------------------------------------------
if ($method === 'POST') {
    $type = isset($_POST['type']) ? $_POST['type'] : 'none';

    switch ($type) {
        case 'text':
            $data = text_post($_POST);
            break;

        case 'image':
            $data = image_post($_FILES['file'], $_POST);
            break;

        case 'gallery':
            $data = gallery_post($_FILES['file'], $_POST);
            break;

        case 'hipgallery':
            $data = hipgallery_post($_FILES['file'], $_POST);
            break;

        case 'video':
            $data = video_post($_POST);
            break;

        case 'file':
            $data = file_post($_FILES['file'], $_POST);
            break;

        case 'depot':
            $data = depot_post($_FILES['file'], $_POST);
            break;

        case 'hipdepot':
            $data = hipdepot_post($_FILES['file'], $_POST);
            break;

        case 'feed':
            $image = isset($_FILES['file']) ? $_FILES['file'] : false;
            $data = feed_post($image, $_POST);
            break;

        case 'blog':
            $data = blog_post($_POST);
            break;

        case 'toggle':
            $data = toggle_post($_POST);
            break;

        case 'date':
            $data = date_post($_POST);
            break;

        case 'datastore':
            $data = datastore_post($_POST);
            break;

        case 'ratings':
            $data = ratings_post($_POST);
            break;

        case 'passport':
            // from interim passport check from admin_core
            $data = passport_post($_POST);
            break;

        default:
            if (isset($_POST['src'])) {
                // Froala Editor Delete image
                preg_match("/cms-data\/gallery\/(\S+?)\/(\S+)-sq\.jpg/i", $_POST['src'], $matches);
                if (count($matches) == 3) {
                    // make sure that we have the exact data from match
                    $options = array(
                           'type' => 'hipgallery',
                           'slug' => $matches[1],
                           'filename'=> $matches[2],
                       );
                    $data = hipgallery_delete($matches[1], $options);
                }
            }
    }
}

//-------------------------------------------
// GET Requests
//-------------------------------------------
if ($method === 'GET' && isset($_GET['type'])) {
    switch ($_GET['type']) {

        case 'version':
            $data = get_version();
            break;

        case 'text':
            $data = text_get($_GET['slug']);
            break;

        case 'datastore':
            $data = datastore_get($_GET['slug']);
            break;

        case 'video':
            $data = video_get($_GET['slug']);
            break;

        case 'toggle':
            $data = toggle_get($_GET['slug']);
            break;

        case 'gallery':
            $data = gallery_get($_GET['slug'], $_GET);
            break;

        case 'hipgallery':
            $data = hipgallery_get($_GET['slug'], $_GET);
            break;

        case 'depot':
            $data = depot_get($_GET['slug'], $_GET);
            break;

        case 'blog':
            $data = blog_get($_GET['slug'], $_GET);
            break;

        case 'feed':
            $data = feed_get($_GET['slug'], $_GET);
            break;

        case 'image':
            $data = image_get($_GET['slug'], $_GET);
            break;

        case 'date':
            $data = date_get($_GET['slug']);
            break;

        case 'ratings':
            $data = ratings_get($_GET['slug'], $_GET);
            break;
    }
}

//-------------------------------------------
// PUT Requests
//-------------------------------------------
if ($method === 'PUT' && isset($_POST['type'])) {
    switch ($_POST['type']) {

        case 'gallery':
            if (isset($_POST['featured'])) {
                $data = gallery_featured($_POST['slug'], $_POST['featured'], $_POST);
            } else {
                gallery_update_alt($_POST['slug'], $_POST['alt'], $_POST);
            }
            break;

        case 'image':
            image_put($_POST['slug'], $_POST['alt'], $_POST);
            break;

        case 'datastore':
            datastore_put($_POST['slug'], $_POST);
            break;

        case 'feed':
            feed_put($_POST['slug'], $_POST['alt'], $_POST);
            break;

        case 'ratings':
            $data = ratings_put($_POST);
            break;

        case 'blog':
            if (isset($_POST['featured']) && isset($_POST['filename'])) {
                $data = blog_featured_image($_POST['slug'], $_POST['featured'], $_POST);
            } elseif (isset($_POST['featured'])) {
                $data = blog_featured($_POST['slug'], $_POST);
            } elseif (isset($_POST['draft'])) {
                $data = blog_draft($_POST['slug'], $_POST);
            } elseif (isset($_POST['filename'])) {
                $data = blog_update_alt($_POST['slug'], $_POST['alt'], $_POST);
            }
            break;
    }
}

//-------------------------------------------
// DELETE Requests
//-------------------------------------------
if ($method === 'DELETE' && isset($_POST['type'])) {
    switch ($_POST['type']) {

        case 'gallery':
            gallery_delete($_POST['slug'], $_POST);
            break;

        case 'hipgallery':
            hipgallery_delete($_POST['slug'], $_POST);
            break;

        case 'image':
            image_delete($_POST['slug'], $_POST);
            break;

        case 'file':
            file_delete($_POST['slug'], $_POST);
            break;

        case 'depot':
            depot_delete($_POST['slug'], $_POST);
            break;

        case 'feed':
            feed_delete($_POST['slug'], $_POST);
            break;

        case 'blog':
            blog_delete($_POST['slug'], $_POST);
            break;
    }
}

if ($data === false) {
    return_error('Error! See cms.log for details');
}
return_success("Success!", $data);

//-------------------------------------------
// Return Data
//-------------------------------------------
function return_success($msg, $data)
{
    header('Content-Type: application/json');
    echo json_encode(array(
        'code' 	  => 200,
        'message' => trim(strip_tags($msg)),
        'data'    => $data
    ));
    exit();
}
function return_error($msg)
{
    header('HTTP/1.1 500 Internal Server Error');
    header('Content-Type: application/json');
    echo json_encode(array(
        'code'    => 500,
        'message' => trim(strip_tags($msg)),
        'post'    => $_POST
    ));
    exit();
}

function get_version()
{
    return trim(file_get_contents('cmsversion'));
}

//-------------------------------------------
// Passport API
//-------------------------------------------
function passport_post($options=array())
{
    $passport = new Passport();
    return $passport->check(json_decode(json_encode($options)));
}

//-------------------------------------------
// Ratings API
//-------------------------------------------
function ratings_post($options=array())
{
    $ratings = new Ratings($options['slug'], $options);
    if (isset($options['manual'])) {
        return $ratings->manual_score($options['score'], $options);
    }
    return $ratings->save_content($options['score'], $options);
}
function ratings_put($options=array())
{
    $ratings = new Ratings($options['slug'], $options);
    return $ratings->change_score($options['old'], $options['score']);
}
function ratings_get($slug, $options=array())
{
    $ratings = new Ratings($slug, $options);
    return json_decode($ratings->get_contents());
}

//-------------------------------------------
// Toggle API
//-------------------------------------------
function toggle_post($options=array())
{
    $toggle = new Toggle($options['slug']);
    return $toggle->save_content($options['state']);
}
function toggle_get($slug)
{
    $toggle = new Toggle($slug);
    $status = $toggle->status();
    return $status === false ? 'false' : $status;
}

//-------------------------------------------
// Text API
//-------------------------------------------
function text_post($options=array())
{
    $totaltext = new Text($options['slug']);
    return $totaltext->save_content($options['text'], array(
        'strip' => (array_key_exists('strip', $options) && $options['strip'] === 'true')
    ));
}
function text_get($slug)
{
    $totaltext = new Text($slug);
    return $totaltext->get_contents();
}

//-------------------------------------------
// DataStore API
//-------------------------------------------
function datastore_post($options=array())
{
    $totalcms = new DataStore($options['slug']);
    return $totalcms->save_content($options['datastore'], $options);
}
function datastore_get($slug)
{
    $totalcms = new DataStore($slug);
    return $totalcms->get_contents();
}
function datastore_put($slug, $options)
{
    $totalcms = new DataStore($slug);
    return $totalcms->bulk_update($options['datastore']);
}

//-------------------------------------------
// Date API
//-------------------------------------------
function date_post($options=array())
{
    $totaldate = new Date($options['slug'], $options);
    return $totaldate->save_content($options['timestamp']);
}
function date_get($slug)
{
    $totaldate = new Date($slug);
    return $totaldate->get_contents();
}

//-------------------------------------------
// Video API
//-------------------------------------------
function video_post($options=array())
{
    $totalvideo = new Video($options['slug']);
    return $totalvideo->save_content($options['video']);
}
function video_get($slug)
{
    $totalvideo = new Video($slug);
    return $totalvideo->get_contents();
}

//-------------------------------------------
// Image API
//-------------------------------------------
function image_post($image, $options=array())
{
    $options["uploadname"] = $image["name"];
    $totalimage = new Image($options['slug'], $options);
    if (isset($options['thumbs']) && $options['thumbs'] === '1') {
        $totalimage->add_thumb($totalimage->thumb($options));
        $totalimage->add_thumb($totalimage->square($options));
    }
    return $totalimage->save_content($image, $options);
}
function image_put($slug, $alt, $options)
{
    $totalimage = new Image($slug, $options);
    return $totalimage->update_alt($alt);
}
function image_get($slug, $options)
{
    $totalimage = new Image($slug, $options);
    return $totalimage->to_data();
}
function image_delete($slug, $options)
{
    $totalimage = new Image($slug, $options);
    $totalimage->add_thumb($totalimage->thumb($options));
    $totalimage->add_thumb($totalimage->square($options));
    return $totalimage->delete();
}

//-------------------------------------------
// HipGallery API
//-------------------------------------------
function hipgallery_post($image, $options=array())
{
    $options["uploadname"] = $image["name"];
    $totalgallery = new HipGallery($options['slug'], $options);
    // The froala editor expects a very specific response for adding new images
    header('Content-Type: application/json');
    echo json_encode($totalgallery->save_content($image, $options));
    exit();
}
function hipgallery_get($slug, $options=array())
{
    $totalgallery = new HipGallery($slug, $options);
    // The froala editor expects a very specific response for adding new images
    header('Content-Type: application/json');
    echo json_encode($totalgallery->to_data());
    exit();
}
function hipgallery_delete($slug, $options)
{
    $totalgallery = new HipGallery($slug, $options);
    return $totalgallery->delete();
}

//-------------------------------------------
// Gallery API
//-------------------------------------------
function gallery_post($image, $options=array())
{
    if (isset($options['oldIndex']) && isset($options['newIndex'])) {
        $totalgallery = new Gallery($options['slug'], $options);
        return $totalgallery->reorder_images($options['oldIndex'], $options['newIndex']);
    } else {
        $options["uploadname"] = $image["name"];
        $totalgallery = new Gallery($options['slug'], $options);
        return $totalgallery->save_content($image, $options);
    }
}
function gallery_get($slug, $options=array())
{
    $totalgallery = new Gallery($slug, $options);
    $filename = isset($options['filename']) ? $options['filename'] : false;
    return $totalgallery->to_data($filename);
}
function gallery_update_alt($slug, $alt, $options)
{
    $totalgallery = new Gallery($slug, $options);
    return $totalgallery->update_alt($alt);
}
function gallery_featured($slug, $featured, $options)
{
    $featured = ($featured === 'true');
    $totalgallery = new Gallery($slug, $options);
    return $totalgallery->update_featured($featured);
}
function gallery_delete($slug, $options)
{
    $totalgallery = new Gallery($slug, $options);
    return $totalgallery->delete();
}

//-------------------------------------------
// Blog API
//-------------------------------------------
function blog_post($options=array())
{
    if (isset($options['oldIndex']) && isset($options['newIndex'])) {
        $totalblog = new Blog($options['slug'], $options);
        return $totalblog->reorder_images($options['oldIndex'], $options['newIndex']);
    } elseif (isset($options['posturl'])) {
        $totalblog = new Blog($options['slug'], $options);
        return $totalblog->posturl;
    }
    $image = false;
    if (isset($_FILES['gallery'])) {
        $image = $_FILES['gallery'];
        $options["uploadname"] = $image["name"];
        $options['imageType'] = 'gallery';
    } elseif (isset($_FILES['image'])) {
        $image = $_FILES['image'];
        $options['imageType'] = 'image';
    }
    $options['file'] = $image;
    $totalblog = new Blog($options['slug'], $options);
    return $totalblog->save_content('', $options);
}
function blog_get($slug, $options=array())
{
    $totalblog = new Blog($slug, $options);
    if (isset($options['posturl'])) {
        return $totalblog->posturl;
    }
    if (isset($options['permalink'])) {
        $contents = $totalblog->get_contents();
        return $contents ? $contents : '';
    }
    if (!empty($options['search'])) {
        return $totalblog->search_posts($options['search'], $_GET);
    }

    $filter = isset($options['filter']) ? $options['filter'] : array('all'=>true);
    return $totalblog->filter_posts($filter);
}
function blog_delete($slug, $options)
{
    if (isset($options['filename'])) {
        if (isset($options['isGallery']) && $options['isGallery'] == "false") {
            $totalblog = new Blog($slug, $options);
            return $totalblog->deleteImage();
        } else {
            $options['gallery']['filename'] = $options['filename'];
            $totalblog = new Blog($slug, $options);
            return $totalblog->deleteGalleryImage();
        }
    } else {
        // Delete entire blog post
        $totalblog = new Blog($slug, $options);
        return $totalblog->delete();
    }
}
function blog_featured($slug, $options)
{
    $totalblog = new Blog($slug, $options);
    return $totalblog->toggle_featured();
}
function blog_draft($slug, $options)
{
    $totalblog = new Blog($slug, $options);
    return $totalblog->toggle_draft();
}
function blog_featured_image($slug, $featured, $options)
{
    $featured = ($featured === 'true');
    $totalblog = new Blog($slug, $options);
    return $totalblog->blog_featured_image($featured);
}
function blog_update_alt($slug, $alt, $options)
{
    if (isset($options['isGallery']) && $options['isGallery'] == "false") {
        $totalblog = new Blog($slug, $options);
        return $totalblog->update_imageAlt($alt);
    } else {
        // $options['gallery']['filename'] = $options['filename'];
        $totalblog = new Blog($slug, $options);
        return $totalblog->update_galleryAlt($alt);
    }
}

//-------------------------------------------
// Feed API
//-------------------------------------------
function feed_post($image, $options=array())
{
    $options["uploadname"] = $image["name"];
    $options["image"] = $image;
    $totalfeed = new Feed($options['slug'], $options);
    return $totalfeed->save_content($options['feed'], $options);
}
function feed_get($slug, $options=array())
{
    $totalfeed = new Feed($slug, $options);
    $timestamp = isset($options['timestamp']) ? $options['timestamp'] : false;
    return $totalfeed->to_data($timestamp);
}
function feed_delete($slug, $options)
{
    $totalfeed = new Feed($slug, $options);
    return $totalfeed->delete();
}
function feed_put($slug, $alt, $options)
{
    $totalfeed = new Feed($slug, $options);
    return $totalfeed->update_alt($alt);
}

//-------------------------------------------
// File API
//-------------------------------------------
function file_post($file, $options=array())
{
    $totalfile = new File($options['slug'], $options);
    return $totalfile->save_content($file);
}
function file_delete($slug, $options)
{
    $totalfile = new File($slug, $options);
    return $totalfile->delete();
}

//-------------------------------------------
// Hipwig Depot API
//-------------------------------------------
function hipdepot_post($file, $options=array())
{
    $totaldepot = new HipDepot($options['slug'], array(
        'filename' => $file['name']
    ));
    // The froala editor expects a very specific response for adding new images
    header('Content-Type: application/json');
    echo json_encode($totaldepot->save_content($file));
    exit();
}

//-------------------------------------------
// Depot API
//-------------------------------------------
function depot_post($file, $options=array())
{
    $totaldepot = new Depot($options['slug'], array(
        'filename' => $file['name']
    ));
    return $totaldepot->save_content($file);
}
function depot_get($slug)
{
    $totaldepot = new Depot($slug);
    return $totaldepot->to_data();
}
function depot_delete($slug, $options)
{
    $totaldepot = new Depot($slug, $options);
    return $totaldepot->delete();
}
