<?php
namespace Dynamics\Components\Fields;

use Dynamics\Dynamics;
use Dynamics\Components\Fields\Image;
use \Monolog\Logger;
use \Slim\Http\UploadedFile;

//---------------------------------------------------------------------------------
// Gallery class
//---------------------------------------------------------------------------------
class Gallery
{
    public $images;

    private $logger;
    private $name;
    private $index;

    public function __construct(string $name, array $options, Logger $logger)
    {
        $options = array_merge(array(
            'directory' => $_SERVER["DOCUMENT_ROOT"],
        ), $options);

        $this->name   = $name;
        $this->logger = $logger;
        $this->dir    = $options["directory"];
        $this->index  = implode(DIRECTORY_SEPARATOR, [$this->dir, '_'.$name.Dynamics::CMS_EXT]);
        $this->images = $this->getIndex();
    }

    //-----------------------------------
    // Gallery Index
    //-----------------------------------
    public function getIndex() : array
    {
        if (!file_exists($this->index)) {
            $this->rebuildIndex();
        }
        return Dynamics::read($this->index);
    }

    public function saveIndex(array $images) : array
    {
        return Dynamics::save($this->index, $images);
    }

    public function rebuildIndex() : array
    {
        $this->images = [];
        $this->logger->info('Rebuilding Gallery Index: '.$this->name);
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

            $this->images[] = Dynamics::read($fileinfo->getPathname());
        }
        return $this->saveIndex($this->images);
    }

    //-----------------------------------
    // Gallery Get
    //-----------------------------------
    public function getImages() : array
    {
        return $this->getIndex();
    }

    public function imageExists(string $image) : bool
    {
        $path = implode(DIRECTORY_SEPARATOR, [$this->dir, $image]);
        return file_exists($path);
    }

    public function findFile(string $file) : array
    {
        if (empty($file)) {
            return [];
        }
        switch ($file) {
            case (strpos($file, 'first') === 0):
                return $this->images[0];

            case (strpos($file, 'last') === 0):
                return end($this->images);

            case (strpos($file, 'random') === 0):
                return $this->images[array_rand($this->images)];

            case (strpos($file, 'featured') === 0):
                $filtered = array_filter($this->images, function ($image) use ($file) {
                    return ($image["featured"] === true);
                });
                return array_pop($filtered);

            default:
                $filtered = array_filter($this->images, function ($image) use ($file) {
                    return ($image["name"] === $file || $image["filename"] === $file);
                });
                return array_pop($filtered);
        }
        return [];
    }

    //-----------------------------------
    // Save Gallery Image
    //-----------------------------------
    public function saveImage(UploadedFile $file, array $options) : array
    {
        // Pass the filename as an option so that multiple images are saved
        $pathinfo = pathinfo($file->getClientFilename());
        $options["filename"] = $pathinfo["filename"];
        $options["ext"]      = $pathinfo["extension"];

        // Prevent duplicate images. Append timestamp
        if ($this->imageExists($file->getClientFilename())) {
            $options["filename"] = $options["filename"]."-".time();
        }

        $this->logger->debug("Saving image to gallery", $options);

        // Create the image, upload and save
        $image = new Image($this->name, $options, $this->logger);
        $image->uploadFile($file);
        $data = $image->save();

        // Append it to images
        $this->images[] = $data;
        $this->saveIndex($this->images);

        // return the saved data
        return $this->images;
    }

    //-----------------------------------
    // Update Gallery Image Data
    //-----------------------------------
    public function indexOfImage(string $filename)
    {
        // Find the index of the file that has the passed filename
        return array_search($filename, array_column($this->images, 'filename'));
    }

    public function getDataFilePath(string $file) : string
    {
        return implode(DIRECTORY_SEPARATOR, [$this->dir, $file.Dynamics::CMS_EXT]);
    }

    public function getDataForImage(string $file) : array
    {
        return Dynamics::read($this->getDataFilePath($file));
    }

    public function updateImage(string $filename, array $data) : array
    {
        $this->logger->debug('updateImage', $data);
        $current = $this->getDataForImage($filename);
        $update = array_merge($current, $data);
        Dynamics::save($this->getDataFilePath($filename), $update);

        $index = $this->indexOfImage($filename, $this->images);
        if ($index !== false) {
            $this->logger->debug("Found $filename at index $index of", $this->images);
            // update the data at the discovered index
            $this->images[$index] = $update;
            // save the update to the index
            $this->saveIndex($this->images);
        }

        return $update;
    }

    //-----------------------------------
    // Delete Gallery Image
    //-----------------------------------
    public function deleteImage(string $filename) : bool
    {
        $options = [
            "filename" => $filename,
            "directory" => $this->dir
        ];
        $image = new Image($this->name, $options, $this->logger);

        $index = $this->indexOfImage($filename, $this->images);
        if ($index !== false) {
            // delete the image at the discovered index
            unset($this->images[$index]);
            $this->images = array_values($this->images);
            // save the update to the index
            $this->saveIndex($this->images);
        }

        return $image->delete();
    }
}
