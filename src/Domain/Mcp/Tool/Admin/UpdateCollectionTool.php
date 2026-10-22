<?php

declare(strict_types=1);

namespace TotalCMS\Domain\Mcp\Tool\Admin;

use Mcp\Exception\ToolCallException;
use Mcp\Schema\ToolAnnotations;
use Monolog\Level;
use TotalCMS\Domain\Collection\Data\CollectionData;
use TotalCMS\Domain\Collection\Service\CollectionFetcher;
use TotalCMS\Domain\Collection\Service\CollectionSaver;
use TotalCMS\Domain\Mcp\Auth\Data\McpPersona;
use TotalCMS\Domain\Mcp\Auth\Service\PersonaContext;
use TotalCMS\Domain\Mcp\Tool\Data\McpToolDefinition;
use TotalCMS\Domain\Mcp\Tool\Data\ToolRequirement;
use TotalCMS\Domain\Mcp\Tool\Service\McpToolsValidator;
use TotalCMS\Domain\Mcp\Tool\Service\ToolRegistry;
use TotalCMS\Domain\Schema\Data\SchemaData;
use TotalCMS\Domain\Schema\Service\SchemaFetcher;
use TotalCMS\Factory\LogChannel;
use TotalCMS\Factory\LoggerFactory;
use TotalCMS\Support\Config;

/**
 * `update_collection`: change a collection's settings after
 * create_collection, field overrides included.
 *
 * Needs the collection-settings update grant (over OAuth, the cms:admin
 * scope), and a caller other than an admin reaches only collections MCP
 * exposes to it, as with content. {@see DeleteCollectionTool} is its sibling.
 */
final readonly class UpdateCollectionTool
{
	/** Settings only an admin may change: they open public sign-up. */
	private const REGISTRATION_KEYS = ['publicRegistration', 'registrationGroups'];

	/** Counters and timestamps the site keeps itself. */
	private const MANAGED_KEYS = ['count', 'totalObjects', 'lastUpdated', 'updated', 'format'];

	/** Settings maps an update merges into one key at a time. */
	private const MAP_KEYS = ['properties', 'customProperties', 'formSettings', 'mcp', 'seo', 'sitemap', 'feed'];

	public function __construct(
		private CollectionFetcher $fetcher,
		private CollectionSaver $saver,
		private SchemaFetcher $schemaFetcher,
		private PersonaContext $personaContext,
		private Config $config,
		private LoggerFactory $loggerFactory,
	) {
	}

	public function register(ToolRegistry $registry): void
	{
		// Built here, not injected: the validator checks names against the
		// registry, and the registry is what builds this class.
		$validator = new McpToolsValidator($registry, $this->loggerFactory->channelLogger(LogChannel::McpToolsValidator, Level::Debug), $this->config);

		$registry->register(new McpToolDefinition(
			name: 'update_collection',
			description: 'Change a collection\'s settings. Required: id + settings. Send only the settings to change; others keep their values. These maps merge one key at a time, so send only the keys you change: properties, customProperties, formSettings, mcp, seo, sitemap, feed. A key set to null in one of them removes it. properties holds field overrides keyed by property name — label, help, placeholder, options and other field settings for this collection only, e.g. {"price": {"label": "Cost"}}; each field\'s override merges too, so {"price": {"help": null}} removes just that override and {"price": null} all of them. Every other setting (name, url, description, category, labelSingular, labelPlural, prettyUrl, sortBy, reverseSort, groups, publicOperations, ownerProperty, ownerCollection, schema) is replaced by the value sent. publicRegistration and registrationGroups: admin callers only. Returns the saved settings.',
			access: 'admin',
			handler: fn (string $id, array $settings): array => $this->updateHandler($id, $settings, $validator),
			inputSchema: [
				'type'                 => 'object',
				'required'             => ['id', 'settings'],
				'additionalProperties' => false,
				'properties'           => [
					'id' => [
						'type'        => 'string',
						'description' => 'Id of an existing collection. Discover via list_collections.',
						'examples'    => ['blog', 'team'],
					],
					'settings' => [
						'type'        => 'object',
						'description' => 'The settings to change. Field overrides go under properties, keyed by property name.',
					],
				],
			],
			annotations: new ToolAnnotations(
				title: 'Update Collection',
				readOnlyHint: false,
				destructiveHint: false,
				idempotentHint: true,
				openWorldHint: false,
			),
			requires: new ToolRequirement(domain: 'collections-meta', operation: 'update', collectionArg: 'id'),
		));
	}

	/**
	 * @param array<string,mixed> $settings
	 *
	 * @return array<string,mixed>
	 */
	public function updateHandler(string $id, array $settings, McpToolsValidator $validator): array
	{
		$collection = $this->existing('update_collection', $id);

		if (in_array($id, SchemaData::SYSTEM_COLLECTIONS, true)) {
			throw new ToolCallException(sprintf('update_collection: "%s" is a system collection; change its settings in the admin.', $id));
		}
		if ($this->personaContext->current() !== McpPersona::ADMIN
			&& array_intersect(self::REGISTRATION_KEYS, array_keys($settings)) !== []) {
			throw new ToolCallException('Only admin callers may set publicRegistration or registrationGroups.');
		}
		if (isset($settings['id']) && $settings['id'] !== $id) {
			throw new ToolCallException('update_collection: a collection cannot be renamed. Create a new collection instead.');
		}
		$this->validateMcpTools($validator, $id, $settings, $collection);

		$stored = $collection->toArray();
		$patch  = array_diff_key($settings, array_flip([...self::MANAGED_KEYS, 'id']));
		foreach ($patch as $key => $value) {
			if (in_array($key, self::MAP_KEYS, true) && is_array($value) && !array_is_list($value)) {
				$patch[$key] = self::mergeMap(is_array($stored[$key] ?? null) ? $stored[$key] : [], $value, $key === 'properties');
			}
		}
		if (is_array($patch['properties'] ?? null)) {
			$patch['properties'] = $this->withFieldTypes($collection->schema, $patch['properties']);
		}

		try {
			return $this->saver->patchCollection($id, $patch)->toArray();
		} catch (\DomainException|\UnexpectedValueException $e) {
			throw new ToolCallException(sprintf('Could not update collection "%s": %s', $id, $e->getMessage()), $e->getCode(), $e);
		}
	}

	/**
	 * The collection, when it exists and the caller may reach it.
	 */
	private function existing(string $toolName, string $id): CollectionData
	{
		$collection = $this->fetcher->fetchCollection($id);
		if (!$collection instanceof CollectionData) {
			throw new ToolCallException(sprintf('%s: collection "%s" not found. Use list_collections to discover available collections.', $toolName, $id));
		}

		try {
			$this->personaContext->exposedCollection($id, 'access');
		} catch (ToolCallException $e) {
			throw new ToolCallException($toolName . ': ' . lcfirst($e->getMessage()), $e->getCode(), $e);
		}

		return $collection;
	}

	/**
	 * Refuse custom MCP tools (`mcp.tools`) the REST endpoints would refuse.
	 *
	 * @param array<string,mixed> $settings
	 */
	private function validateMcpTools(McpToolsValidator $validator, string $id, array $settings, CollectionData $collection): void
	{
		$mcp = $settings['mcp'] ?? null;
		if (!is_array($mcp) || !array_key_exists('tools', $mcp)) {
			return;
		}

		$access = (string)($mcp['access'] ?? $collection->mcp['access'] ?? 'admin');
		$result = $validator->validate($id, $access, $mcp['tools']);
		if ($result->isInvalidJson()) {
			throw new ToolCallException('update_collection: mcp.tools must be a list of tool definitions.');
		}
		if ($result->isValidationFailed()) {
			throw new ToolCallException(sprintf('update_collection: mcp.tools item %d is invalid: %s', $result->failedIndex + 1, $result->failedMessage));
		}
	}

	/**
	 * Field overrides with the `field` each one must carry (they validate as
	 * schema properties), taken from the schema where the agent left it out.
	 *
	 * @param array<mixed> $overrides
	 *
	 * @return array<mixed>
	 */
	private function withFieldTypes(string $schemaId, array $overrides): array
	{
		try {
			$properties = $this->schemaFetcher->fetchSchema($schemaId)->properties;
		} catch (\UnexpectedValueException|\DomainException) {
			return $overrides;
		}

		foreach ($overrides as $name => $override) {
			$field = $properties[$name]['field'] ?? null;
			if (is_array($override) && !isset($override['field']) && is_string($field)) {
				$overrides[$name] = ['field' => $field] + $override;
			}
		}

		return $overrides;
	}

	/**
	 * $edit laid over $stored one key at a time; a null removes the key.
	 * With $deep (field overrides), each field's override merges the same
	 * way, so changing a label keeps the field's other overrides.
	 *
	 * @param array<mixed> $stored
	 * @param array<mixed> $edit
	 *
	 * @return array<mixed>
	 */
	private static function mergeMap(array $stored, array $edit, bool $deep = false): array
	{
		foreach ($edit as $key => $value) {
			if ($value === null) {
				unset($stored[$key]);
			} elseif ($deep && is_array($value) && !array_is_list($value) && is_array($stored[$key] ?? null)) {
				$stored[$key] = self::mergeMap($stored[$key], $value);
			} else {
				$stored[$key] = $value;
			}
		}

		return $stored;
	}
}
