<?php

declare(strict_types=1);

namespace TotalCMS\CLI\Command;

use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * Merge changes into an existing object.
 *
 * Fills the one gap in the CLI's write surface: object:create only makes new
 * objects and collection:import only works in bulk, so changing a single field
 * on a single object had no supported route and ended up being done by editing
 * the JSON on disk — which skips validation, the collection index, and events.
 *
 * Mirrors the two HTTP patch endpoints rather than inventing a third shape:
 * without --property it merges at the top level (ObjectPatchAction), with
 * --property it merges into that property (ObjectPatchPropertyAction).
 *
 * The distinction matters more than it looks. The merge is shallow, so
 * patching a structured field from the top level REPLACES it wholesale:
 *
 *   object:patch image social '{"image":{"alt":"…"}}'   # drops name, size, …
 *   object:patch image social '{"alt":"…"}' --property=image   # keeps them
 */
class ObjectPatchCommand extends BaseCommand
{
	protected function configure(): void
	{
		parent::configure();
		$this
			->setName('object:patch')
			->setDescription('Merge changes into one existing object from a JSON file (use "-" to read stdin)')
			->addArgument('collection', InputArgument::REQUIRED, 'Collection ID')
			->addArgument('id', InputArgument::REQUIRED, 'Object ID')
			->addArgument('file', InputArgument::REQUIRED, 'Path to a JSON file holding the fields to merge, or "-" for stdin')
			->addOption(
				'property',
				null,
				InputOption::VALUE_REQUIRED,
				'Merge into this property instead of the top level, preserving its other keys'
			)
			->setHelp(<<<'HELP'
Merge changes into an existing object, leaving every field you do not mention
untouched.

  <info>tcms object:patch blog my-post patch.json</info>
  <info>echo '{"title":"New Title"}' | tcms object:patch blog my-post -</info>

The merge is shallow. To change one key inside a structured field without
losing its siblings, target the property:

  <info>echo '{"alt":"A description"}' | tcms object:patch image social - --property=image</info>

Use <info>object:create</info> for new objects and <info>collection:import</info> for bulk changes.
HELP);
	}

	protected function execute(InputInterface $input, OutputInterface $output): int
	{
		$collectionId = (string)$input->getArgument('collection');
		$objectId     = (string)$input->getArgument('id');
		$filePath     = (string)$input->getArgument('file');
		$property     = $input->getOption('property');
		$property     = is_string($property) && $property !== '' ? $property : null;

		if (!$this->totalcms->collectionFetcher()->collectionExists($collectionId)) {
			return $this->outputError($input, $output, "Collection '{$collectionId}' not found.");
		}

		// Checked up front so a missing object reports itself rather than
		// surfacing as a fetch failure from inside the patcher.
		if (!$this->totalcms->objectFetcher()->existsObject($collectionId, $objectId)) {
			return $this->outputError(
				$input,
				$output,
				"Object '{$objectId}' not found in '{$collectionId}'. Use object:create to add it."
			);
		}

		$patchData = $this->readJson($input, $output, $filePath);
		if (!is_array($patchData)) {
			return Command::FAILURE;
		}

		if ($patchData === []) {
			return $this->outputError($input, $output, 'Nothing to patch: the JSON object is empty.');
		}

		try {
			$object = $property === null
				? $this->totalcms->objectPatcher()->patchObject($collectionId, $objectId, $patchData)
				: $this->totalcms->objectPatcher()->patchObjectProperty($collectionId, $objectId, $property, $patchData);
		} catch (\Throwable $e) {
			return $this->outputError($input, $output, "Object patch failed: {$e->getMessage()}");
		}

		if ($this->isJson($input)) {
			$output->writeln((string)json_encode($object->toArray(), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

			return Command::SUCCESS;
		}

		$fields = implode(', ', array_keys($patchData));
		$target = $property === null ? '' : " property '{$property}'";
		$output->writeln("<info>Patched{$target} on '{$objectId}' in '{$collectionId}': {$fields}</info>");

		return Command::SUCCESS;
	}

	/**
	 * Read the patch document from a file or stdin.
	 *
	 * @return array<string,mixed>|null null when the input was rejected and the
	 *                                  error has already been reported
	 */
	private function readJson(InputInterface $input, OutputInterface $output, string $filePath): ?array
	{
		if ($filePath === '-') {
			$content = stream_get_contents(STDIN);
		} else {
			if (!file_exists($filePath)) {
				$this->outputError($input, $output, "File not found: {$filePath}");

				return null;
			}
			$content = file_get_contents($filePath);
		}

		if ($content === false || trim($content) === '') {
			$this->outputError($input, $output, 'No JSON input provided.');

			return null;
		}

		$data = json_decode($content, true);

		if (!is_array($data)) {
			$this->outputError($input, $output, 'Invalid JSON: expected an object of fields to merge.');

			return null;
		}

		if (array_is_list($data)) {
			$this->outputError(
				$input,
				$output,
				'Input is a JSON array — object:patch takes one object. Use collection:import for arrays.'
			);

			return null;
		}

		/** @var array<string,mixed> $data */
		return $data;
	}
}
