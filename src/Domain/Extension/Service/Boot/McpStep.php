<?php

declare(strict_types=1);

namespace TotalCMS\Domain\Extension\Service\Boot;

use TotalCMS\Domain\Extension\Service\ExtensionManager;
use TotalCMS\Domain\Extension\Service\McpExtensionRegistrar;
use TotalCMS\Domain\Mcp\Resource\Service\ResourceRegistry;
use TotalCMS\Domain\Mcp\Tool\Service\ToolRegistry;

/**
 * Wire MCP tools and resources from extensions into their respective
 * registries (strict-deny on collisions, both core-vs-extension and
 * cross-extension). Runs before Twig wiring so registries are ready
 * by the time the first /mcp request lands — boot is the latest safe
 * moment since extensions populate their contexts during register().
 */
final readonly class McpStep extends BootStep
{
	public function wire(ExtensionManager $manager): void
	{
		if (!$this->container->has(ToolRegistry::class)) {
			return;
		}
		/** @var ToolRegistry $toolRegistry */
		$toolRegistry  = $this->container->get(ToolRegistry::class);
		$mcpRegistrar  = new McpExtensionRegistrar($this->logger);
		$mcpRegistrar->register($toolRegistry, $manager->getAllMcpTools());

		// Only request the ResourceRegistry singleton when extensions have
		// resources to add. Resolving the registry eagerly would trigger
		// its CollectionResourceRegistrar pass over every collection on
		// disk, which (a) is wasted work when no extension contributes
		// resources, and (b) snapshots the collection list at boot time —
		// any collection created later in the same process (e.g. in a
		// test's beforeEach) wouldn't appear in the registry.
		$extensionResources = $manager->getAllMcpResources();
		$extensionTemplates = $manager->getAllMcpResourceTemplates();
		if (
			($extensionResources !== [] || $extensionTemplates !== [])
			&& $this->container->has(ResourceRegistry::class)
		) {
			/** @var ResourceRegistry $resourceRegistry */
			$resourceRegistry = $this->container->get(ResourceRegistry::class);
			$mcpRegistrar->registerResources($resourceRegistry, $extensionResources);
			$mcpRegistrar->registerResourceTemplates($resourceRegistry, $extensionTemplates);
		}
	}
}
