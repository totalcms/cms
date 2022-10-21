<?php

namespace App\Domain\Property\Data;

/**
 * String type property data.
 */
class ImageData extends PropertyData
{
    public ListData $tags;
    public DateData $uploadDate;
    public array    $color;
    public array    $exif;
    public array    $focalpoint;
    public array    $palette;
    public string   $alt;
    public string   $mime;
    public string   $link;
    public string   $name;
    public int      $size;
    public int      $width;
    public int      $height;
    public bool     $featured;

    public const DEFAULT_PALETTE = [
        'main'          => [],
        'complimentary' => [],
    ];
    public const DEFAULT_FOCALPOINT = [
        'x' => 50,
        'y' => 50,
    ];

    public function __construct(array $file = [])
    {
        $this->alt        = $file['alt'] ?? '';
        $this->color      = $file['color'] ?? self::DEFAULT_PALETTE;
        $this->exif       = $file['exif'] ?? ['nodata' => ''];
        $this->featured   = $file['featured'] ?? false;
        $this->focalpoint = $file['focalpoint'] ?? self::DEFAULT_FOCALPOINT;
        $this->height     = intval($file['height'] ?? 0);
        $this->link       = $file['link'] ?? '';
        $this->mime       = $file['mime'] ?? '';
        $this->name       = $file['name'] ?? '';
        $this->palette    = $file['palette'] ?? self::DEFAULT_PALETTE;
        $this->size       = intval($file['size'] ?? 0);
        $this->tags       = new ListData($file['tags'] ?? []);
        $this->width      = intval($file['width'] ?? 0);

        $uploadDate       = empty($file['uploadDate']) ? date('c') : $file['uploadDate'];
        $this->uploadDate = new DateData($uploadDate);
    }

    public function transform(): array
    {
        return [
            'alt'        => $this->alt,
            'color'      => $this->color,
            'exif'       => $this->exif,
            'featured'   => $this->featured,
            'focalpoint' => $this->focalpoint,
            'height'     => $this->height,
            'link'       => $this->link,
            'mime'       => $this->mime,
            'name'       => $this->name,
            'palette'    => $this->palette,
            'size'       => $this->size,
            'tags'       => $this->tags->transform(),
            'uploadDate' => $this->uploadDate->transform(),
            'width'      => $this->width,
        ];
    }
}
