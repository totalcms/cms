<?php
header('X-Robots-Tag: noindex');
if ($_SERVER['REQUEST_METHOD'] == "GET" || empty($_POST['blog'])) {
?>

<section style="padding:1rem">
    <h1>Total CMS Blog Import Utility</h1>
    <p style="max-width:60ch">
        You will need to create a CSV file (<a href="./totalimport.csv" download>download sample</a>).
        This import utlity will import all of the data from the CSV file into the blog. This utility
        does not currently support importing of images. You can edit the blog posts after in order to upload images.
        If you are importing into an existing blog, you may use this import utility to update existing blog content.
        If you remove fields from this CSV file, the data from existing posts will be retained. Only the data provided
        within the CSV file will be modified. The only required field in the CSV is the permalink field.
    </p>
    <form method="post" enctype="multipart/form-data">
        <fieldset>
        <label>Total CMS License Key for Authorization</label><br>
        <input type="text" name="passport" placeholder="License Key">
        <br><br>
        <label>CMS ID of the Blog to Import into</label><br>
        <input type="text" name="blog" placeholder="Blog CMS ID">
        <br><br>
        <label>Choose CSV for Upload</label><br>
        <input type="file" name="csv" accept=".csv">
        <br><br>
        <button type="submit">Import CSV</button>
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
    $importDir = "$cms_dir/imports";
    $csvfile = "$importDir/totalimport-" . time() . ".csv";
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

    if (!file_exists($importDir)) {
        mkdir($importDir);
    }

    move_uploaded_file($_FILES['csv']['tmp_name'], $csvfile);

    $logger = new \TotalCMS\Component\Blog($slug);
    $logger->log_message("Importing data into blog/$slug");

    if (file_exists($csvfile)) {
        //load the CSV document from a file path
        $csv = \League\Csv\Reader::createFromPath($csvfile, 'r');
        $csv->setHeaderOffset(0);
        $records = $csv->getRecords();

        foreach ($records as $newrecord) {
            if (!isset($newrecord["permalink"])) {
                continue;
            }

            $permalink = $newrecord["permalink"];

            if (isset($newrecord["timestamp"])) {
                $newrecord["timestamp"] = strtotime($newrecord["timestamp"]);
            }

            $existing = new \TotalCMS\Component\Blog($slug, $newrecord);
            $existingData = (array) $existing->get_contents();

            if (is_array($existingData)) {
                echo "Merging $permalink with existing post.<br>";
                $newrecord = array_merge($existingData, $newrecord);
            } else {
                echo "Creating new post for $permalink.<br>";
            }
            $newrecord["image"] = [];
            $newrecord["gallery"] = [];

            $listAttrs = ["categories", "tags", "labels"];
            foreach ($listAttrs as $attr) {
                if (is_array($newrecord[$attr])) {
                    $newrecord[$attr] = implode(",", $newrecord[$attr]);
                }
            }

            $newblog = new \TotalCMS\Component\Blog($slug, $newrecord);
            $newblog->save_content("");
        }
        $logger->log_message("Import Complete");
        echo "<p>Import into $slug Complete</p>";
    } else {
        echo "<p>ERROR: Import CSV not found</p>";
    }
}
