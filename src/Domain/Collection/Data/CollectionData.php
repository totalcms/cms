<?php

namespace TotalCMS\Domain\Collection\Data;

/**
 * Data object.
 */
final class CollectionData
{
    public const RESERVED_COLLECTIONS = [
        'blog',
        'color',
        'date',
        'email',
        'depot',
        'feed',
        'file',
        'gallery',
        'image',
        'number',
        'svg',
        'text',
        'toggle',
        'url',
    ];

    public string $name;
    public string $schema;
    public string $url;
}
