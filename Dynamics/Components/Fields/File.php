<?php
namespace Dynamics\Components\Fields;

use Dynamics\Dynamics;
use Dynamics\Components\Fields\Image;
use \Monolog\Logger;
use \Slim\Http\UploadedFile;

//---------------------------------------------------------------------------------
// File class
//---------------------------------------------------------------------------------
class File
{
    public $title;
    public $notes;
    public $ext;
    public $size;
    public $filename;
    public $name;
    public $uploadDate;
    public $download;

    private $filePath;
    private $logger;
    private $dir;
    private $uploadedFile;
    private $datafile;

    public function __construct(string $name, array $options, Logger $logger)
    {
        $options = array_merge(array(
            'directory' => $_SERVER["DOCUMENT_ROOT"],
            'ext'       => 'zip',
            'filename'  => null,
            'title'     => null,
            'notes'     => null,
        ), $options);

        $this->uploadedFile = null;

        $this->logger     = $logger;
        $this->name       = $name;
        $this->dir        = $options["directory"];
        $this->title      = $options["title"];
        $this->notes      = $options["notes"];
        $this->size       = 0;
        $this->uploadDate = date('c');

        if (isset($options["filename"])) {
            $this->filename = $options["filename"];
            $this->ext = pathinfo($this->filename, PATHINFO_EXTENSION);
        } else {
            $this->ext        = $options["ext"];
            $this->filename   = sprintf('%s.%s', $this->name, $this->ext);
        }

        $this->ext      = trim($this->ext, ".");
        $this->filePath = $this->assetPath();
        $this->datafile = $this->assetPath(Dynamics::CMS_EXT);
        $this->download = $this->downloadPath();

        if (file_exists($this->datafile)) {
            // populate object with existing data
            $data = Dynamics::read($this->datafile);
            // All of this data is statically generated content, not user defined
            // This is why its not pulled in from the options
            $this->size       = $data["size"];
            $this->uploadDate = date('c', strtotime($data["uploadDate"]));
        }
    }

    public function assetPath(string $ext = "") : string
    {
        $ext      = empty($ext) ? $this->ext : $ext;
        $name     = pathinfo($this->filename, PATHINFO_FILENAME);
        $filepath = sprintf('%s.%s', trim($name, "."), trim($ext, "."));
        return implode(DIRECTORY_SEPARATOR, [$this->dir, $filepath]);
    }

    public function downloadPath() : string
    {
        return str_replace($_SERVER["DOCUMENT_ROOT"], '', $this->assetPath());
    }

    public function uploadFile(UploadedFile $file) : void
    {
        // Take the uploaded file and update object with related data
        $this->uploadedFile = $file;

        $this->ext        = pathinfo($file->getClientFilename(), PATHINFO_EXTENSION);
        $this->filePath   = $this->assetPath();
        $this->filename   = basename($this->filePath);
        $this->uploadDate = date('c');
    }

    public function delete() : bool
    {
        $this->logger->info('filePath '.$this->filePath);
        $this->logger->info('datafile '.$this->datafile);
        Dynamics::delete($this->filePath);
        return Dynamics::delete($this->datafile);
    }

    public function save() : array
    {
        $this->logger->info('Saving File to '.$this->dir);

        // save file
        if ($this->uploadedFile) {
            $this->logger->debug('filePath '.$this->filePath);
            $this->uploadedFile->moveTo($this->filePath);
            $this->size = ceil(filesize($this->filePath) / 1024);
        }

        // Save file data to data file
        $data = Dynamics::objectToArray($this);
        $this->logger->debug('DataFile '.$this->datafile);
        Dynamics::save($this->datafile, $data);

        // Backup File

        return $data;
    }
}
