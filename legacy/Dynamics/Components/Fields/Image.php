<?php
namespace Dynamics\Components\Fields;

use Dynamics\Dynamics;
use Dynamics\Components\Fields\Color;
use \Imagine\Image\Metadata\ExifMetadataReader;
use \Monolog\Logger;
use \Slim\Http\UploadedFile;
use \ColorThief\ColorThief;

//---------------------------------------------------------------------------------
// IMAGE class
//---------------------------------------------------------------------------------
class Image
{
    public $alt;
    public $colors;
    public $exif;
    public $ext;
    public $featured;
    public $filename;
    public $link;
    public $name;
    public $palettes;
    public $uploadDate;

    private $imagePath;
    private $cachedir;
    private $logger;
    private $dir;
    private $uploadedFile;
    private $datafile;

    public function __construct(string $name, array $options, Logger $logger)
    {
        $options = array_merge(array(
            'directory' => $_SERVER["DOCUMENT_ROOT"],
            'alt'       => $name,
            'filename'  => null,
            'ext'       => 'jpg',
            'link'      => 'javascript:void(0)',
            'colors'    => ["#ffffff","#000000"],
            'featured'  => false,
        ), $options);

        $this->uploadedFile = null;

        $this->logger     = $logger;
        $this->name       = $name;
        $this->dir        = $options["directory"];
        $this->alt        = $options["alt"];
        $this->link       = $options["link"];
        $this->featured   = $options["featured"];
        $this->exif       = $options["exif"]??[];
        $this->ext        = $options["ext"];
        $this->palettes   = new \stdClass();
        $this->uploadDate = date('c');
        $this->filename   = $options["filename"]??sprintf('%s.%s', $this->name, $this->ext);
        $this->datafile   = $this->assetPath(Dynamics::CMS_EXT);

        $this->logger->debug("Colors:", $options["colors"]);

        $this->setColors(...$options["colors"]);

        if (file_exists($this->datafile)) {
            // populate object with existing data
            $data = Dynamics::read($this->datafile);
            // All of this data is statically generated content, not user defined
            // This is why its not pulled in from the options
            $this->ext        = $data["ext"];
            $this->palettes   = $data["palettes"];
            $this->uploadDate = date('c', strtotime($data["uploadDate"]));
        }
        $this->ext = trim($this->ext, ".");

        if (empty(pathinfo($this->filename, PATHINFO_EXTENSION))) {
            // make sure that the filename has an extension
            // I shouldn't need this but I didn't always have filename w/ext
            $this->filename = sprintf('%s.%s', $this->filename, $this->ext);
        }

        $this->imagePath = $this->assetPath();
        $this->cachedir  = implode(DIRECTORY_SEPARATOR, [$this->dir, 'cache', $this->filename]);
    }

    public function setColors(...$colors) : void
    {
        // loop through the passed colors and create Color objects
        $this->colors = array_map(function ($color) {
            $hex = str_replace("##", "#", "#$color");
            return new Color($hex, $this->logger);
        }, $colors);
    }

    public function assetPath(string $ext = "") : string
    {
        $ext      = empty($ext) ? $this->ext : $ext;  // strip dots
        $name     = pathinfo($this->filename, PATHINFO_FILENAME);
        $filepath = sprintf('%s.%s', trim($name, "."), trim($ext, "."));
        return implode(DIRECTORY_SEPARATOR, [$this->dir, $filepath]);
    }

    public function downloadFile(string $fileUrl) : void
    {
        // TODO : Download the image with curl
    }

    public function uploadFile(UploadedFile $file) : void
    {
        // Take the uploaded file and update object with related data
        $this->uploadedFile = $file;

        $this->ext        = pathinfo($file->getClientFilename(), PATHINFO_EXTENSION);
        $this->imagePath  = $this->assetPath();
        $this->filename   = basename($this->imagePath);
        $this->uploadDate = date('c');

        $this->exif = $this->collectMetaData($this->uploadedFile->file);
        $this->palettes = $this->determinePalettes($this->uploadedFile->file);

        // Setting the default colors to the 2nd color.
        // After testing multiple images, the 2nd color seemed best
        $this->setColors($this->palettes['primary'][1], $this->palettes['complementary'][1]);
    }

    private function determinePalettes(string $image) : array
    {
        try {
            // Get palette. tests showed 15 samples produced a good set
            $scanner = ColorThief::getPalette($image, 15);
        } catch (Exception $e) {
            $this->logger->warn('ColorThief Exception: '.$e->getMessage());
            return [];
        }

        // Only take in the first 6 colors in the palette
        $scanner = array_slice($scanner, 0, 6);

        $palettes = [];
        foreach ($scanner as $newcolor) {
            $color = new Color($newcolor, $this->logger);
            $palettes['primary'][] = $color->hex();
            $palettes['complementary'][] = $color->invert()->hex();
        }
        return $palettes;
    }

    private static function calcString($string) : string
    {
        $value = intval($string);
        if ($value === 0) {
            return "";
        }
        if (preg_match('/(\d+)(?:\s*)([\+\-\*\/])(?:\s*)(\d+)/', $string, $matches) != false) {
            $operator = $matches[2];
            switch ($operator) {
                case '+':
                    $value = $matches[1] + $matches[3];
                    break;
                case '-':
                    $value = $matches[1] - $matches[3];
                    break;
                case '*':
                    $value = $matches[1] * $matches[3];
                    break;
                case '/':
                    $value = $matches[1] / $matches[3];
                    break;
            }
        }
        return (string) round($value, 2);
    }

    private static function collectMetaData(string $image) : array
    {
        $title     = '';
        $caption   = '';
        $copyright = '';
        $width     = 0;
        $height    = 0;

        if (function_exists('getimageSize')) {
            $size = getimageSize($image, $info);
            $width = $size[0];
            $height = $size[1];

            if (function_exists('iptcparse')) {
                if (isset($info["APP13"])) {
                    $iptc = iptcparse($info["APP13"]);
                    if (is_array($iptc)) {
                        $title     = array_key_exists('2#005', $iptc) ? $iptc["2#005"][0] : '';
                        $caption   = array_key_exists('2#120', $iptc) ? $iptc["2#120"][0] : '';
                        $copyright = array_key_exists('2#116', $iptc) ? $iptc["2#116"][0] : '';
                    }
                }
            } else {
                throw new Exception("Unable to gather image meta data for alt tag. Is GD and iptcparse installed?");
            }
        } else {
            throw new Exception("Unable to gather image meta data for alt tag. Is GD and getimageSize installed?");
        }

        $imagine = new \Imagine\Gd\Imagine();
        $metadata = $imagine->open($image)->metadata();
        unset($imagine); // free up memory as soon as possible

        $exif = array(
            "focalLength"  => self::calcString($metadata["exif.FocalLength"]),
            "aperture"     => self::calcString($metadata["exif.FNumber"]),
            "exposureBias" => self::calcString($metadata["exif.ExposureBiasValue"]),
            "shutterSpeed" => $metadata["exif.ExposureTime"]??'',
            "iso"          => $metadata["exif.ISOSpeedRatings"]??'',
            "date"         => $metadata["exif.DateTimeOriginal"]??'',
            "make"         => isset($metadata["ifd0.Make"])  ? ucwords($metadata["ifd0.Make"])  : '',
            "model"        => isset($metadata["ifd0.Model"]) ? ucwords($metadata["ifd0.Model"]) : '',
            "copyright"    => $copyright,
            "caption"      => $caption,
            "title"        => $title,
            "width"        => $width,
            "height"       => $height
        );
        return $exif;
    }

    public function delete() : bool
    {
        $this->logger->info('imagePath '.$this->imagePath);
        $this->logger->info('cachedir '.$this->cachedir);
        $this->logger->info('datafile '.$this->datafile);
        Dynamics::delete($this->imagePath);
        Dynamics::delete($this->cachedir);
        return Dynamics::delete($this->datafile);
    }

    public function save() : array
    {
        $this->logger->info('Saving Image to '.$this->dir);

        // Process the alt tag through Mustache to resolve any EXIF data within
        $this->alt = Dynamics::processTemplate($this->alt, $this->exif);

        // save file
        if ($this->uploadedFile) {
            $this->logger->debug('ImagePath '.$this->imagePath);
            $this->uploadedFile->moveTo($this->imagePath);
        }

        // Save Image data to data file
        $data = Dynamics::objectToArray($this);
        $this->logger->debug('DataFile '.$this->datafile);
        Dynamics::save($this->datafile, $data);

        // Clear Cache
        Dynamics::delete($this->cachedir);
        // The ext may have changed, so lets reset and try again, just in case
        $this->cachedir = implode(DIRECTORY_SEPARATOR, [$this->dir, 'cache', $this->filename]);
        Dynamics::delete($this->cachedir);

        // Backup Images

        return $data;
    }
}
