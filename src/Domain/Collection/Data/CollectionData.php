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

    public string $name;       // collection name
    public string $schema;     // schema name
    public string $url;        // collection url to object page minus the slug
    public array  $defaults;   // default form values
    public array  $overrides;  // override form values for specific objects
}
