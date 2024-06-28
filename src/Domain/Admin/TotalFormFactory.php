<?php

namespace TotalCMS\Domain\Admin;

use TotalCMS\Domain\Collection\Service\CollectionFetcher;
use TotalCMS\Domain\Object\Service\ObjectFetcher;
use TotalCMS\Domain\Schema\Service\SchemaFetcher;
use TotalCMS\Domain\Schema\Service\SchemaLister;
use TotalCMS\Support\Config;

/**
 * Total Form Builder.
 *
 * This class is a factory for creating TotalForm objects.
 * I cannot use Dependency Injection in the template, so I need to create a factory class
 * This encapsulate the creation of the TotalForm object without depencency injection here.
 */
final class TotalFormFactory
{
    private string $api;

    public function __construct(
        private Config $config,
        private ObjectFetcher $objectFetcher,
        private CollectionFetcher $collectionFetcher,
        private SchemaFetcher $schemaFetcher,
        private SchemaLister $schemaLister,
    ) {
        $this->api = $this->config->api;
    }

    public function objectFormBuilder(array $options = []): TotalForm
    {
        $options['api']               = $this->api;
        $options['objectFetcher']     = $this->objectFetcher;
        $options['collectionFetcher'] = $this->collectionFetcher;
        $options['schemaFetcher']     = $this->schemaFetcher;
        $options['schemaLister']      = $this->schemaLister;

        return new TotalForm(...$options);
    }
}
