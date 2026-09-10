<?php

declare(strict_types=1);

namespace TotalCMS\CLI\Command;

use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use TotalCMS\Domain\Collection\Service\CollectionSaver;
use TotalCMS\Domain\Schema\Data\SchemaData;

/**
 * Create a single collection.
 *
 * The ID decides the path, not the options. A reserved id
 * (`SchemaData::RESERVED_SCHEMAS`) is always provisioned through
 * CollectionFetcher::fetchOrCreateReserved(), so the shipped name and the
 * SINGLETON_COLLECTIONS flag apply — the same result as "Setup Default
 * Collections" but for exactly one collection, which is what an existing site
 * needs when a new reserved collection ships (e.g. `seo-site`).
 *
 * Branching on `--schema` instead was a trap: `collection:create seo-site
 * --schema=seo-site` reads as the obvious spelling and took the custom path,
 * producing a non-singleton `seo-site` collection that looked right and behaved
 * wrong. A reserved id now accepts `--schema` only when it names its own
 * schema, and ignores `--name` / `--singleton` in favour of the shipped values.
 *
 * Any other id is a custom collection and requires `--schema`.
 */
class CollectionCreateCommand extends BaseCommand
{
	protected function configure(): void
	{
		parent::configure();
		$this
			->setName('collection:create')
			->setDescription('Create a collection (reserved ids need no --schema)')
			->addArgument('id', InputArgument::REQUIRED, 'Collection ID')
			->addOption('schema', null, InputOption::VALUE_REQUIRED, 'Schema ID (required for a custom collection; a reserved ID accepts only its own schema name, and needs no --schema at all)')
			->addOption('name', null, InputOption::VALUE_REQUIRED, 'Display name (defaults to the collection ID; ignored for a reserved ID, which uses its shipped name)')
			->addOption('singleton', null, InputOption::VALUE_NONE, 'Create as a single-object collection (ignored for a reserved ID, which uses its shipped setting)');
	}

	protected function execute(InputInterface $input, OutputInterface $output): int
	{
		$id = trim((string)$input->getArgument('id'));
		if ($id === '') {
			return $this->outputError($input, $output, 'A collection ID is required.');
		}

		if ($this->totalcms->collectionFetcher()->collectionExists($id)) {
			return $this->outputError($input, $output, "Collection '{$id}' already exists.");
		}

		$schema   = trim((string)($input->getOption('schema') ?? ''));
		$reserved = in_array($id, SchemaData::RESERVED_SCHEMAS, true);

		// The ID wins. A reserved collection is bound to the schema of the same
		// name, so the only --schema it can accept is that one.
		if ($reserved && $schema !== '' && $schema !== $id) {
			return $this->outputError($input, $output, "The reserved id '{$id}' is bound to its own schema — drop --schema, or pass --schema={$id}.");
		}

		$collection = ($reserved || $schema === '')
			? $this->createReserved($input, $output, $id)
			: $this->createCustom($input, $output, $id, $schema);

		if ($collection === null) {
			return self::FAILURE;
		}

		return $this->outputData($input, $output, $collection);
	}

	/**
	 * @param array<string,mixed>|list<mixed> $data
	 */
	protected function renderHuman(InputInterface $input, OutputInterface $output, array $data): void
	{
		$name      = (string)($data['name'] ?? '');
		$schema    = (string)($data['schema'] ?? '');
		$id        = (string)($data['id'] ?? '');
		$singleton = ($data['singleton'] ?? false) === true ? ', singleton' : '';

		$output->writeln("<info>Collection '{$id}' created.</info>");
		$output->writeln("  name:   {$name}");
		$output->writeln("  schema: {$schema}{$singleton}");
	}

	/**
	 * Provision a reserved collection with its shipped defaults. `--name` and
	 * `--singleton` do not apply here — the shipped name and the
	 * SINGLETON_COLLECTIONS flag are the point of the reserved path.
	 *
	 * @return array<string,mixed>|null null when the error has already been reported
	 */
	private function createReserved(InputInterface $input, OutputInterface $output, string $id): ?array
	{
		if (SchemaData::isReferenceSchema($id)) {
			$this->outputError($input, $output, "The '{$id}' schema is a reference example and cannot back a collection.");

			return null;
		}

		if (!in_array($id, SchemaData::RESERVED_SCHEMAS, true)) {
			$this->outputError($input, $output, "'{$id}' is not a reserved collection — pass --schema=ID to create a custom collection.");

			return null;
		}

		$collection = $this->totalcms->collectionFetcher()->fetchOrCreateReserved($id);
		if ($collection === null) {
			$this->outputError($input, $output, "Failed to create reserved collection '{$id}'.");

			return null;
		}

		return $this->result($collection->id, $collection->schema, $collection->name, $collection->singleton);
	}

	/**
	 * Create a custom collection from an explicit schema.
	 *
	 * @return array<string,mixed>|null null when the error has already been reported
	 */
	private function createCustom(InputInterface $input, OutputInterface $output, string $id, string $schema): ?array
	{
		if (SchemaData::isReferenceSchema($schema)) {
			$this->outputError($input, $output, "The '{$schema}' schema is a reference example and cannot back a collection.");

			return null;
		}

		if (!$this->totalcms->schemaFetcher()->schemaExists($schema)) {
			$this->outputError($input, $output, "Schema '{$schema}' not found.");

			return null;
		}

		$name      = trim((string)($input->getOption('name') ?? ''));
		$singleton = (bool)$input->getOption('singleton');

		try {
			$collection = $this->totalcms->container()->get(CollectionSaver::class)->saveCollection([
				'id'        => $id,
				'schema'    => $schema,
				'name'      => $name === '' ? $id : $name,
				'singleton' => $singleton,
			]);
		} catch (\Throwable $e) {
			$this->outputError($input, $output, "Failed to create collection '{$id}': " . $e->getMessage());

			return null;
		}

		return $this->result($collection->id, $collection->schema, $collection->name, $collection->singleton);
	}

	/**
	 * @return array<string,mixed>
	 */
	private function result(string $id, string $schema, string $name, bool $singleton): array
	{
		return [
			'id'        => $id,
			'schema'    => $schema,
			'name'      => $name,
			'singleton' => $singleton,
			'created'   => true,
		];
	}
}
