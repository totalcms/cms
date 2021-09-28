<?php

namespace Dynamics;

use Monolog\Logger;

//---------------------------------------------------------------------------------
// Base Schema
//---------------------------------------------------------------------------------
class Schema
{
    private $settings;
    private $logger;
    private $collection;
    private $schema_file;
    private $schema;

    public function __construct(string $collection, Settings $settings, Logger $logger)
    {
        $this->settings = $settings;
        $this->logger = $logger;
        $this->collection = $collection;
        $this->schema_file = $this->settings->get("dir") . DIRECTORY_SEPARATOR . '_schema' . Dynamics::CMS_EXT;
        $this->schema = $this->get();
        $this->schema_def = __DIR__ . "/Schemas/schema.json";
        $this->logger->debug("Schema for $collection", $this->schema);
    }

    //-------------------
    // Schema
    //-------------------
    public function get(): array
    {
        if (!isset($this->schema)) {
            $this->schema = Dynamics::read($this->schema_file);
        }
        return $this->schema;
    }

    public function getType(string $key): string
    {
        return $this->schema["properties"][$key]["fieldset"];
    }

    public function defaultValue(string $key)
    {
        if (isset($this->schema["properties"][$key])) {
            return null;
        }

        if (isset($this->schema["properties"][$key]["default"])) {
            return $this->schema["properties"][$key]["default"];
        }

        // string number object array null boolean
        switch ($this->schema["properties"][$key]["type"]) {
            case 'string':
                return "";
            case 'number':
                return 0;
            case 'boolean':
                return false;
            case 'array':
                return array();
            case 'object':
                return new \stdClass();
        }

        return null;
    }

    public function indexFields(): array
    {
        return $this->schema["index"];
    }

    public function requireFields(): array
    {
        return $this->schema["required"];
    }

    public function properties(): array
    {
        return $this->schema["properties"];
    }

    public function forceSchemaConstraints(array $schema): array
    {
        $schema['type'] = 'object';
        $schema['title'] = $this->collection;

        if (isset($schema['properties'])) {
            // Force id and collection into properties
            $default = ["type" => "string", "fieldset" => "text"];
            $schema['properties']['id'] = $default;
            $schema['properties']['collection'] = $default;
        }

        // force require field
        if (!isset($schema['required'])) {
            $schema['required'] = [];
        }
        if (!in_array('id', $schema['required'])) {
            array_push($schema['required'], 'id');
        }

        // force index field
        if (!isset($schema['index'])) {
            $schema['index'] = [];
        }
        if (!in_array('id', $schema['index'])) {
            array_push($schema['index'], 'id');
        }

        return $schema;
    }

    public function save(array $schema): array
    {
        $this->logger->info('Saving Dynamics Schema: ' . $this->collection);

        $schema = $this->forceSchemaConstraints($schema);
        $this->validateSchema($schema);

        return Dynamics::save($this->schema_file, $schema);
    }

    private function validate(\stdClass $data, \stdClass $schema): bool
    {
        $validator = new \League\JsonGuard\Validator($data, $schema);

        if ($validator->fails()) {
            $errors = array_map(function ($error) {
                return $error->data_path . ' : ' . $error->message;
            }, Dynamics::reEncode($validator->errors()));
            $this->logger->critical("Schema Validation Failed", $errors);
            // There has to be a better way than this... :-(
            trigger_error("Schema Validation Failed", E_USER_ERROR);
        }

        return true;
    }

    // This method validates schema files for an object
    private function validateSchema(array $schema): bool
    {
        $schema = Dynamics::reEncode($schema);
        $schema_def = Dynamics::read($this->schema_def, false);
        return $this->validate($schema, $schema_def);
    }

    // validate an object against its schema
    public function validateObject(array $object): bool
    {
        $object = Dynamics::reEncode($object);
        $schema = Dynamics::read($this->schema_file, false);
        return $this->validate($object, $schema);
    }
}
