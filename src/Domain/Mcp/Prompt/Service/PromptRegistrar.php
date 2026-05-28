<?php

declare(strict_types=1);

namespace TotalCMS\Domain\Mcp\Prompt\Service;

use Mcp\Server\Builder;
use TotalCMS\Domain\Mcp\Auth\Data\McpPersona;
use TotalCMS\Domain\Mcp\Prompt\Data\PromptData;

final readonly class PromptRegistrar
{
	public function __construct(
		private PromptRenderer $renderer,
	) {
	}

	/**
	 * Register all prompts on the MCP SDK Builder for the given persona.
	 *
	 * Each handler closure captures the persona and prompt at registration time
	 * and re-checks access at call time — a caller who guesses an admin-only
	 * prompt name via prompts/get gets a clean MCP error rather than rendered
	 * content.
	 *
	 * @param list<PromptData> $prompts
	 */
	public function registerAll(Builder $builder, array $prompts, McpPersona $persona): void
	{
		foreach ($prompts as $prompt) {
			$builder->addPrompt(
				handler: $this->buildHandler($prompt, $persona),
				name: $prompt->name,
				description: $prompt->description,
			);
		}
	}

	/**
	 * Build a closure whose named parameters match the prompt's declared args.
	 *
	 * The MCP SDK's ReferenceHandler uses reflection to map JSON-RPC arguments
	 * to handler parameters BY NAME — a closure with a single `array $arguments`
	 * parameter receives an empty array because no incoming key matches the
	 * parameter name. Generating the closure via eval() with the right parameter
	 * names is how SavedQueryToolFactory solves the same problem for MCP tools.
	 *
	 * Safety: arg names are validated against `^[a-z][a-z0-9_]*$` by the schema
	 * (mcp-prompt-arg.json) and re-normalised by ObjectFactory's snakeCase
	 * pipeline, so the eval input is bounded to safe identifiers.
	 */
	private function buildHandler(PromptData $prompt, McpPersona $persona): \Closure
	{
		$renderer = $this->renderer;

		$paramSrc = [];
		$argMap   = [];
		foreach ($prompt->args as $arg) {
			// All args land as nullable mixed; the renderer enforces required-ness.
			$paramSrc[] = "mixed \${$arg->name} = null";
			$argMap[]   = "'{$arg->name}' => \${$arg->name}";
		}

		$paramList   = implode(', ', $paramSrc);
		$argMapSrc   = implode(', ', $argMap);

		// Re-check access at call time so a caller who guesses an admin-only
		// prompt name via prompts/get gets a clean MCP error rather than
		// rendered content. Keeps named args so the SDK's reflection-based
		// dispatch fills them.
		$src = sprintf(
			'return function (%s) use ($renderer, $prompt, $persona): array {
				if (!\\TotalCMS\\Domain\\Mcp\\Prompt\\Service\\PromptRegistrar::personaCanAccess($persona, $prompt->access)) {
					throw new \\Mcp\\Exception\\PromptGetException(sprintf(
						\'Prompt "%%s" requires %%s access.\',
						$prompt->name,
						$prompt->access,
					));
				}
				$args = array_filter([%s], static fn ($v) => $v !== null);
				return $renderer->render($prompt, $args);
			};',
			$paramList,
			$argMapSrc,
		);

		/** @var \Closure */
		$closure = eval($src);

		return $closure;
	}

	/**
	 * Returns true when $persona is allowed to call a prompt with $access level.
	 * Fails closed: unrecognised access values are treated as admin-only.
	 */
	public static function personaCanAccess(McpPersona $persona, string $access): bool
	{
		return match ($access) {
			'public'        => true,
			'authenticated' => $persona !== McpPersona::PUBLIC_,
			'admin'         => $persona === McpPersona::ADMIN,
			default         => $persona === McpPersona::ADMIN,
		};
	}
}
