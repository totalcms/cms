<?php

declare(strict_types=1);

namespace TotalCMS\Domain\Extension\Service;

use Psr\Log\LoggerInterface;
use TotalCMS\Domain\Mcp\Resource\Data\McpResourceDefinition;
use TotalCMS\Domain\Mcp\Resource\Data\McpResourceTemplateDefinition;
use TotalCMS\Domain\Mcp\Resource\Service\ResourceRegistry;
use TotalCMS\Domain\Mcp\Tool\Data\McpToolDefinition;
use TotalCMS\Domain\Mcp\Tool\Service\ToolRegistry;

/**
 * Adds extension-registered MCP tools and resources to the core registries,
 * with strict collision detection.
 *
 * **Policy: strict deny on collisions** — both core-vs-extension and
 * extension-vs-extension, applied independently to tools, resources, and
 * resource templates. The plan considered last-wins for cross-extension
 * collisions (TwigExtensionRegistrar's pattern) but settled on strict deny
 * here because MCP tools and resources are a security surface: a rogue or
 * buggy extension shadowing `query_collection` (tool) or `tcms://blog/`
 * (resource) could silently exfiltrate data through what the agent thinks is
 * a familiar surface. Better to fail loudly and force the operator to resolve
 * the conflict.
 *
 * Companion to TwigExtensionRegistrar — same architectural role, tighter
 * conflict policy.
 */
final readonly class McpExtensionRegistrar
{
	public function __construct(
		private LoggerInterface $logger,
	) {
	}

	/**
	 * Register extension tools into the registry, applying collision policy.
	 *
	 * @param array<string,list<mixed>> $extensionTools  map of extensionId =>
	 *                                                   list of McpToolDefinition
	 *                                                   (non-McpToolDefinition
	 *                                                   entries are defensively
	 *                                                   skipped — bad extension
	 *                                                   shouldn't crash boot)
	 *
	 * @return array{registered: int, blocked: int}
	 */
	public function register(ToolRegistry $registry, array $extensionTools): array
	{
		$registered = 0;
		$blocked    = 0;

		foreach ($extensionTools as $extensionId => $tools) {
			foreach ($tools as $tool) {
				if (!$tool instanceof McpToolDefinition) {
					continue;
				}

				if ($registry->get($tool->name) instanceof McpToolDefinition) {
					$this->logger->warning(sprintf(
						"MCP tool '%s' from extension '%s' blocked: name already registered (core or another extension).",
						$tool->name,
						$extensionId,
					));
					$blocked++;
					continue;
				}

				$registry->register($tool);
				$registered++;
			}
		}

		return ['registered' => $registered, 'blocked' => $blocked];
	}

	/**
	 * Register extension resources into the ResourceRegistry. Strict deny on
	 * collision: a URI already taken by core or another extension is logged
	 * and skipped, never overridden.
	 *
	 * @param array<string,list<McpResourceDefinition>> $extensionResources
	 *
	 * @return array{registered: int, blocked: int}
	 */
	public function registerResources(ResourceRegistry $registry, array $extensionResources): array
	{
		$registered = 0;
		$blocked    = 0;

		foreach ($extensionResources as $extensionId => $resources) {
			foreach ($resources as $resource) {
				if ($registry->get($resource->uri) instanceof McpResourceDefinition) {
					$this->logger->warning(sprintf(
						"MCP resource '%s' from extension '%s' blocked: URI already registered (core or another extension).",
						$resource->uri,
						$extensionId,
					));
					$blocked++;
					continue;
				}

				$registry->register($resource);
				$registered++;
			}
		}

		return ['registered' => $registered, 'blocked' => $blocked];
	}

	/**
	 * Register extension resource templates. Same collision policy as
	 * concrete resources, against the template registry.
	 *
	 * @param array<string,list<McpResourceTemplateDefinition>> $extensionTemplates
	 *
	 * @return array{registered: int, blocked: int}
	 */
	public function registerResourceTemplates(ResourceRegistry $registry, array $extensionTemplates): array
	{
		$registered = 0;
		$blocked    = 0;

		foreach ($extensionTemplates as $extensionId => $templates) {
			foreach ($templates as $template) {
				if ($registry->getTemplate($template->uriTemplate) instanceof McpResourceTemplateDefinition) {
					$this->logger->warning(sprintf(
						"MCP resource template '%s' from extension '%s' blocked: template already registered (core or another extension).",
						$template->uriTemplate,
						$extensionId,
					));
					$blocked++;
					continue;
				}

				$registry->registerTemplate($template);
				$registered++;
			}
		}

		return ['registered' => $registered, 'blocked' => $blocked];
	}
}
