<?php
namespace Dynamics\Components;

use Dynamics\Dynamics;
use Dynamics\Settings;
use Dynamics\Schema;
use Dynamics\Components\Fields\Image;
use Dynamics\Components\Fields\Gallery;
use Dynamics\Components\Fields\Color;
use Dynamics\Components\Fields\StyledAsset;
use \Monolog\Logger;
use \Slim\Http\UploadedFile;
use \Slim\Http\Response;
use League\Glide\ServerFactory;
use League\Glide\Responses\SlimResponseFactory;
use \Cocur\Slugify\Slugify;

//---------------------------------------------------------------------------------
// Dynamics class
//---------------------------------------------------------------------------------
class DynamicObject
{
    private $logger;
    private $assetDir;
    private $settings;

    public $id;
    public $path;
    public $properties;
    public $schema;

    public function __construct(string $id, Schema $schema, Settings $settings, Logger $logger)
    {
        $this->settings = $settings;
        $this->logger   = $logger;
        $this->schema   = $schema;
        $this->id       = $this->cleanString($id);

        $this->path = implode(DIRECTORY_SEPARATOR, [
            $this->settings->get('dir'),
            $this->id.Dynamics::CMS_EXT
        ]);

        $this->properties = $this->get();
        $this->assetDir = implode(DIRECTORY_SEPARATOR, [$this->settings->get('dir'), $this->id]);

        // $this->logger->debug("Created Object:".$this->id);
    }

    public function cleanString(string $string) : string
    {
        $parts   = parse_url($string);
        $slugify = new Slugify();
        return $slugify->slugify($parts["path"]);
    }

    public function exists() : bool
    {
        return file_exists($this->path);
    }

    public function get() : array
    {
        return Dynamics::read($this->path);
    }

    public function delete() : bool
    {
        $this->logger->debug('Deleting Object Assets:'.$this->assetDir);
        Dynamics::delete($this->assetDir);

        $this->logger->debug('Deleting Object:'.$this->id);
        return Dynamics::delete($this->path);
    }

    public function getProperty(string $field) // no return type declaration since the returned value is unknown
    {
        return isset($this->properties[$field]) ? $this->properties[$field] : null;
    }

    public function setProperty(string $field, $value, bool $append = false) : void // PHP 7.1 void return
    {
        if ($append === true) {
            $this->logger->debug("Append to Property $field");
            if (!Dynamics::isAssoc($this->properties[$field])) {
                if (!Dynamics::isAssoc($value)) {
                    $this->logger->debug("Merging Values to $field:", [$this->properties[$field], $value]);
                    $this->properties[$field] = array_merge($this->properties[$field], $value);
                } else {
                    $this->logger->debug("Appending Values to $field:", [$value]);
                    $this->properties[$field][] = $value;
                }
            } else {
                $this->logger->warn("Unable to append to field $field (not array)");
            }
        } else {
            $this->logger->debug("Set Property: $field to:", [$value]);
            $this->properties[$field] = $value;
        }
    }

    public function boolVal(string $value) : bool
    {
        $value = trim($value);
        // false string possibilities
        return !($value === "false" || $value === "0" || $value === "");
    }

    public function listVal($value) : array
    {
        $type = gettype($value);
        if ($type === "string") {
            return preg_split("/\s*,\s*/", $value, PREG_SPLIT_NO_EMPTY);
        }
        if ($type === "array" && !Dynamics::isAssoc($value)) {
            // if it’s a normal array, just use that
            return $value;
        }
        return array($value);
    }

    public function dateVal($time) : string
    {
        return date("c", strtotime($time));
        // return gettype($time) === "string" ? strtotime($time) : $time;
    }

    public function updateProperties(array $new, bool $append = false) : void // PHP 7.1 void return
    {
        // Loop through the schema so that we only process fields that are supposed to be there
        foreach ($this->schema->properties() as $field => $schema) {
            if (isset($new[$field])) {
                // set new value to the one passed
                $newvalue = $new[$field];
            } elseif (isset($schema["default"])) {
                // use default value set in schema
                $newvalue = $schema["default"];
            } else {
                // skip this property if not contained in the new object
                continue;
            }

            switch ($schema["fieldset"]) {
                case 'text':
                case 'video':
                case 'select':
                case 'svg':
                case 'styledtext':
                        $this->setProperty($field, $newvalue);
                    break;

                case 'deck':
                    $this->setProperty($field, $this->listVal($newvalue), $append);
                    break;

                case 'range':
                case 'number':
                    $this->setProperty($field, floatval($newvalue));
                    break;

                case 'toggle':
                case 'checkbox':
                    $this->setProperty($field, $this->boolVal($newvalue));
                    break;

                case 'list':
                case 'multiselect':
                    $this->setProperty($field, $this->listVal($newvalue), $append);
                    break;

                case 'date':
                    $this->setProperty($field, $this->dateVal($newvalue));
                    break;

                case 'color':
                    $color = new Color($newvalue, $this->logger);
                    $this->setProperty($field, $color->toArray());
                    break;

                case 'image':
                    // Dont overwrite image data saveFile/updateFile methods do that
                    if (!$this->getProperty($field)) {
                        $image = new Image($field, $newvalue, $this->logger);
                        $this->setProperty($field, $image);
                    }
                    break;

                case 'gallery':
                    // Dont overwrite gallery data saveFile/updateFile methods do that
                    if (!$this->getProperty($field)) {
                        $this->setProperty($field, $newvalue);
                    }
                    break;

                case 'file':
                    // Dont overwrite file data saveFile/updateFile methods do that
                    if (!$this->getProperty($field)) {
                        $file = new File($field, $newvalue, $this->logger);
                        $this->setProperty($field, $file);
                    }
                    break;

                default:
                    $this->logger->warn("Unknown property while replacing object:", [$newvalue, $field]);
                    break;
            }
        }
    }

    public function save() : array
    {
        $this->logger->info('Saving Object: '.$this->id);
        $this->schema->validateObject($this->properties);
        return Dynamics::save($this->path, $this->properties);
    }

    public function index() : array
    {
        $object = $this->get();
        $indexFields = $this->schema->indexFields();
        $index = [];

        foreach ($indexFields as $key) {
            if (isset($object[$key])) {
                $index[$key] = $object[$key];
            } else {
                $index[$key] = $this->schema->defaultValue($key);
            }
        }
        return $index;
    }

    //--------------------------------
    // Save/Update Files
    //--------------------------------
    public function fieldAssetDir(string $field) : string
    {
        return implode(DIRECTORY_SEPARATOR, [$this->assetDir, $field]);
    }

    public function downloadFile(string $field, string $fileUrl) : array
    {
        // TODO : This method has not been tested at all

        $options = [
            'directory' => $this->fieldAssetDir($field),
            'ext'       => pathinfo($fileUrl, PATHINFO_EXTENSION)
        ];
        Dynamics::makeDir($options['directory']);

        $this->logger->debug("Downloading file of type:", [$field, $this->schema->getType($field)]);

        switch ($this->schema->getType($field)) {
            case 'image':
                $image = new Image($field, $options, $this->logger);
                $image->downloadFile($fileUrl);
                $data = $image->save();
                break;

            default:
                $this->logger->warn("Unknown property while downloading file:", [$name, $field]);
                $data = array();
                break;
        }

        // Save the file data into the object
        $this->setProperty($field, $data);
        $this->save();

        $this->logger->debug('Data from save', $data);
        return $data;
    }

    public function saveFile(string $field, array $options, UploadedFile $file) : array
    {
        $options['directory'] = $this->fieldAssetDir($field);
        Dynamics::makeDir($options['directory']);

        $this->logger->debug("Saving file of type:", [$field, $this->schema->getType($field)]);

        $type = $this->schema->getType($field);
        switch ($type) {
            case 'image':
                $image = new Image($field, $options, $this->logger);
                $image->uploadFile($file);
                $data = $image->save();
                break;

            case 'gallery':
                $gallery = new Gallery($field, $options, $this->logger);
                $data    = $gallery->saveImage($file, $options);
                break;

            case 'file':
                $file = new File($field, $options, $this->logger);
                $file->uploadFile($file);
                $data = $file->save();
                break;

            case 'depot':
                $this->logger->warn("Depot uploads have not been implemented yet");
                $data = array();
                break;

            case 'styledtext':
                $asset = new StyledAsset($field, $options, $this->logger);
                $data  = $asset->saveFile($file);
                break;

            default:
                $this->logger->warn("Unknown property while saving file:", [$name, $field]);
                $data = array();
                break;
        }

        if (!empty($data) and $type !== 'styledtext') {
            // Save the file data into the object
            $this->setProperty($field, $data);
            $this->save();
        }

        $this->logger->debug('Data from save', $data);
        return $data;
    }


    public function updateFile(string $field, string $file, array $data) : array
    {
        $data['directory'] = $this->fieldAssetDir($field);

        switch ($this->schema->getType($field)) {
            case 'image':
                $image = new Image($field, $data, $this->logger);
                $data = $image->save();
                break;

            case 'gallery':
                $gallery = new Gallery($field, $data, $this->logger);
                $gallery->updateImage($file, $data);
                $data = $gallery->getImages();
                break;

            case 'file':
                $file = new File($field, $data, $this->logger);
                $data = $file->save();
                break;

            default:
                $this->logger->warn("Unknown property while replacing object:", [$name, $field]);
                $data = null;
                break;
        }

        // Save the file data into the object
        $this->setProperty($field, $data);
        $this->save();

        $this->logger->debug('Data from update', $data);
        return $data;
    }

    public function deleteFile(string $field, string $file) : array
    {
        $options = [];
        $options['directory'] = $this->fieldAssetDir($field);

        switch ($this->schema->getType($field)) {
            case 'image':
                $image = new Image($field, $options, $this->logger);
                $image->delete();
                $data = [];
                break;

            case 'gallery':
                $gallery = new Gallery($field, $options, $this->logger);
                $gallery->deleteImage($file);
                $data = $gallery->getImages();
                break;

            default:
                $this->logger->warn("Unknown property while deleting file:", [$name, $field]);
                $data = null;
                break;
        }

        // Save the file data into the object
        $this->setProperty($field, $data);
        $this->save();

        $this->logger->debug('Data from update', $data);
        return $data;
    }

    //--------------------------------
    // ImageWorks Render Image
    //--------------------------------
    public function getDataForFile(string $field, string $file) : array
    {
        $dir  = $this->fieldAssetDir($field);
        $path = implode(DIRECTORY_SEPARATOR, [$dir, $file.Dynamics::CMS_EXT]);
        return Dynamics::read($path);
    }

    public function getImageByField(string $field, array $params) : Response
    {
        $this->logger->debug("Created getImageByField for $field.");
        $image = $this->getProperty($field);
        $file = sprintf('%s.%s', $image["filename"], $image["ext"]);
        return $this->getImage($field, $file, $params);
    }

    public function getImage(string $field, string $file, array $params) : Response
    {
        $this->logger->debug("getImage for $field/$file");

        $server = ServerFactory::create([
            'source' => $this->fieldAssetDir($field),
            'cache' => $this->fieldAssetCacheDir($field),
            'response' => new SlimResponseFactory(),
            'group_cache_in_folders' => true,
            'max_image_size' => 2000*2000
        ]);
        // 'watermarks' =>              // Watermarks filesystem
        // 'watermarks_path_prefix' =>  // Watermarks filesystem path prefix
        // 'defaults' =>  []            // Default image manipulations
        // 'presets' => []              // Preset image manipulations

        switch ($this->schema->getType($field)) {
            case 'image':
                // if the file passed did not have an extension, get it from the saved data
                if (empty(pathinfo($file, PATHINFO_EXTENSION))) {
                    $image = $this->getDataForFile($field, $file);
                    $file = sprintf('%s.%s', $file, $image["ext"]);
                }
                break;

            case 'gallery':
                $options = ['directory' => $this->fieldAssetDir($field)];
                $gallery = new Gallery($field, $options, $this->logger);
                $image   = $gallery->findFile($file);
                $file    = $image["filename"];
                if (empty(pathinfo($file, PATHINFO_EXTENSION))) {
                    $file = sprintf('%s.%s', $file, $image["ext"]);
                }
                break;

            default:
                $this->logger->warn("Unknown property while replacing object:", [$name, $field]);
                $data = null;
                break;
        }
        $path = sprintf('%s/%s', $this->fieldAssetDir($field), $file);
        if (!file_exists($path)) {
            $this->logger->error("Unable to locate image file:", [$path]);
            $response = new Response();
            return $response->withStatus(404);
        }
        return $server->getImageResponse($file, $params);
    }

    //--------------------------------
    // ImageWorks Cache
    //--------------------------------
    public function fieldAssetCacheDir(string $field) : string
    {
        return implode(DIRECTORY_SEPARATOR, [$this->assetDir, $field, 'cache']);
    }

    public function clearFieldCache(string $field) : bool
    {
        $this->logger->debug('Deleting Object Assets:'.$this->assetDir);
        return Dynamics::delete($this->fieldAssetCacheDir($field));
    }

    public function clearFileCache(string $field, string $file) : bool
    {
        return Dynamics::delete($this->fieldAssetCacheDir($field).'/'.$file);
    }
}
