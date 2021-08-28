<?php header('X-Robots-Tag: noindex'); ?>

<!-- TotalCMS Debug info -->

<h3>CMS Debug Info</h3>
<div style="color:red">

<?php
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
    return $all_headers;
}

$header = get_all_the_headers();
if (count($header) === 0) {
    echo '<p>Unable to process server request headers.</p>';
}

// TotalCMS Image support check
if (!extension_loaded('gd')) {
    echo "<p>You do not have the PHP gd extension enabled</p>";
}

// TotalCMS curl support check
if (!extension_loaded('curl')) {
    echo "<p>curl extension is not enabled on this server.</p>";
}

// EXIF Check
if (!function_exists('exif_read_data')) {
    echo "<p>The exif_read_data() function is not installed. Images uploaded from mobile cannot be auto-rotated unless this is installed.</p>";
}

if (!function_exists('mb_detect_encoding')) {
    echo "<p>The mb_detect_encoding() function is not installed. Install the php-mbstring package.</p>";
}

// TotalCMS directory checks
// Assuming the this is deployed at /rw_common/plugins/stacks/total-cms
$site_root = preg_replace('/(.*)\/rw_common.+/', '$1', __DIR__);
$cms_dir = "$site_root/cms-data";
if (!file_exists($cms_dir)) {
    if (!mkdir($cms_dir, 0775, true)) {
        echo "<p>Failed to create CMS directory: &lt;site-root&gt;/cms-data</p>";
    }
} else {
    if (!is_writable($cms_dir)) {
        chmod($cms_dir, 0775);
        if (!is_writable($cms_dir)) {
            echo "<p>The CMS directory is not writable. Please fix the permissions on the directory: $cms_dir</p>";
        }
    }
}

// TotalCMS lib dir
$asset_dir = __DIR__;
if (!is_writable($asset_dir)) {
    chmod($cms_dir, 0775);
    if (!is_writable($asset_dir)) {
        echo "<p>The CMS lib directory is not writable. Please fix the permissions on the directory: $asset_dir</p>";
    }
}
if (!extension_loaded('mbstring')) {
    echo '<p>PHP Multibyte String extension is not enabled.</p>';
}

if (version_compare(PHP_VERSION, '7.2.0') <= 0) {
    echo "<p>You are running an unsupported version of PHP. You must be running PHP v7.2+. Your version: ".PHP_VERSION."</p>";
}
?>
</div>

<?php
echo '<p>PHP version: '. phpversion() .'</p>';
echo '<p>HTTP_HOST: '. $_SERVER['HTTP_HOST'] .'</p>';
echo '<p>SERVER_NAME: '. $_SERVER['SERVER_NAME'] .'</p>';
echo '<p>DOCUMENT_ROOT: '. $_SERVER['DOCUMENT_ROOT'] .'</p>';
echo '<p>DOCUMENT_ROOT (realpath): '. realpath($_SERVER['DOCUMENT_ROOT']) .'</p>';
echo '<p>SITE ROOT: '. preg_replace('/(.*).rw_common.+/', '$1', __DIR__) .'</p>';

if (isset($_SERVER['SUBDOMAIN_DOCUMENT_ROOT']) && is_dir($_SERVER['SUBDOMAIN_DOCUMENT_ROOT'])) {
    echo '<p>SUBDOMAIN_DOCUMENT_ROOT (GoDaddy?): '.$_SERVER['SUBDOMAIN_DOCUMENT_ROOT'].'</p>';
}
if (isset($_SERVER['CONTEXT_DOCUMENT_ROOT']) && is_dir($_SERVER['CONTEXT_DOCUMENT_ROOT'])) {
    echo '<p>CONTEXT_DOCUMENT_ROOT: '.$_SERVER['CONTEXT_DOCUMENT_ROOT'].'</p>';
}
if (isset($_SERVER['PHPRC']) && is_dir($_SERVER['PHPRC'])) {
    echo '<p>PHPRC (Strato?): '.$_SERVER['PHPRC'].'</p>';
}

// LiteSpeed server hack. SCRIPT_NAME on shared hosting contains domain name
// This was on A2 hosting. Strip the domain out
echo '<p>SCRIPT_NAME: '. $_SERVER['SCRIPT_NAME'] .'</p>';

echo '<p>POST_MAX_SIZE: '.ini_get('post_max_size').'</p>';
echo '<p>UPLOAD_MAX_FILESIZE: '.ini_get('upload_max_filesize').'</p>';
echo '<p>MEMORY LIMIT: '.ini_get('memory_limit').'</p>';
echo '<p>MAX_EXECUTION_TIME: '.ini_get('max_execution_time').'</p>';

$locale = setlocale(LC_ALL, 0);
echo '<p>SERVER LOCALE: '.$locale.'</p>';
if (strpos($locale, 'UTF-8') === false) {
    setlocale(LC_ALL, 'C.UTF-8');
    if ($locale !== 'C.UTF-8') {
        // A2 Hosting does not support C.UTF-8 trying to fallback
        setlocale(LC_ALL, "en_US.UTF-8");
    }
}
$locale = setlocale(LC_ALL, 0);
echo '<p>CMS LOCALE: '.$locale.'</p>';

$cmsversion = file_get_contents(__DIR__.'/cmsversion');
echo "<p>CMS VERSION: $cmsversion</p>";

if (strpos($cmsversion, 'Total') !== false) {
    include 'totalcms.php';
    $passport = new \TotalCMS\Passport();
    $results = json_encode($passport->verify());
    echo "<p>Total CMS Passport Check: $results</p>";
}

if (file_exists("error_log")) {
    echo "<h3>error_log</h3>";
    echo "<pre>";
    echo file_get_contents("error_log");
    echo "</pre>";
}
?>

<pre><?php if (isset($_GET['info'])) {
    phpinfo();
} ?></pre>