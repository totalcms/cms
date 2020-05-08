<?php
namespace Dynamics\Controllers;

use Dynamics\Settings;
use \Monolog\Logger;
use Dynamics\Dynamics;
use Dynamics\Components\DynamicObject;
use \Slim\Http\UploadedFile;

//---------------------------------------------------------------------------------
// Dynamics Controller
//---------------------------------------------------------------------------------
class CollectionsController extends Controller
{
    public $collection;
    public $dir;
    public $index;
    public $schema;

    //-------------------
    // All Collections
    //-------------------
    public function getCollections() : array
    {
        $files = [];
        $dir   = $this->settings->cms_dir;
        $it    = new \FilesystemIterator($dir, \FilesystemIterator::SKIP_DOTS);
        foreach ($it as $fileinfo) {
            // All collections are the top level folders in tcms-data
            if (!$fileinfo->isDir()) {
                continue;
            }
            $basename = $fileinfo->getBasename();
            if (strpos($basename, '.') === 0) {
                continue;
            }
            $files[] = $basename;
        }
        return $files;
    }

    public function setCollection(string $collection) : void
    {
        $this->collection = $collection;

        $this->dir = implode(DIRECTORY_SEPARATOR, [
            $this->settings->cms_dir,
            $this->collection
        ]);
        Dynamics::makeDir($this->dir);

        // Save things to settings so that it could be passed to objects
        $this->settings->set('dir', $this->dir);

        // Index data file
        $this->index = $this->dir.DIRECTORY_SEPARATOR."_$collection".Dynamics::CMS_EXT;
        $this->settings->set('index', $this->index);

        // This is done here and not in the constructor since we need the dir setting
        $schemaController = new SchemaController($this->settings, $this->logger);
        $this->schema = $schemaController->schema($this->collection);

        $this->logger->debug("Created Dynamics Controller for $collection");
    }

    //-------------------
    // Schema
    //-------------------
    public function getSchema() : array
    {
        return $this->schema->get($this->collection);
    }
    public function saveSchema(array $schema) : array
    {
        return $this->schema->save($schema);
    }

    //-------------------
    // Index
    //-------------------
    public function getIndex() : array
    {
        if (!file_exists($this->index)) {
            $this->rebuildIndex();
        }
        return Dynamics::read($this->index);
    }

    public function rebuildIndex() : array
    {
        $this->logger->info('Rebuilding Dynamics Index: '.$this->collection);
        $index = [];
        $it = new \FilesystemIterator($this->dir, \FilesystemIterator::SKIP_DOTS);
        foreach ($it as $fileinfo) {
            if (!$fileinfo->isFile()) {
                // only files
                continue;
            }
            $basename = $fileinfo->getBasename(Dynamics::CMS_EXT);
            $is_json  = (strpos(Dynamics::CMS_EXT, $fileinfo->getExtension()??'') !== 1); // only JSON files
            $is_dot   = (strpos($basename, '.') === 0); // ignore dot files
            $is_data  = (strpos($basename, '_') === 0); // ignore cms data files that start with underscore

            if ($is_json || $is_dot || $is_data) {
                continue;
            }

            $dyn = $this->dynObject($basename);
            $index[] = $dyn->index();
        }
        return Dynamics::save($this->index, $index);
    }

    //-------------------
    // Single Object
    //-------------------
    public function dynObject(string $id) : DynamicObject
    {
        return new DynamicObject($id, $this->schema, $this->settings, $this->logger);
    }
    public function exists(string $id) : bool
    {
        $obj = $this->dynObject($id);
        return $obj->exists();
    }
    public function getObject(string $id) : array
    {
        $obj = $this->dynObject($id);
        return $obj->get();
    }
    public function updateObject(string $id, array $updateData) : array
    {
        $this->logger->info('Updating Object '.$this->collection.'/'.$id);
        $this->logger->debug('Object Updates', $updateData);

        $dyn = $this->dynObject($id);
        $append = true;
        $dyn->updateProperties($updateData, $append);
        $save = $dyn->save();
        if ($save !== false) {
            $this->rebuildIndex();
        }
        return $save;
    }
    public function saveObject(array $object, bool $rebuild = true) : array
    {
        $this->logger->info('Saving Dynamics Object to '.$this->collection);
        $this->logger->debug('Dynamics Object', $object);

        $dyn = $this->dynObject($object['id']);
        $dyn->updateProperties($object);
        $save = $dyn->save();
        if ($save !== false && $rebuild === true) {
            $this->rebuildIndex();
        }
        return $save;
    }
    public function deleteObject(string $id) : bool
    {
        $this->logger->info('Deleting Dynamics Object: '.$id);
        $obj = $this->dynObject($id);
        $del = $obj->delete();
        $this->rebuildIndex();
        return $del;
    }
    public function clearFieldCache(string $id, string $field) : bool
    {
        $this->logger->info("Clearing Field Cache: $id/$field");
        $dyn = $this->dynObject($id);
        return $dyn->clearFieldCache($field);
    }

    //-------------------
    // Single Object Field
    //-------------------
    public function saveField(string $id, string $field, array $data, bool $append = true) : array
    {
        $dyn = $this->dynObject($id);
        $dyn->setProperty($field, $data, $append);
        $update = $dyn->save();

        // Rebuild index file
        $this->rebuildIndex();

        return $update;
    }

    public function updateField(string $id, string $field, array $data) : array
    {
        return $this->saveField($id, $field, $data, false);
    }

    //-------------------
    // Single Object File
    //-------------------
    public function saveFile(string $id, string $field, UploadedFile $file, array $data) : array
    {
        $this->logger->debug('Dynamics File', [$file, $data]);

        if ($file->getError() === UPLOAD_ERR_OK) {
            $dyn = $this->dynObject($id);
            $newFile = $dyn->saveFile($field, $data, $file);

            // Rebuild index file
            $this->rebuildIndex();

            return $newFile;
        } else {
            $this->logger->error($file->getError());
        }
        return [];
    }

    public function updateFile(string $id, string $field, string $file, array $data) : array
    {
        $dyn = $this->dynObject($id);
        $newFile = $dyn->updateFile($field, $file, $data);

        // Rebuild index file
        $this->rebuildIndex();

        return $newFile;
    }
    public function deleteFile(string $id, string $field, string $file) : array
    {
        $dyn = $this->dynObject($id);
        $del = $dyn->deleteFile($field, $file);

        // Rebuild index file
        $this->rebuildIndex();

        return $del;
    }
}
