<?php

declare(strict_types=1);

namespace TotalCMS\CLI\Command;

use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use TotalCMS\Domain\Collection\Data\CollectionData;
use TotalCMS\Domain\Collection\Service\CollectionSettingsUpdater;
use TotalCMS\Domain\Mcp\Tool\Service\McpToolsValidator;

/**
 * Change an existing collection's settings.
 *
 * collection:create makes a collection and collection:get reads one, but
 * nothing changed one afterwards: setting `ownerProperty`, a URL or the sort
 * meant the admin, the API, or editing `.meta.json` by hand, which skips
 * validation and the collection.updated event.
 *
 * Merges like the MCP `update_collection` tool, through the same
 * CollectionSettingsUpdater: settings maps (`properties`, `mcp`, `seo`, …)
 * merge one key at a time, a key set to null removes it, and every other
 * setting is replaced. The save validates the result as the collection form
 * does, and `mcp.tools` is checked as the REST endpoints check it.
 */
class CollectionUpdateCommand extends BaseCommand
{
	protected function configure(): void
	{
		parent::configure();
		$this
			->setName('collection:update')
			->setDescription('Change an existing collection\'s settings with --set key=value, or from JSON (use "-" to read stdin)')
			->addArgument('id', InputArgument::REQUIRED, 'Collection ID')
			->addArgument('file', InputArgument::OPTIONAL, 'Path to a JSON file holding the settings to change, or "-" for stdin')
			->addOption('set', null, InputOption::VALUE_REQUIRED | InputOption::VALUE_IS_ARRAY, 'Set one setting, as key=value; a dot reaches into a map (mcp.access=authenticated). Repeatable')
			->setHelp(<<<'HELP'
Change some of a collection's settings, keeping the rest.

  <info>tcms collection:update recipes --set ownerProperty=author</info>
  <info>tcms collection:update blog --set url=/blog/{id} --set sortBy=date --set reverseSort=true</info>
  <info>tcms collection:update blog --set mcp.access=authenticated</info>

A --set value is read as JSON when it parses (true, 5, null, ["a","b"]) and
as a string otherwise; quote it as JSON to keep a string that looks like
something else: --set 'labelSingular="2024"'. A dot reaches into a map, so
--set properties.price.label=Cost sets one field override.

Bigger changes can come as JSON, from a file or stdin, and --set is applied
on top of it:

  <info>echo '{"url":"/blog/{id}","sortBy":"date","reverseSort":true}' | tcms collection:update blog -</info>

These settings are maps that merge one key at a time, so send only the keys
you change: properties, customProperties, formSettings, mcp, seo, sitemap,
feed. A key set to null removes it. Field overrides live under properties,
keyed by property name, and each field's overrides merge too:

  <info>echo '{"properties":{"price":{"label":"Cost"}}}' | tcms collection:update products -</info>
  <info>echo '{"mcp":{"access":"authenticated"}}' | tcms collection:update blog -</info>

Every other setting is replaced by the value sent. A collection cannot be
renamed, and its storage format changes only with collection:convert.
HELP);
	}

	protected function execute(InputInterface $input, OutputInterface $output): int
	{
		$id         = (string)$input->getArgument('id');
		$collection = $this->totalcms->collectionFetcher()->fetchCollection($id);
		if (!$collection instanceof CollectionData) {
			return $this->outputError($input, $output, "Collection '{$id}' not found. Use collection:create to add it.");
		}

		$settings = [];
		$file     = $input->getArgument('file');
		if (is_string($file) && $file !== '') {
			$settings = $this->readJsonObject($input, $output, $file, 'Input is a JSON array — collection:update takes one object of settings.');
			if ($settings === null) {
				return Command::FAILURE;
			}
		}
		foreach ((array)$input->getOption('set') as $assignment) {
			if (!str_contains((string)$assignment, '=') || str_starts_with((string)$assignment, '=')) {
				return $this->outputError($input, $output, "--set takes key=value, got '{$assignment}'.");
			}
			[$path, $value] = explode('=', (string)$assignment, 2);
			$settings       = self::assign($settings, explode('.', $path), self::parseValue($value));
		}
		if ($settings === []) {
			return $this->outputError($input, $output, 'Nothing to update: pass --set key=value, or JSON from a file or "-" for stdin.');
		}

		$container  = $this->totalcms->container();
		$toolsError = $container->get(McpToolsValidator::class)->settingsError($id, $settings, (string)($collection->mcp['access'] ?? 'admin'));
		if ($toolsError !== null) {
			return $this->outputError($input, $output, "Collection update failed: {$toolsError}");
		}

		try {
			$saved = $container->get(CollectionSettingsUpdater::class)->update($collection, $settings);
		} catch (\Throwable $e) {
			return $this->outputError($input, $output, "Collection update failed: {$e->getMessage()}");
		}

		if ($this->isJson($input)) {
			return $this->outputData($input, $output, $saved->toArray());
		}

		$output->writeln(sprintf("<info>Updated '%s': %s</info>", $id, implode(', ', array_keys($settings))));

		return Command::SUCCESS;
	}

	/**
	 * $settings with $value at $path: `mcp.access` becomes ["mcp" => ["access" => …]],
	 * merged into what is already there so several --set options combine.
	 *
	 * @param array<mixed> $settings
	 * @param list<string> $path
	 *
	 * @return array<mixed>
	 */
	private static function assign(array $settings, array $path, mixed $value): array
	{
		$key = array_shift($path);
		if ($path === []) {
			$settings[$key] = $value;

			return $settings;
		}
		$settings[$key] = self::assign(is_array($settings[$key] ?? null) ? $settings[$key] : [], $path, $value);

		return $settings;
	}

	/**
	 * A --set value: JSON when it parses (true, 5, null, a list), a string
	 * otherwise, so --set sortBy=date needs no quoting.
	 */
	private static function parseValue(string $value): mixed
	{
		$decoded = json_decode($value, true);

		return json_last_error() === JSON_ERROR_NONE ? $decoded : $value;
	}
}
