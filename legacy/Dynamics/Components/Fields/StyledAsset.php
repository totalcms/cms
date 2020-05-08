<?php
namespace Dynamics\Components\Fields;

use Dynamics\Dynamics;
use Dynamics\Components\Fields\Image;
use \Monolog\Logger;
use \Slim\Http\UploadedFile;
use \League\Glide\ServerFactory;
use \League\Glide\Responses\SlimResponseFactory;

//---------------------------------------------------------------------------------
// StyledAsset class
//---------------------------------------------------------------------------------
class StyledAsset
{
    private $logger;
    private $name;
    private $type;
    private $uploadedFile;

    public function __construct(string $name, array $options, Logger $logger)
    {
        $options = array_merge(array(
            'directory' => $_SERVER["DOCUMENT_ROOT"],
            'type'      => 'file',
        ), $options);

        $this->uploadedFile = null;
        $this->name         = $name;
        $this->logger       = $logger;
        $this->type         = $options["type"];
        $this->options      = $options;

        // add the type as a subfolder to the directory
        $this->dir = implode(DIRECTORY_SEPARATOR, [$options["directory"], $this->type]);
        Dynamics::makeDir($this->dir);
    }

    //-----------------------------------
    // Save Asset
    //-----------------------------------
    public function saveFile(UploadedFile $file) : array
    {
        $this->name = $file->getClientFilename();

        if ($this->fileExists()) {
            $this->renameFile();
        }
        $this->logger->debug("Saving file to asset dir ".$this->assetPath());

        // save to cms
        if ($this->type === 'image') {
            // create the upload folder and move it
            Dynamics::makeDir(dirname($this->uploadPath()));
            $file->moveTo($this->uploadPath());
            $this->resizeImage();
        } else {
            // Move the uploaded file into place
            $file->moveTo($this->assetPath());
        }

        // return the required data for Froala
        return [ "link" => $this->assetURI() ];
    }

    public function resizeImage() : void
    {
        // Setup Glide server
        $glide = ServerFactory::create([
            'source' => dirname($this->uploadPath()),
            'cache'  => $this->cachePath()
        ]);

        // resize image
        if (!isset($this->options['fit'])) {
            // Make sure that we don't distort or crop image unless told to
            $this->options['fit'] = 'max';
        }
        $glide->makeImage($this->name, $this->options);

        // Move image into place
        $cachePath = $glide->getCachePath($this->name, $this->options);
        $tempFile  = implode(DIRECTORY_SEPARATOR, [$this->cachePath(), $cachePath]);
        if (!rename($tempFile, $this->assetPath())) {
            // Use the original if cache fails
            rename($this->uploadPath(), $this->assetPath());
        }
    }

    public function cachePath() : string
    {
        return implode(DIRECTORY_SEPARATOR, [$this->dir, 'cache']);
    }

    public function uploadPath() : string
    {
        return implode(DIRECTORY_SEPARATOR, [$this->dir, 'original', $this->name]);
    }

    public function assetPath() : string
    {
        return implode(DIRECTORY_SEPARATOR, [$this->dir, $this->name]);
    }

    public function assetURI() : string
    {
        return str_replace($_SERVER["DOCUMENT_ROOT"], '', $this->assetPath());
    }

    public function fileExists() : bool
    {
        return file_exists($this->assetPath());
    }

    public function renameFile() : void
    {
        // Prevent duplicate files. Append timestamp
        $pathinfo = pathinfo($this->name);
        $filename = $pathinfo["filename"];
        $ext      = $pathinfo["extension"];
        $this->name = $filename.'-'.time().'.'.$ext;
        $this->logger->info("Renamed uploaded file to ".$this->name);
    }
}
