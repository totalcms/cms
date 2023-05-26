<?php

namespace TotalCMS\Domain\Import;

use Cake\Chronos\Chronos;
use Cocur\Slugify\Slugify;
use Embed\Embed;
use League\Uri\Uri;
use Psr\Log\LoggerInterface;
use Selective\Validation\Exception\ValidationException;
use Selective\Validation\Factory\CakeValidationFactory;
use TotalCMS\Domain\Object\Data\ObjectData;
use TotalCMS\Domain\Object\Repository\ObjectRepository;
use TotalCMS\Factory\LoggerFactory;

final class UrlImporter
{
    private ObjectRepository $storage;
    private LoggerInterface $logger;
    private CakeValidationFactory $validationFactory;

    public function __construct(
        ObjectRepository $storage,
        CakeValidationFactory $validationFactory,
        LoggerFactory $loggerFactory
    ) {
        $this->storage           = $storage;
        $this->validationFactory = $validationFactory;
        $this->logger            = $loggerFactory
            ->addFileHandler('url_importer.log')
            ->createLogger();
    }

    public function import(string $collection, string $link, array $properties = []): void
    {
        $this->validate($link);

        try {
            $embed = new Embed();
            $info  = $embed->get($link);

            $slugify = new Slugify();
            $id      = $slugify->slugify($info->title ?? $link);

            $uri    = Uri::createFromString($link);
            $domain = $uri->getHost();

            if ($this->storage->existsObjectId($collection, $id)) {
                // Deal with duplicate IDs
                $id = uniqid($id . '-');
            }

            $record                = $properties;
            $record['id']          = $id;
            $record['url']         = $info->url;
            $record['title']       = $info->title;
            $record['description'] = $info->description;
            $record['domain']      = $domain;
            $record['hidden']      = true;
            $record['date']        = Chronos::now()->format('c');

            $this->storage->saveObject($collection, new ObjectData($record['id'], $record));
            // @todo Add logic that will download the image and save it to the post
        } catch (\Exception $exception) {
            $this->logger->error(
                sprintf('Error importing URL: %s', $exception->getMessage())
            );
        }
    }

    private function validate(string $link): void
    {
        $validation = $this->validationFactory->createValidator();
        $validation->notEmptyString('link', 'A link is required');
        $validation->url('link', 'Invalid URL');

        $data = [
            'link' => $link,
        ];

        $validationResult = $this->validationFactory->createValidationResult($validation->validate($data));

        if ($validationResult->fails()) {
            throw new ValidationException('Validation failed', $validationResult);
        }
    }
}
