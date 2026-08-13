<?php

declare(strict_types=1);

namespace TotalCMS\Domain\Mcp\Tool\Service;

use Psr\Log\LoggerInterface;
use TotalCMS\Domain\Collection\Repository\CollectionRepository;
use TotalCMS\Domain\Mcp\Tool\Data\McpToolDefinition;
use TotalCMS\Domain\Mcp\Tool\Data\SavedQueryToolDefinition;
use TotalCMS\Domain\Mcp\Tool\Exception\SavedQueryToolException;

/**
 * Auto-registers schema-defined MCP tools into the ToolRegistry at
 * server-build time.
 *
 * Called once by McpServerFactory::build() before
 * ToolRegistry::forPersona() is consumed. Iterates every collection,
 * reads its `mcp.tools` array, validates each entry, runs collision
 * detection (vs core, extension, schema), and registers each surviving
 * tool via SavedQueryToolFactory.
 *
 * Failures (validation, collision) log a WARN and skip the offending
 * tool — never throw. The endpoint stays up regardless of how broken
 * individual tool definitions might be.
 */
final class SchemaToolRegistrar
{
	/** @var list<string> Tool names this registrar contributed in previous register() calls */
	private array $contributedNames = [];

	public function __construct(
		private readonly CollectionRepository $collectionRepository,
		private readonly SavedQueryToolFactory $factory,
		private readonly LoggerInterface $logger,
	) {
	}

	public function register(ToolRegistry $registry): void
	{
		// Re-scanning collections on each call is idempotent + picks up
		// mid-process data changes (tests that mutate mcp.tools mid-suite,
		// worker-mode setups that survive multiple requests, etc.).
		// Unregister what we contributed last time so re-scan starts clean
		// without producing "already registered" collision warnings.
		foreach ($this->contributedNames as $name) {
			$registry->unregister($name);
		}
		$this->contributedNames = [];

		// Pre-compute existing names for collision detection. Anything already in
		// the registry (core + extension) at this point wins; schema tools yield.
		$existingNames = array_map(
			static fn (McpToolDefinition $t): string => $t->name,
			$registry->all(),
		);

		// Track schema-tool names seen in this pass — for cross-collection deny.
		/** @var array<string,string> */
		$seenSchemaNames = [];

		foreach ($this->collectionRepository->listAllCollections() as $collection) {
			$mcp   = $collection->mcp;
			$tools = $mcp['tools'] ?? null;
			if (!is_array($tools) || $tools === []) {
				continue;
			}

			$access = (string)($mcp['access'] ?? 'admin');

			// Two accepted shapes (see McpToolsValidator::validate):
			//   - Keyed object (post-migration): key is the canonical id
			//   - List array (legacy): entry carries its own id field
			$isLegacyArray = array_is_list($tools);

			foreach ($tools as $key => $rawEntry) {
				if (!is_array($rawEntry)) {
					$this->logger->warning('Schema tool entry is not an array', [
						'collection' => $collection->id,
						'index'      => $key,
					]);
					continue;
				}

				if (!$isLegacyArray && is_string($key)) {
					$rawEntry['id'] = $key;
				}

				try {
					$definition = SavedQueryToolDefinition::fromArray($collection->id, $access, $rawEntry);
				} catch (SavedQueryToolException $e) {
					$this->logger->warning('Schema tool validation failed', [
						'collection' => $collection->id,
						'index'      => $key,
						'message'    => $e->getMessage(),
					]);
					continue;
				}

				// Core/extension collision — schema tool yields, core/extension wins.
				if (in_array($definition->name, $existingNames, true)) {
					$this->logger->warning('Schema tool name collides with core/extension tool — skipped', [
						'tool'       => $definition->name,
						'collection' => $collection->id,
					]);
					continue;
				}

				// Schema-vs-schema collision — strict deny: both yield.
				if (isset($seenSchemaNames[$definition->name])) {
					$this->logger->warning('Schema tool name collides across collections — both skipped', [
						'tool'         => $definition->name,
						'first_owner'  => $seenSchemaNames[$definition->name],
						'second_owner' => $collection->id,
					]);
					// Remove the first registration too — strict deny.
					$registry->unregister($definition->name);
					// Drop from our contributed list so subsequent register() calls
					// don't try to unregister something that's no longer there.
					$this->contributedNames = array_values(array_diff(
						$this->contributedNames,
						[$definition->name],
					));
					continue;
				}

				try {
					$tool = $this->factory->build($definition);
				} catch (\Throwable $e) {
					$this->logger->error('Schema tool build failed', [
						'tool'    => $definition->name,
						'message' => $e->getMessage(),
					]);
					continue;
				}

				try {
					$registry->register(new McpToolDefinition(
						name: $definition->name,
						description: $this->factory->descriptionFor($definition),
						access: $definition->access,
						handler: $this->factory->closureFor($definition, $tool),
						inputSchema: $this->factory->inputSchemaFor($definition),
						outputSchema: $this->factory->outputSchemaFor($definition),
						// Directory requirement: every tool carries a title.
						// Saved queries are index reads by construction, so the
						// read-only hints are safe to assert unconditionally.
						annotations: new \Mcp\Schema\ToolAnnotations(
							title: ucwords(str_replace(['_', '-'], ' ', $definition->name)),
							readOnlyHint: true,
							destructiveHint: false,
							idempotentHint: true,
							openWorldHint: false,
						),
					));
				} catch (\LogicException $e) {
					// Registry collision that slipped past our pre-check (race condition is
					// not realistic here, but defensive belt-and-suspenders).
					$this->logger->warning('Schema tool registry collision', [
						'tool'    => $definition->name,
						'message' => $e->getMessage(),
					]);
					continue;
				}

				$seenSchemaNames[$definition->name] = $collection->id;
				$this->contributedNames[]           = $definition->name;
			}
		}
	}
}
