<?php

namespace App\Domain\ImageWorks\Service;

use App\Domain\Property\Data\ColorData;
use App\Domain\Storage\StorageAdapterInterface;
use App\Support\Config;
use App\Utils\ColorUtils;
use League\Glide\Responses\PsrResponseFactory;
use League\Glide\Server;
use League\Glide\ServerFactory;
use Slim\Psr7\Response;
use Slim\Psr7\Stream;

final class GlideFactory
{
    private StorageAdapterInterface $filesystem;
    private Config $config;

    public const CACHEDIR    = '.cache';
    public const COLOR_NAMES = ['main', 'complimentary'];

    public function __construct(StorageAdapterInterface $filesystem, Config $config)
    {
        $this->filesystem = $filesystem;
        $this->config     = $config;
    }

    /**
     * Create a glide server.
     *
     * @param string $source
     * @param ?string $cache
     * @param ?string $watermark
     *
     * @return Server
     */
    public function create(string $source, ?string $cache = null, ?string $watermark = null): Server
    {
        $glide = ServerFactory::create([
            'source'                 => $this->filesystem->flysystem(),
            'cache'                  => $this->filesystem->flysystem(),
            'watermarks'             => $this->filesystem->flysystem(),
            'source_path_prefix'     => $source,
            'cache_path_prefix'      => sprintf('%s/%s', $source, $cache ?? self::CACHEDIR),
            'watermarks_path_prefix' => $this->watermarkPath($watermark),
            'driver'                 => extension_loaded('imagick') ? 'imagick' : 'gd',
            'defaults'               => $this->config->imageworks['defaults'],
            'presets'                => $this->config->imageworks['presets'],
            'response'               => new PsrResponseFactory(new Response(), fn ($stream) => new Stream($stream)),
        ]);

        return $glide;
    }

    /**
     * @param ?string $watermark
     *
     * @return string
     */
    public function watermarkPath(?string $watermark): string
    {
        $objectID = $watermark ?? $this->config->imageworks['watermarksGallery'];

        return sprintf('gallery/%s/gallery', $objectID);
    }

    /**
     * @param string $crop
     * @param array $focalpoint
     *
     * @return string
     */
    public static function cropFocalpoint(string $crop, array $focalpoint): string
    {
        $newcrop = sprintf('crop-%g-%g', $focalpoint['x'], $focalpoint['y']);

        return str_replace('focalpoint', $newcrop, $crop);
    }

    /**
     * @param string $background
     * @param array $imageColors
     *
     * @return string
     */
    public static function updateBackgroundColor(string $background, array $imageColors): string
    {
        foreach (self::COLOR_NAMES as $color) {
            if ($background === $color) {
                return ColorUtils::colorToHex(new ColorData($imageColors[$color]));
            }
        }

        return $background;
    }

    /**
     * @param string $border
     * @param array $imageColors
     *
     * @return string
     */
    public static function updateBorderColor(string $border, array $imageColors): string
    {
        foreach (self::COLOR_NAMES as $color) {
            if (str_contains($border, $color)) {
                $hex = ColorUtils::colorToHex(new ColorData($imageColors[$color]));

                return str_replace($color, $hex, $border);
            }
        }

        return $border;
    }
}
