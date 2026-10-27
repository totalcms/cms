<?php

declare(strict_types=1);

namespace TotalCMS\Domain\Mcp\Tool\Admin;

use Mcp\Exception\ToolCallException;
use Mcp\Schema\ToolAnnotations;
use Monolog\Level;
use TotalCMS\Domain\Collection\Data\CollectionData;
use TotalCMS\Domain\Collection\Service\CollectionFetcher;
use TotalCMS\Domain\Collection\Service\CollectionSettingsUpdater;
use TotalCMS\Domain\Mcp\Auth\Data\McpPersona;
use TotalCMS\Domain\Mcp\Auth\Service\PersonaContext;
use TotalCMS\Domain\Mcp\Tool\Data\McpToolDefinition;
use TotalCMS\Domain\Mcp\Tool\Data\ToolRequirement;
use TotalCMS\Domain\Mcp\Tool\Service\McpToolsValidator;
use TotalCMS\Domain\Mcp\Tool\Service\ToolRegistry;
use TotalCMS\Domain\Schema\Data\SchemaData;
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

	public function __construct(
		private CollectionFetcher $fetcher,
		private CollectionSettingsUpdater $updater,
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
			description: 'Change a collection\'s settings. Required: id + settings. Send only the settings to change; others keep their values. These maps merge one key at a time, so send only the keys you change: properties, customProperties, formSettings, mcp, seo, sitemap, feed. A key set to null in one of them removes it. properties holds field overrides keyed by property name — label, help, placeholder, options and other field settings for this collection only, e.g. {"price": {"label": "Cost"}}; each field\'s override merges too, so {"price": {"help": null}} removes just that override and {"price": null} all of them. Every other setting (name, url, description, category, labelSingular, labelPlural, prettyUrl, sortBy, reverseSort, groups, publicOperations, ownerProperty, ownerCollection, accessCollection, schema) is replaced by the value sent. publicRegistration and registrationGroups: admin callers only. Returns the saved settings.',
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
		$toolsError = $validator->settingsError($id, $settings, (string)($collection->mcp['access'] ?? 'admin'));
		if ($toolsError !== null) {
			throw new ToolCallException('update_collection: ' . $toolsError);
		}

		try {
			return $this->updater->update($collection, $settings)->toArray();
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
}
