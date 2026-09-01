<?php

declare(strict_types=1);

namespace TotalCMS\Domain\Mcp\Tool\Admin;

use Mcp\Exception\ToolCallException;
use Mcp\Schema\ToolAnnotations;
use TotalCMS\Domain\Builder\Service\BuilderTemplatePaths;
use TotalCMS\Domain\Mcp\Tool\Data\McpToolDefinition;
use TotalCMS\Domain\Mcp\Tool\Data\ToolRequirement;
use TotalCMS\Domain\Mcp\Tool\Service\ToolRegistry;
use TotalCMS\Domain\Template\Data\TemplatePath;
use TotalCMS\Domain\Template\Repository\TemplateRepository;
use TotalCMS\Domain\Template\Service\TemplateFetcher;
use TotalCMS\Domain\Template\Service\TemplateLister;

/**
 * Admin tool family for reading Site Builder templates.
 *
 * Registers two READ-ONLY tools (`list_templates`, `get_template`). There is
 * deliberately no save tool yet: writing Twig is a materially larger authority
 * grant than writing content (a template reaches the whole `cms.*` surface),
 * and it wants its own decision rather than arriving as a side effect of adding
 * read access.
 *
 * The point of read-only access is that agents editing `builder-pages` objects
 * are currently working blind — a page's free-form `data` field surfaces in the
 * template as `page.data.*`, and without reading the template an agent can only
 * guess which keys it consumes. Reading turns that guesswork into "read the
 * template, then write matching keys."
 *
 * **Builder templates only.** `TemplateFetcher::fetchTemplate()` falls back to
 * the reserved admin templates, which are core internals — noise for an agent
 * and mild information disclosure. Both tools here go through the builder-only
 * paths so reserved templates never surface.
 *
 * **Why the responses carry `git_managed` / `layer`.** A template can resolve
 * from any of three read layers (project-root → tcms-data → shipped defaults),
 * and when the project is git-managed `BuilderTemplatePaths::locked()` makes
 * templates read-only everywhere — the repo is the only write path. Stating
 * that in the read response means an agent learns the shape from a successful
 * call instead of inferring it from a missing tool. An agent that has to guess
 * why it can't write invents workarounds; one that is told goes and edits the
 * right place.
 */
readonly class TemplateTools
{
	public function __construct(
		private TemplateLister $lister,
		private TemplateFetcher $fetcher,
		private BuilderTemplatePaths $paths,
	) {
	}

	public function register(ToolRegistry $registry): void
	{
		$registry->register(new McpToolDefinition(
			name: 'list_templates',
			description: 'List the Site Builder Twig templates on this site. Returns builder template paths (e.g. "layouts/base", "pages/home", "partials/nav") — reserved admin templates are never listed. Templates are READ-ONLY via MCP: there is no save tool, so use this and get_template to understand how pages render, then edit the files in the Site Builder admin UI or in the git repository. The response reports which of those two applies via git_managed/edit_via.',
			access: 'admin',
			handler: $this->listHandler(...),
			inputSchema: [
				'type'                 => 'object',
				'additionalProperties' => false,
				'properties'           => [
					'folder' => [
						'type'        => 'string',
						'description' => 'Optional folder to limit the listing to. Builder templates are conventionally organised into layouts, pages, partials, and macros. Omit to list everything.',
						'examples'    => ['pages', 'partials'],
					],
					'recursive' => [
						'type'        => 'boolean',
						'description' => 'Walk nested folders (default true). Nested results come back as slash-separated paths that get_template accepts verbatim.',
					],
				],
			],
			annotations: new ToolAnnotations(
				title: 'List Templates',
				readOnlyHint: true,
				destructiveHint: false,
				idempotentHint: true,
				openWorldHint: false,
			),
			requires: new ToolRequirement(domain: 'builder', operation: 'read'),
		));

		$registry->register(new McpToolDefinition(
			name: 'get_template',
			description: 'Fetch the raw Twig source of one Site Builder template. Required: path (as returned by list_templates, e.g. "pages/home"). Returns the template source plus the read layer it resolved from. Use this before editing a builder-pages object: the page\'s free-form `data` field is exposed to the template as `page.data.*`, so the template source is what tells you which keys it actually consumes. Templates cannot be written via MCP — see list_templates.',
			access: 'admin',
			handler: $this->getHandler(...),
			inputSchema: [
				'type'                 => 'object',
				'required'             => ['path'],
				'additionalProperties' => false,
				'properties'           => [
					'path' => [
						'type'        => 'string',
						'description' => 'Builder-relative template path WITHOUT the .twig extension, exactly as list_templates returns it.',
						'examples'    => ['pages/home', 'layouts/base', 'partials/nav'],
					],
				],
			],
			annotations: new ToolAnnotations(
				title: 'Get Template',
				readOnlyHint: true,
				destructiveHint: false,
				idempotentHint: true,
				openWorldHint: false,
			),
			requires: new ToolRequirement(domain: 'builder', operation: 'read'),
		));
	}

	/**
	 * @SuppressWarnings("PHPMD.BooleanArgumentFlag")
	 *
	 * @return array<string,mixed>
	 */
	public function listHandler(?string $folder = null, bool $recursive = true): array
	{
		if ($folder !== null && $folder !== '') {
			$this->assertSafePath($folder, 'folder');
		}

		$templates = $this->lister->listBuilderTemplates($folder, $recursive);

		return [
			'templates' => array_values($templates),
			'total'     => count($templates),
			...$this->managementState(),
		];
	}

	/**
	 * @return array<string,mixed>
	 */
	public function getHandler(string $path): array
	{
		$this->assertSafePath($path, 'path');

		[$folder, $name] = TemplatePath::parse($path);

		$template = $this->fetcher->fetchBuilderTemplate($name, $folder);

		if (!$template instanceof \TotalCMS\Domain\Template\Data\TemplateData) {
			throw new ToolCallException(sprintf(
				'get_template: template "%s" not found. Use list_templates to see available templates. Paths are builder-relative and carry no .twig extension.',
				$path,
			));
		}

		$resolved = $this->paths->resolveRead($path . TemplateRepository::FILE_EXT);

		return [
			...$template->toArray(),
			'path'  => $path,
			// Which read layer won: 'project' (git-managed source of truth),
			// 'data' (admin-edited, in tcms-data) or 'built-in' (shipped
			// default). Tells the agent where the file it just read lives.
			'layer' => $resolved['layer'] ?? null,
			...$this->managementState(),
		];
	}

	/**
	 * Where templates are edited on this install. Attached to every response
	 * so the read-only-ness of the MCP surface is explained rather than left
	 * to be inferred.
	 *
	 * @return array<string,mixed>
	 */
	private function managementState(): array
	{
		$gitManaged = $this->paths->isProjectManaged();

		return [
			'git_managed' => $gitManaged,
			'edit_via'    => $gitManaged
				? 'git — templates are source-controlled in the project builder/ directory and are read-only to the admin UI and MCP alike'
				: 'the Site Builder admin UI — templates are not writable via MCP',
		];
	}

	/**
	 * Reject traversal outright rather than silently rewriting it. The
	 * repository sanitizes defensively, but an agent that gets a stripped
	 * path back and a confusing "not found" learns nothing; an explicit
	 * error tells it the input was wrong. Slashes are legal — nested
	 * template paths are the normal shape.
	 */
	private function assertSafePath(string $value, string $argName): void
	{
		$invalid = str_contains($value, '..')
			|| str_contains($value, '\\')
			|| str_contains($value, "\0")
			|| str_starts_with($value, '/');

		if ($invalid) {
			throw new ToolCallException(sprintf(
				'get_template/list_templates: %s "%s" is not a valid builder-relative path. Use forward-slash paths without a leading slash and without "..", exactly as list_templates returns them.',
				$argName,
				$value,
			));
		}
	}
}
