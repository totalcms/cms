<?php

error_reporting(E_ERROR);

chdir(__DIR__);

require_once '../../vendor/autoload.php';

use PHPExif\Reader\Reader;

// reader with Native adapter
$reader = Reader::factory(Reader::TYPE_NATIVE);

// reader with Exiftool adapter
// $reader = \PHPExif\Reader\Reader::factory(\PHPExif\Reader\Reader::TYPE_EXIFTOOL);

// reader with FFmpeg/FFprobe adapter
// $reader = \PHPExif\Reader\Reader::factory(\PHPExif\Reader\Reader::TYPE_FFPROBE);

// reader with Imagick adapter
// $reader = \PHPExif\Reader\Reader::factory(\PHPExif\Reader\Reader::TYPE_IMAGICK);

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
        'longitude'   => $exif->getLongitude(),
        'latitude'    => $exif->getLatitude(),
        'altitude'    => $exif->getAltitude(),
    ];
    echo "$image:" . PHP_EOL;
    print_r(array_filter($data));
    echo PHP_EOL . PHP_EOL;
}
