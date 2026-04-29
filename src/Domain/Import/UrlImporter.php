<?php

namespace TotalCMS\Domain\Import;

use Cake\Chronos\Chronos;
use Embed\Embed;
use League\Uri\Uri;
use Psr\Log\LoggerInterface;
use Selective\Validation\Exception\ValidationException;
use Selective\Validation\Factory\CakeValidationFactory;
use TotalCMS\Domain\Event\EventDispatcher;
use TotalCMS\Domain\Event\Payload\ImportEventPayload;
use TotalCMS\Domain\Object\Data\ObjectData;
use TotalCMS\Domain\Object\Repository\ObjectRepository;
use TotalCMS\Domain\Property\Data\SlugData;
use TotalCMS\Factory\LoggerFactory;

readonly class UrlImporter
{
	public LoggerInterface $logger;

	public function __construct(
		private ObjectRepository $storage,
		private CakeValidationFactory $validationFactory,
		private EventDispatcher $eventDispatcher,
		LoggerFactory $loggerFactory,
	) {
		$this->logger = $loggerFactory->addFileHandler('importer.log')->createLogger('url-importer');
	}

	/** @param array<string,mixed> $properties */
	public function import(string $collection, string $link, array $properties = []): void
	{
		$this->validate($link);

		try {
			$embed = new Embed();
			$info  = $embed->get($link);

			$id = SlugData::slugify($info->title ?? $link);

			$uri    = Uri::createFromString($link);
			$domain = $uri->getHost();

			if ($this->storage->existsObject($collection, $id)) {
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

			$this->eventDispatcher->dispatch('import.completed', new ImportEventPayload($collection, 1, [$record['id']]));
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
