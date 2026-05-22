<?php

declare(strict_types=1);

namespace TotalCMS\Domain\Mcp\Resource\Service;

use TotalCMS\Domain\Mcp\Data\McpPersona;
use TotalCMS\Domain\Mcp\Resource\Data\McpResourceDefinition;
use TotalCMS\Domain\Mcp\Resource\Data\McpResourceTemplateDefinition;

/**
 * In-memory registry of MCP resources and resource templates.
 *
 * Mirrors ToolRegistry's role for tools: built up at container construction by
 * per-collection and per-view registrars and by extension hooks, then read by
 * McpServerFactory to build the SDK's persona-filtered resource surface.
 *
 * Concrete resources (`tcms://blog/`) and templates (`tcms://blog/{id}`) are
 * stored in separate maps because the SDK exposes them via different APIs
 * (addResource vs addResourceTemplate) and consumers (resources/list vs
 * resources/templates/list) ask for them separately.
 *
 * URI uniqueness is enforced per map. Extension code wanting to override a core
 * registration must call unregister or unregisterTemplate first.
 */
class ResourceRegistry
{
	/** @var array<string, McpResourceDefinition> */
	private array $resources = [];

	/** @var array<string, McpResourceTemplateDefinition> */
	private array $templates = [];

	public function register(McpResourceDefinition $resource): void
	{
		if (isset($this->resources[$resource->uri])) {
			throw new \LogicException(\sprintf('MCP resource "%s" is already registered.', $resource->uri));
		}

		$this->resources[$resource->uri] = $resource;
	}

	public function registerTemplate(McpResourceTemplateDefinition $template): void
	{
		if (isset($this->templates[$template->uriTemplate])) {
			throw new \LogicException(\sprintf('MCP resource template "%s" is already registered.', $template->uriTemplate));
		}

		$this->templates[$template->uriTemplate] = $template;
	}

	public function unregister(string $uri): void
	{
		unset($this->resources[$uri]);
	}

	public function unregisterTemplate(string $uriTemplate): void
	{
		unset($this->templates[$uriTemplate]);
	}

	public function get(string $uri): ?McpResourceDefinition
	{
		return $this->resources[$uri] ?? null;
	}

	public function getTemplate(string $uriTemplate): ?McpResourceTemplateDefinition
	{
		return $this->templates[$uriTemplate] ?? null;
	}

	/**
	 * @return list<McpResourceDefinition>
	 */
	public function all(): array
	{
		return array_values($this->resources);
	}

	/**
	 * @return list<McpResourceTemplateDefinition>
	 */
	public function allTemplates(): array
	{
		return array_values($this->templates);
	}

	/**
	 * Resources visible to the given persona, ordered by URI for stable
	 * resources/list output.
	 *
	 * @return list<McpResourceDefinition>
	 */
	public function forPersona(McpPersona $persona): array
	{
		$visible = array_values(array_filter(
			$this->resources,
			static fn (McpResourceDefinition $r): bool => $r->isVisibleTo($persona),
		));

		usort($visible, static fn (McpResourceDefinition $a, McpResourceDefinition $b): int => strcmp($a->uri, $b->uri));

		return $visible;
	}

	/**
	 * Templates visible to the given persona, ordered by uriTemplate for stable
	 * resources/templates/list output.
	 *
	 * @return list<McpResourceTemplateDefinition>
	 */
	public function templatesForPersona(McpPersona $persona): array
	{
		$visible = array_values(array_filter(
			$this->templates,
			static fn (McpResourceTemplateDefinition $t): bool => $t->isVisibleTo($persona),
		));

		usort($visible, static fn (McpResourceTemplateDefinition $a, McpResourceTemplateDefinition $b): int => strcmp($a->uriTemplate, $b->uriTemplate));

		return $visible;
	}
}
