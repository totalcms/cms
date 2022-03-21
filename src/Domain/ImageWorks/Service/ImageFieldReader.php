<?php

namespace App\Domain\ImageWorks\Service;

use App\Domain\ImageWorks\Data\ImageFile;
use App\Domain\Storage\CollectionStorage;
use App\Factory\LoggerFactory;
use Psr\Log\LoggerInterface;

final class ImageFieldReader
{
    private CollectionStorage $storage;
    private LoggerInterface $logger;

    public function __construct(CollectionStorage $storage, LoggerFactory $loggerFactory)
    {
        $this->storage = $storage;
        $this->logger = $loggerFactory
            ->addFileHandler('image_works_reader.log')
            ->createLogger();
    }

    public function readImageByField(string $collection, string $id, string $field, array $params): ?ImageFile
    {
        $this->logger->debug('Read image by field', [
            'collection' => $collection,
            'id' => $id,
            'field' => $field,
            'params' => $params,
        ]);

        // @todo Implement logic here
        // $object = $this->storage->getObject($collection, $id);
        // $image = $object->properties->get($field);
        // $file = sprintf('%s.%s', $image['filename'], $image['ext']);
        // ...

        // If found, return image object
        $image = new ImageFile();
        $image->filename = 'example.jpg';
        $image->content = 'binary data';

        return $image;
    }
}
