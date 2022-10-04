<?php

namespace App\Domain\ImageWorks\Service;

use App\Domain\Storage\StorageAdapterInterface;
use League\Glide\Responses\PsrResponseFactory;
use League\Glide\Server;
use League\Glide\ServerFactory;
use Slim\Psr7\Response;
use Slim\Psr7\Stream;

final class GlideFactory
{
    private StorageAdapterInterface $filesystem;

    public const CACHEDIR      = '.cache';
    public const WATERMARKSDIR = 'gallery/watermarks/gallery';

    public function __construct(StorageAdapterInterface $filesystem)
    {
        $this->filesystem = $filesystem;
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
            'cache_path_prefix'      => $cache ?? self::CACHEDIR,
            'watermarks_path_prefix' => $watermark ?? self::WATERMARKSDIR,
            'driver'                 => extension_loaded('imagick') ? 'imagick' : 'gd',
            'defaults'               => [], // defaults set in config?
            'presets'                => [], // presets set in config?
            'response'               => new PsrResponseFactory(new Response(), fn ($stream) => new Stream($stream)),
        ]);

        // TODO: add support for default and presets in config

        return $glide;
    }
}
