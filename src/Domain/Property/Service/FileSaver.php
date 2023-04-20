<?php

namespace TotalCMS\Domain\Property\Service;

use ColorThief\ColorThief;
use PHPExif\Enum\ReaderType as ExifReaderType;
use PHPExif\Exif;
use PHPExif\Reader\Reader as ExifReader;
use Symfony\Component\Serializer\Encoder\JsonEncoder;
use Symfony\Component\Serializer\Normalizer\ObjectNormalizer;
use Symfony\Component\Serializer\Serializer;
use TotalCMS\Domain\Object\Data\ObjectData;
use TotalCMS\Domain\Object\Service\ObjectFetcher;
use TotalCMS\Domain\Object\Service\ObjectUpdater;
use TotalCMS\Domain\Property\Data\FileData;
use TotalCMS\Domain\Property\Data\ImageData;
use TotalCMS\Domain\Property\Data\PropertyData;
use TotalCMS\Domain\Property\Repository\PropertyRepository;
use TotalCMS\Domain\Schema\Service\SchemaFetcher;
use TotalCMS\Domain\Storage\StorageRepository;
use TotalCMS\Utils\ColorUtils;

/**
 * Service.
 */
final class FileSaver
{
    private Serializer $serializer;
    private ExifReader $exifReader;

    public function __construct(
        private PropertyRepository $storage,
        private PropertyFetcher $propFetcher,
        private ObjectUpdater $objectUpdater,
        private SchemaFetcher $schemaFetcher,
        private ObjectFetcher $objectFetcher,
    ) {
        $this->storage       = $storage;
        $this->propFetcher   = $propFetcher;
        $this->objectUpdater = $objectUpdater;
        $this->schemaFetcher = $schemaFetcher;
        $this->objectFetcher = $objectFetcher;
        $this->serializer    = new Serializer([new ObjectNormalizer()], [new JsonEncoder()]);

        $this->exifReader = ExifReader::factory(ExifReaderType::NATIVE);
        // $this->exifReader = ExifReader::factory(ExifReaderType::EXIFTOOL);
        // $this->exifReader = ExifReader::factory(ExifReaderType::FFPROBE);
        // $this->exifReader = ExifReader::factory(ExifReaderType::IMAGICK);
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
        $type   = basename($schema->schema['properties'][$property]['$ref'], StorageRepository::FILE_EXT);

        $method = 'saveFileFor' . ucfirst($type);

        if (!method_exists($this, $method)) {
            throw new \UnexpectedValueException('Invalid file type found');
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
        $propertyJson = $this->serializer->serialize([$property => $data], 'json', ['json_encode_options' => JSON_PRETTY_PRINT]);

        return $this->objectUpdater->updateObject($collection, $objectID, $propertyJson);
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
        if (!$this->objectFetcher->existsObject($collection, $objectID)) {
            // TODO: create object if it does not exist
            throw new \UnexpectedValueException('Object does not exist');
        }

        // Clean up existing files in the path. Only one file should exist
        $this->storage->deleteDirectory($collection, $objectID, $property);

        // Update the object with the new file data
        $imageProp = $this->fetchProperty($collection, $objectID, $property);

        $existingData = $imageProp->transform();
        $fileData     = $this->storage->saveFile($collection, $objectID, $property, $filePath);
        $exifData     = $this->gatherExifData($filePath);
        $colorData    = self::gatherColorData($filePath);

        $newImage = array_merge($existingData, $fileData, $exifData, $colorData);

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
        if (!$this->objectFetcher->existsObject($collection, $objectID)) {
            // TODO: create object if it does not exist
            throw new \UnexpectedValueException('Object does not exist');
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
        $palette = ColorThief::getPalette($imagepath, 15, 10, null, 'hex');
        if (!is_array($palette) || count($palette) === 0) {
            return [];
        }
        $palette       = array_slice($palette, 0, 5);
        $palette       = array_map(fn ($hex) => ColorUtils::colorFromHex((string)$hex), $palette);
        $complimentary = array_map(fn ($color) => ColorUtils::complementary($color), $palette);

        return [
            'palette' => [
                'main'          => array_map(fn ($c) => $c->transform(), $palette),
                'complimentary' => array_map(fn ($c) => $c->transform(), $complimentary),
            ],
            'color' => [
                'main'          => $palette[0]->transform(),
                'complimentary' => $complimentary[0]->transform(),
            ],
        ];
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
            'aperture'     => $exif->getAperture(),
            'iso'          => $exif->getIso(),
            'shutterSpeed' => $exif->getExposure(),
            // Camera Data
            'make'        => $exif->getMake(),
            'camera'      => $exif->getCamera(),
            'lens'        => $exif->getLens(),
            'focalLength' => $exif->getFocalLength(),
            // Meta Data
            'author'      => $exif->getAuthor(),
            'description' => $exif->getDescription(),
            'keywords'    => $exif->getKeywords(),
            'copyright'   => $exif->getCopyright(),
            'title'       => $exif->getTitle(),
            'date'        => $date,
            // GPS Data
            'longitude'   => $exif->getLongitude(),
            'latitude'    => $exif->getLatitude(),
            'altitude'    => $exif->getAltitude(),
        ];
        // fitler out any null values
        $data = array_filter($data);

        return array_filter([
            'exif'   => $data,
            'tags'   => $data['keywords'] ?? [],
            'alt'    => $data['description'] ?? '',
            'mime'   => $exif->getMimeType(),
            'width'  => intval($exif->getWidth()),
            'height' => intval($exif->getHeight()),
        ]);
    }
}
