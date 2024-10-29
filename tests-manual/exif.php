<?php

error_reporting(E_ERROR);

chdir(__DIR__);

require_once '../vendor/autoload.php';

use PHPExif\Reader\Reader;
use PHPExif\Enum\ReaderType as ExifReaderType;
use PHPExif\Exif;
use PHPExif\Reader\Reader as ExifReader;

$readerType = extension_loaded('imagick') ? ExifReaderType::IMAGICK : ExifReaderType::NATIVE;
$reader     = ExifReader::factory($readerType);

$images = scandir('images');

foreach ($images as $image) {
    if (strpos($image, '.') === 0) {
        continue;
    }
    $exif = $reader->read("images/$image");
    $data = [
        'aperture'    => $exif->getAperture(),
        'iso'         => $exif->getIso(),
        'shutter'     => $exif->getExposure(),
        'make'        => $exif->getMake(),
        'camera'      => $exif->getCamera(),
        'lens'        => $exif->getLens(),
        'focal'       => $exif->getFocalLength(),
        'author'      => $exif->getAuthor(),
        'description' => $exif->getDescription(),
        'keywords'    => $exif->getKeywords(),
        'copyright'   => $exif->getCopyright(),
        'title'       => $exif->getTitle(),
        'date'        => $exif->getCreationDate(),
        // 'width'       => $exif->getWidth(),
        // 'height'      => $exif->getHeight(),
        'longitude' => $exif->getLongitude(),
        'latitude'  => $exif->getLatitude(),
        'altitude'  => $exif->getAltitude(),
        'country'   => $exif->getCountry(),
        'city'      => $exif->getCity(),
        'state'     => $exif->getState(),
        'sublocation'  => $exif->getSublocation(),
    ];
    echo "$image:" . PHP_EOL;
    print_r(array_filter($data));
    echo PHP_EOL . PHP_EOL;
}
