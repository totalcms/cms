<?php

declare(strict_types=1);

namespace TotalCMS\Domain\JumpStart\Service;

use Psr\Log\LoggerInterface;
use TotalCMS\Domain\JumpStart\Data\ImportReport;
use TotalCMS\Domain\JumpStart\Service\Import\CollectionSection;
use TotalCMS\Domain\JumpStart\Service\Import\FactorySection;
use TotalCMS\Domain\JumpStart\Service\Import\ImportRun;
use TotalCMS\Domain\JumpStart\Service\Import\ObjectSection;
use TotalCMS\Domain\JumpStart\Service\Import\SchemaSection;
use TotalCMS\Domain\JumpStart\Service\Import\TemplateSection;
use TotalCMS\Factory\LogChannel;
use TotalCMS\Factory\LoggerFactory;
use TotalCMS\Support\OperationResult;
use TotalCMS\Support\PathResolver;

/**
 * Runs a JumpStart definition: schemas, collections, templates, objects,
 * factory, then the page orders the collections carried — in that order,
 * because each section depends on the ones before it. The sections
 * themselves live in {@see Import}.
 */
readonly class JumpStartImporter
{
	private LoggerInterface $logger;

	public function __construct(
		private SchemaSection $schemas,
		private CollectionSection $collections,
		private TemplateSection $templates,
		private ObjectSection $objects,
		private FactorySection $factory,
		LoggerFactory $loggerFactory,
	) {
		$this->logger = $loggerFactory->channelLogger(LogChannel::JumpStartImporter);
	}

	private function demoJumpstartFile(): string
	{
		return PathResolver::packageRoot() . '/resources/jumpstart/demo.json';
	}

	/**
	 * @param string $filePath               Path to the jumpstart JSON file
	 * @param bool   $allowSystemCollections See ImportRun — pass true only for shell-trusted callers (CLI)
	 *
	 * @SuppressWarnings("PHPMD.BooleanArgumentFlag")
	 */
	public function importFromFile(string $filePath, bool $allowSystemCollections = false): OperationResult
	{
		if (!file_exists($filePath)) {
			throw new \Exception("Jumpstart file not found: {$filePath}");
		}

		$content = file_get_contents($filePath);
		if ($content === false) {
			throw new \Exception("Failed to read jumpstart file: {$filePath}");
		}

		$definition = json_decode($content, true);
		if (json_last_error() !== JSON_ERROR_NONE) {
			throw new \Exception('Invalid JSON in jumpstart file: ' . json_last_error_msg());
		}

		return $this->importFromDefinition($definition, false, $allowSystemCollections);
	}

	/** @SuppressWarnings("PHPMD.BooleanArgumentFlag") */
	public function importDemoDefinition(bool $allowSystemCollections = false): OperationResult
	{
		return $this->importFromFile($this->demoJumpstartFile(), $allowSystemCollections);
	}

	/**
	 * Import a JumpStart definition.
	 *
	 * With `$upsert` false (the default) an object that already exists in the
	 * target collection is left untouched and logged as "skipping" — the
	 * starter-kit semantics `tcms jumpstart:import` and the public
	 * `POST /api/import/jumpstart` endpoint need, where the operator's edits
	 * must not be trampled by a re-run.
	 *
	 * With `$upsert` true (sync push / sync pull) the payload is
	 * authoritative and existing objects are overwritten, so a push from a
	 * local environment lands a true mirror on the remote.
	 *
	 * @param array<string,mixed> $definition
	 * @param bool                $allowSystemCollections See ImportRun — true only for super-admin/shell-trusted callers
	 *
	 * @SuppressWarnings("PHPMD.BooleanArgumentFlag")
	 */
	public function importFromDefinition(array $definition, bool $upsert = false, bool $allowSystemCollections = false): OperationResult
	{
		$run = new ImportRun(new ImportReport($this->logger), $upsert, $allowSystemCollections);

		// Increase execution time for image generation
		set_time_limit(300); // 5 minutes

		$this->logger->info('Starting jumpstart import', [
			'name'    => $definition['name'] ?? 'Unknown',
			'version' => $definition['version'] ?? 'Unknown',
			'upsert'  => $upsert,
		]);

		if (isset($definition['schemas'])) {
			$this->schemas->import($definition['schemas'], $run);
		}
		if (isset($definition['collections'])) {
			$this->collections->import($definition['collections'], $run);
		}
		if (isset($definition['templates'])) {
			$this->templates->import($definition['templates'], $run);
		}
		if (isset($definition['objects'])) {
			$this->objects->import($definition['objects'], $run);
		}
		if (isset($definition['factory'])) {
			$this->factory->import($definition['factory'], $run);
		}
		// Last, and deliberately so: the order arrived with the collection
		// settings but can only be applied once the pages it arranges exist.
		$this->collections->applyPendingPageOrder($run);

		$data = $run->report->toData();

		return $run->report->hasErrors()
			? OperationResult::failure('Import completed with errors', null, $data)
			: OperationResult::success('Import completed successfully', $data);
	}
}
