<?php
namespace Dynamics\Importers;

use Dynamics\Dynamics;
use Dynamics\Settings;
use Dynamics\Controllers\CollectionsController;
use \Monolog\Logger;
use \Embed\Embed;
use \Cocur\Slugify\Slugify;
use \League\Uri\Parser;

//---------------------------------------------------------------------------------
// Link Import Class
//---------------------------------------------------------------------------------
class Link
{
    private $logger;
    private $settings;

    public $collection;

    public function __construct(string $collection, array $properties, Logger $logger)
    {
        $this->logger     = $logger;
        $this->collection = $collection;

        $this->properties = array_merge([
            'id'          => 'id',
            'title'       => 'title',
            'description' => 'description',
            'url'         => 'url',
            'hidden'      => 'hidden',
            'date'        => 'date',
            'image'       => 'image'
        ], $properties);
    }

    public function import(string $link) : int
    {
        $dynamics = new CollectionsController(new Settings(), $this->logger);
        $dynamics->setCollection($this->collection);

        try {
            $parser  = new Parser();
            $slugify = new Slugify();
            $info    = Embed::create($link);
            $id      = $slugify->slugify($info->title);
            $domain  = $parser($link)['host'];

            if ($dynamics->exists($id)) {
                // deal with duplicate IDs
                $id = uniqid("$id-");
            }

            $record = [
                $this->properties["id"]          => $id,
                $this->properties["url"]         => $info->url,
                $this->properties["title"]       => $info->title,
                $this->properties["description"] => $info->description,
                $this->properties["domain"]      => $domain,
                $this->properties["hidden"]      => true,
                $this->properties["date"]        => date('c')
            ];
            $rc = $dynamics->saveObject($record, false);

            // TODO : Add logic that will download the image and save it to the post
            // var_dump ($info->image);
            // $object = $dynamics->dynObject($id);
            // $object->downloadFile($image, $info->image);
        } catch (Exception $e) {
            $this->logger->error("Error importing record at row $offset: ".$e->getMessage());
        }
        return $rc;
    }
}
