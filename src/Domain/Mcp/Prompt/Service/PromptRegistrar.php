<?php

declare(strict_types=1);

namespace TotalCMS\Domain\Mcp\Prompt\Service;

use Mcp\Schema\Prompt;
use Mcp\Server\Builder;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use TotalCMS\Domain\Mcp\Auth\Data\McpPersona;
use TotalCMS\Domain\Mcp\Auth\Data\McpAccessLevel;
use TotalCMS\Domain\Mcp\Prompt\Data\PromptData;
use TotalCMS\Domain\Mcp\Prompt\Handler\ExtensionPromptHandler;

final readonly class PromptRegistrar
{
	public function __construct(
		// @phpstan-ignore property.onlyWritten
		private PromptRenderer $renderer,
		private LoggerInterface $logger = new NullLogger(),
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
	 * Register the prompts extensions declared through
	 * ExtensionContext::registerMcpPrompt(). They use the SDK's Prompt type
	 * directly (not PromptData). Collision policy: soft deny — a name already
	 * taken by a collection-stored prompt is logged and skipped, and the SDK's
	 * own duplicate error is caught the same way rather than crashing the
	 * build. The persona filter matches collection-stored prompts, and the
	 * handler re-checks access at call time so a lower-privilege caller who
	 * guesses a name via prompts/get is denied.
	 *
	 * @param array<string,list<array{prompt: Prompt, handler: callable, access: string}>> $byExtension
	 * @param list<PromptData>                                                             $collectionPrompts
	 */
	public function registerExtensionPrompts(Builder $builder, array $byExtension, array $collectionPrompts, McpPersona $persona): void
	{
		$reserved = array_flip(array_map(static fn (PromptData $p): string => $p->name, $collectionPrompts));

		foreach ($byExtension as $extensionId => $registrations) {
			foreach ($registrations as $reg) {
				$name   = $reg['prompt']->name;
				$access = $reg['access'];

				if (!self::personaCanAccess($persona, $access)) {
					continue;
				}

				if (isset($reserved[$name])) {
					$this->logger->warning('Extension MCP prompt skipped: name collides with collection-stored prompt', [
						'extension' => $extensionId,
						'prompt'    => $name,
					]);
					continue;
				}

				try {
					// Builder::add() (not addPrompt()) so the declared Prompt object
					// IS the advertised schema. addPrompt() reflects the handler,
					// which recovers parameter names only — argument descriptions
					// and required flags are lost, and clients render an unlabelled
					// optional box for a described, required argument.
					$builder->add($reg['prompt'], new ExtensionPromptHandler($reg['handler'], $persona, $name, $access));
				} catch (\LogicException $e) {
					$this->logger->warning('Extension MCP prompt registration failed', [
						'extension' => $extensionId,
						'prompt'    => $name,
						'error'     => $e->getMessage(),
					]);
				}
			}
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
	 *
	 * @SuppressWarnings("PHPMD.EvalExpression")
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
		return McpAccessLevel::fromString($access)->allows($persona);
	}
}
