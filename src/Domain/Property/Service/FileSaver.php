<?php

namespace TotalCMS\Domain\Property\Service;

use ColorThief\ColorThief;
use PHPExif\Enum\ReaderType as ExifReaderType;
use PHPExif\Exif;
use PHPExif\Reader\Reader as ExifReader;
use TotalCMS\Domain\Object\Data\ObjectData;
use TotalCMS\Domain\Object\Service\ObjectFetcher;
use TotalCMS\Domain\Object\Service\ObjectSaver;
use TotalCMS\Domain\Property\Data\FileData;
use TotalCMS\Domain\Property\Data\GalleryData;
use TotalCMS\Domain\Property\Data\ImageData;
use TotalCMS\Domain\Property\Data\PropertyData;
use TotalCMS\Domain\Property\Repository\PropertyRepository;
use TotalCMS\Domain\Schema\Service\CollectionSchemaFetcher;
use TotalCMS\Domain\Storage\StorageRepository;

/**
 * Service.
 *
 * @SuppressWarnings(PHPMD.CouplingBetweenObjects)
 */
final class FileSaver
{
    private ExifReader $exifReader;

    public function __construct(
        private PropertyRepository $storage,
        private PropertyFetcher $propFetcher,
        private ObjectSaver $objectSaver,
        private CollectionSchemaFetcher $schemaFetcher,
        private ObjectFetcher $objectFetcher,
    ) {
        $this->storage       = $storage;
        $this->propFetcher   = $propFetcher;
        $this->objectSaver   = $objectSaver;
        $this->schemaFetcher = $schemaFetcher;
        $this->objectFetcher = $objectFetcher;

        $readerType       = extension_loaded('imagick') ? ExifReaderType::IMAGICK : ExifReaderType::NATIVE;
        $this->exifReader = ExifReader::factory($readerType);

        // TODO: split this class up into smaller classes for ImageSaver, FileSaver, etc.
    }

    /**
     * save a file to collection object property.
     *
     * @param string $collection
     * @param string $objectID
     * @param string $property
     * @param string $filePath
     *
     * @return ObjectData
     */
    public function saveFile(string $collection, string $objectID, string $property, string $filePath): ObjectData
    {
        $schema = $this->schemaFetcher->fetchSchemaForCollection($collection);
        $type   = basename($schema->properties[$property]['$ref'], StorageRepository::FILE_EXT);

        $method = 'saveFileFor' . ucfirst($type);

        if (!method_exists($this, $method)) {
            throw new \UnexpectedValueException("Invalid file type $type found for property $property in collection $collection");
        }

        return $this->$method($collection, $objectID, $property, $filePath);
    }

    /**
     * fetch property data.
     *
     * @param string $collection
     * @param string $objectID
     * @param string $property
     *
     * @return PropertyData
     */
    private function fetchProperty(string $collection, string $objectID, string $property): PropertyData
    {
        // Get the existing object property data
        $fileProperty = $this->propFetcher->fetchProperty($collection, $objectID, $property);

        if (!$fileProperty instanceof PropertyData) {
            throw new \UnexpectedValueException('Invalid file property found');
        }

        return $fileProperty;
    }

    /**
     * Update the object property.
     *
     * @param string $collection
     * @param string $objectID
     * @param string $property
     * @param array $data
     *
     * @return ObjectData
     */
    private function updateObject(string $collection, string $objectID, string $property, array $data): ObjectData
    {
        $propertyData = [$property => $data];

        return $this->objectSaver->patchObject($collection, $objectID, $propertyData);
    }

    /**
     * save a file to a file property.
     *
     * @param string $collection
     * @param string $objectID
     * @param string $property
     * @param string $filePath
     *
     * @return ObjectData
     */
    public function saveFileForFile(string $collection, string $objectID, string $property, string $filePath): ObjectData
    {
        if (!$this->objectFetcher->existsObject($collection, $objectID)) {
            // TODO: create object if it does not exist
            throw new \UnexpectedValueException('Object does not exist');
        }

        // Clean up existing files in the path. Only one file should exist
        $this->storage->deleteDirectory($collection, $objectID, $property);

        // Update the object with the new file data
        $fileProperty = $this->fetchProperty($collection, $objectID, $property);
        $fileInfo     = $this->storage->saveFile($collection, $objectID, $property, $filePath);
        $newData      = array_merge($fileProperty->transform(), $fileInfo);

        return $this->updateObject($collection, $objectID, $property, $newData);
    }

    /**
     * save a file to depot property.
     *
     * @param string $collection
     * @param string $objectID
     * @param string $property
     * @param string $filePath
     *
     * @return ObjectData
     */
    public function saveFileForDepot(string $collection, string $objectID, string $property, string $filePath): ObjectData
    {
        if (!$this->objectFetcher->existsObject($collection, $objectID)) {
            // TODO: create object if it does not exist
            throw new \UnexpectedValueException('Object does not exist');
        }

        $files    = $this->fetchProperty($collection, $objectID, $property)->transform();
        $fileinfo = $this->storage->saveFile($collection, $objectID, $property, $filePath);
        $files[]  = (new FileData($fileinfo))->transform();

        return $this->updateObject($collection, $objectID, $property, $files);
    }

    /**
     * save a image to a file property.
     *
     * @param string $collection
     * @param string $objectID
     * @param string $property
     * @param string $filePath
     *
     * @return ObjectData
     */
    public function saveFileForImage(string $collection, string $objectID, string $property, string $filePath): ObjectData
    {
        $objectExists = $this->objectFetcher->existsObject($collection, $objectID);
        if (!$objectExists) {
            // Attempt to create the object if it does not exist
            try {
                $image  = new ImageData();
                $this->objectSaver->saveObject($collection, [
                    'id'      => $objectID,
                    $property => $image->transform(),
                ]);
            } catch (\Exception $e) {
                // Object creation failed
                $msg = "Object $objectID does not exist in collection $collection to save image ($property) to. ";
                throw new \UnexpectedValueException($msg . $e->getMessage());
            }
        }

        // Clean up existing files in the path. Only one file should exist
        // This also cleans the cache
        $this->storage->deleteDirectory($collection, $objectID, $property);

        // Update the object with the new file data
        $imageProp = $this->fetchProperty($collection, $objectID, $property);

        // Only keep the data for alt, featrued, link, and tags
        $keep         = ['alt', 'featured', 'link', 'tags'];
        $existingData = array_filter($imageProp->transform(), fn ($key) => in_array($key, $keep), ARRAY_FILTER_USE_KEY);

        $fileData     = $this->storage->saveFile($collection, $objectID, $property, $filePath);
        $exifData     = $this->gatherExifData($filePath);
        $colorData    = self::gatherColorData($filePath);

        $newImage = array_merge($existingData, $fileData, $exifData, $colorData);

        if ($objectExists) {
            // If the object existed before, we will keep the existing data
            $newImage = array_merge($newImage, $existingData);
        }

        return $this->updateObject($collection, $objectID, $property, $newImage);
    }

    /**
     * save a file to depot property.
     *
     * @param string $collection
     * @param string $objectID
     * @param string $property
     * @param string $filePath
     *
     * @return ObjectData
     */
    public function saveFileForGallery(string $collection, string $objectID, string $property, string $filePath): ObjectData
    {
        $objectExists = $this->objectFetcher->existsObject($collection, $objectID);
        if (!$objectExists) {
            // Attempt to create the object if it does not exist
            try {
                $gallery  = new GalleryData();
                $this->objectSaver->saveObject($collection, [
                    'id'      => $objectID,
                    $property => $gallery->transform(),
                ]);
            } catch (\Exception $e) {
                // Object creation failed
                $msg = "Gallery Object $objectID does not exist in collection $collection to save image ($property) to.";
                throw new \UnexpectedValueException($msg . $e->getMessage());
            }
        }

        $images    = $this->fetchProperty($collection, $objectID, $property)->transform();
        $fileData  = $this->storage->saveFile($collection, $objectID, $property, $filePath);
        $exifData  = $this->gatherExifData($filePath);
        $colorData = self::gatherColorData($filePath);
        $newImage  = array_merge($fileData, $exifData, $colorData);

        $images[]  = (new ImageData($newImage))->transform();

        return $this->updateObject($collection, $objectID, $property, $images);
    }

    /**
     * get image color data.
     *
     * @param string $imagepath
     *
     * @return array
     */
    private static function gatherColorData(string $imagepath): array
    {
        // Getting the top 15 colors from the image then reduce to top 5
        // This produces the best results after a lot of testing
        /** @var array<string> $palette */
        $palette = ColorThief::getPalette($imagepath, 15, 10, null, 'hex');
        if (!is_array($palette) || count($palette) === 0) {
            return [];
        }
        $palette = array_slice($palette, 0, 5);

        return ['palette' => $palette];
    }

    /**
     * get image basic data.
     *
     * @param string $imagepath
     *
     * @return array
     */
    private static function gatherBasicImageData(string $imagepath): array
    {
        $imageData = getimagesize($imagepath);
        if (!is_array($imageData)) {
            return [];
        }

        return [
            'mime'   => $imageData['mime'],
            'width'  => $imageData[0],
            'height' => $imageData[1],
        ];
    }

    private static function floatOrNull(string|float|int|bool $value): ?float
    {
        if (!is_bool($value)) {
            // Remove any non-numeric characters
            $value = preg_replace('/[^0-9.]/', '', (string)$value);
            if (is_numeric($value)) {
                return floatval($value);
            }
        }

        return null;
    }

    private static function shutterSpeed(string|bool $speed): ?string
    {
        if (is_bool($speed)) {
            return null;
        }
        if (!str_starts_with($speed, '1/')) {
            return '1/' . $speed;
        }

        return $speed;
    }

    /**
     * get image exif data.
     *
     * @param string $imagepath
     *
     * @return array
     */
    private function gatherExifData(string $imagepath): array
    {
        $exif = $this->exifReader->read($imagepath);

        if (!$exif instanceof Exif) {
            return self::gatherBasicImageData($imagepath);
        }

        $date = $exif->getCreationDate();
        if ($date instanceof \DateTime) {
            $date = $date->format('c');
        }

        $data = [
            // Exposure Data
            'aperture'     => self::floatOrNull($exif->getAperture()),
            'iso'          => self::floatOrNull($exif->getIso()),
            'shutterSpeed' => self::shutterSpeed($exif->getExposure()),
            // Camera Data
            'make'        => $exif->getMake(),
            'camera'      => $exif->getCamera(),
            'lens'        => $exif->getLens(),
            'focalLength' => self::floatOrNull($exif->getFocalLength()),
            // Meta Data
            'author'      => $exif->getAuthor(),
            'description' => $exif->getDescription(),
            // 'keywords'    => $exif->getKeywords(),
            'copyright'   => $exif->getCopyright(),
            'title'       => $exif->getTitle(),
            'date'        => $date,
            // GPS Data
            'longitude'   => self::floatOrNull($exif->getLongitude()),
            'latitude'    => self::floatOrNull($exif->getLatitude()),
            'altitude'    => self::floatOrNull($exif->getAltitude()),
        ];
        // fitler out any null values
        $data     = array_filter($data);
        $keywords = $exif->getKeywords();

        return array_filter([
            'exif'   => $data,
            'tags'   => is_array($keywords) ? $keywords : [],
            'alt'    => $data['title'] ?? $data['description'] ?? '',
            'mime'   => $exif->getMimeType(),
            'width'  => intval($exif->getWidth()),
            'height' => intval($exif->getHeight()),
        ]);
    }
}
