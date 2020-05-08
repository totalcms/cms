<?php
namespace Dynamics\Controllers;

use Dynamics\Settings;
use Dynamics\Schema;
use \Monolog\Logger;

//---------------------------------------------------------------------------------
// Dynamics Schema Controller
//---------------------------------------------------------------------------------
class SchemaController extends Controller
{
    public function schema(string $collection) : Schema
    {
        return new Schema($collection, $this->settings, $this->logger);
    }

    public function get(string $collection) : array
    {
        $schema = $this->schema($collection);
        return $schema->get();
    }

    public function save(string $collection, array $data) : array
    {
        $this->logger->debug("Saving Schema:".json_encode($data));
        $schema = $this->schema($collection);
        return $schema->save($data);
    }
}
