<?php

declare(strict_types=1);

namespace TotalCMS\Domain\DataView\Service;

use Psr\Log\LoggerInterface;
use TotalCMS\Domain\DataView\Data\DataViewData;
use TotalCMS\Domain\DataView\Repository\DataViewRepository;
use TotalCMS\Domain\Object\Service\ObjectFetcher;
use TotalCMS\Domain\Object\Service\ObjectUpdater;
use TotalCMS\Domain\Property\Data\DateData;
use TotalCMS\Domain\Twig\Service\TwigEngine;
use TotalCMS\Factory\LoggerFactory;
use TotalCMS\Support\OperationResult;

readonly class DataViewBuilder
{
	private LoggerInterface $logger;

	/** Appended to every definition to serialize the user's `data` variable */
	private const DATA_SUFFIX = "\n{{ data|json_encode }}";

	public function __construct(
		private DataViewRepository $viewRepository,
		private ObjectFetcher $objectFetcher,
		private ObjectUpdater $objectUpdater,
		private TwigEngine $twigEngine,
		LoggerFactory $loggerFactory,
	) {
		$this->logger = $loggerFactory
			->addFileHandler('dataviews.log')
			->createLogger('viewbuilder');
	}

	public function buildView(string $viewId): void
	{
		try {
			$object     = $this->objectFetcher->fetchObject(DataViewData::COLLECTION_ID, $viewId);
			$definition = (string)($object->toArray()['definition'] ?? '');

			if ($definition === '') {
				throw new \RuntimeException('View definition is empty');
			}

			$jsonData = $this->executeDefinition($definition);

			$this->viewRepository->saveData($viewId, $jsonData);

			// Update success metadata on the collection object
			$this->objectUpdater->updateObject(DataViewData::COLLECTION_ID, $viewId, array_merge(
				$object->toArray(),
				[
					'lastBuilt' => DateData::cleanDate(),
					'lastError' => '',
				],
			));

			$this->logger->info('View built successfully', [
				'viewId' => $viewId,
			]);
		} catch (\Throwable $e) {
			$this->logger->error("View build failed: {$viewId}", [
				'error' => $e->getMessage(),
			]);

			$this->updateErrorMetadata($viewId, $e->getMessage());
		}
	}

	/**
	 * Execute a definition and return parsed result without saving.
	 */
	public function testView(string $definition): OperationResult
	{
		try {
			$jsonData = $this->executeDefinition($definition);

			return OperationResult::success('', [
				'data'   => $jsonData,
				'output' => '',
			]);
		} catch (\Throwable $e) {
			return OperationResult::failure($e->getMessage(), $e->getMessage(), [
				'data'   => null,
				'output' => '',
			]);
		}
	}

	/**
	 * Execute a definition template by appending the data serializer and decoding the result.
	 *
	 * @return array<mixed>
	 */
	private function executeDefinition(string $definition): array
	{
		$template = $definition . self::DATA_SUFFIX;
		$output   = trim($this->twigEngine->renderString($template));

		if ($output === '') {
			throw new \RuntimeException('View produced no output. Make sure to set a "data" variable.');
		}

		$decoded = json_decode($output, true);

		if (!is_array($decoded)) {
			throw new \RuntimeException('The "data" variable must be set to an array or object. Got: ' . mb_substr($output, 0, 200));
		}

		return $decoded;
	}

	private function updateErrorMetadata(string $viewId, string $error): void
	{
		try {
			$object = $this->objectFetcher->fetchObject(DataViewData::COLLECTION_ID, $viewId);

			$this->objectUpdater->updateObject(DataViewData::COLLECTION_ID, $viewId, array_merge(
				$object->toArray(),
				['lastError' => $error],
			));
		} catch (\Throwable) {
			// View may have been deleted, ignore
		}
	}
}
