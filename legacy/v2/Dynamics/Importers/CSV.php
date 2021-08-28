<?php
namespace Dynamics\Importers;

use Dynamics\Dynamics;
use Dynamics\Settings;
use Dynamics\Controllers\CollectionsController;
use \Monolog\Logger;
use \Slim\Http\UploadedFile;
use League\Csv\Reader;

//---------------------------------------------------------------------------------
// CSV Import Class
//---------------------------------------------------------------------------------
class CSV
{
    private $logger;
    private $settings;

    public $collection;

    public function __construct(string $collection, Settings $settings, Logger $logger)
    {
        // If your CSV document was created or is read on a Mac
        if (!ini_get("auto_detect_line_endings")) {
            ini_set("auto_detect_line_endings", '1');
        }

        $this->settings   = $settings;
        $this->logger     = $logger;
        $this->collection = $collection;
    }

    public function import(UploadedFile $file) : int
    {
        $import_count = 0;

        // Take the uploaded file and update object with related data
        $file->file;
        $csv = Reader::createFromPath($file->file, 'r');
        $csv->setHeaderOffset(0);

        $header = $csv->getHeader(); //returns the CSV header record
        $records = $csv->getRecords(); //returns all the CSV records as an Iterator object

        $dynamics = new CollectionsController($this->settings, $this->logger);
        $dynamics->setCollection($this->collection);

        foreach ($records as $offset => $record) {
            try {
                if (!isset($record["id"]) || $dynamics->exists($record["id"])) {
                    $this->logger->info("Skipping import of record at row $offset");
                    continue;
                }
                // Save the object but do not rebuild the index, we do that at the end
                if ($dynamics->saveObject($record, false)) {
                    $this->logger->info("Imported ".$record["id"]);
                    $this->logger->debug("Imported Record:", $record);
                    $import_count++;
                }
            } catch (Exception $e) {
                $this->logger->error("Error importing record at row $offset: ".$e->getMessage());
            }
        }
        $dynamics->rebuildIndex();

        return $import_count;
    }
}
