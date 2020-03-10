<?php
namespace Dynamics\Controllers;

use Dynamics\Settings;
use \Monolog\Logger;
use Dynamics\Dynamics;
use Dynamics\Importers\CSV;
use Dynamics\Importers\Link;
use \Slim\Http\UploadedFile;

//---------------------------------------------------------------------------------
// Dynamics Controller
//---------------------------------------------------------------------------------
class ImportController extends Controller
{
    public $collection;

    //-------------------
    // Import CSV
    //-------------------
    public function importCSV(string $collection, UploadedFile $file) : int
    {
        if ($file->getError() !== UPLOAD_ERR_OK) {
            $this->logger->error($file->getError());
            return false;
        }

        $importer = new CSV($collection, $this->settings, $this->logger);
        return $importer->import($file);
    }

    public function importLink(string $collection, array $data) : int
    {
        $props    = $data["properties"]??[];
        $importer = new Link($collection, $props, $this->logger);
        return $importer->import($data["link"]);
    }
}
