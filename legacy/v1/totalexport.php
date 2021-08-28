<?php
header('X-Robots-Tag: noindex');
if ($_SERVER['REQUEST_METHOD'] == "GET" || empty($_POST['blog'])) {
?>

<section style="padding:1rem">
    <h1>Total CMS Blog Export Utility</h1>
    <p style="max-width:60ch">
    Add the CMS ID of the Blog that you want to export below. When you click on export, it will export
    all blog posts to a CSV file. This CSV will not contain image data.
    </p>
    <form method="post">
        <fieldset>
        <label>Total CMS License Key for Authorization</label><br>
        <input type="text" name="passport" placeholder="License Key">
        <br><br>
        <label>CMS ID of the Blog to Export</label><br>
        <input type="text" name="blog" placeholder="Blog CMS ID">
        <br><br>
        <button type="submit">Export CSV</button>
        </fieldset>
    </form>
</section>

<?php
} else {
    include 'totalcms.php';

    if (!ini_get("auto_detect_line_endings")) {
        ini_set("auto_detect_line_endings", '1');
    }

    // Assuming the this is deployed at /rw_common/plugins/stacks/total-cms
    $site_root = preg_replace('/(.*)\/rw_common.+/', '$1', __DIR__);
    $cms_dir = "$site_root/cms-data";

    $slug = $_POST['blog'];

    try {
        $key = $_POST['passport'] ?? "";
        require_once("total-key.php");

        if (TOTALKEY !== $key) {
            throw new Exception("Invalid passport provided");
        }
    } catch (Exception $e) {
        echo "Unlicensed or Invalid license key provided.";
        exit;
    }


    $blog = new \TotalCMS\Component\Blog($slug);
    $posts = $blog->filter_posts();
    $blog->log_message("Exporting blog data for $slug");

    $csv = "totalexport.csv";
    $writer = \League\Csv\Writer::createFromPath($csv, 'w+');

    $header = false;

    foreach ($posts as $post) {
        $blog->set_permalink($post->permalink);
        $fullPost = $blog->get_contents();
        unset($fullPost->image);
        unset($fullPost->gallery);
        unset($fullPost->words);
        $fullPost->labels = isset($fullPost->labels) ? implode(",", $fullPost->labels) : "";
        $fullPost->categories = implode(",", $fullPost->categories);
        $fullPost->tags = implode(",", $fullPost->tags);
        $fullPost->genre = $fullPost->genre ?? "";
        $fullPost->extra = $fullPost->extra ?? "";
        $fullPost->extra2 = $fullPost->extra2 ?? "";
        $fullPost->media = $fullPost->media ?? "";
        $fullPost->archived = $fullPost->archived ?? false;

        $fullPost->archived = $fullPost->archived ? 1 : 0;
        $fullPost->draft = $fullPost->draft ? 1 : 0;
        $fullPost->featured = $fullPost->featured ? 1 : 0;

        $array = get_object_vars($fullPost);
        ksort($array);
        $data = array_values($array);

        if (!$header) {
            $properties = array_keys($array);
            $writer->insertOne($properties);
            $header = true;
        }
        $writer->insertOne($data);
    }

    header('Content-type: text/csv');
    header('Content-disposition:attachment; filename="'.$csv.'"');
    readfile($csv);
    unlink($csv);
}
