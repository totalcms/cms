<?php

declare(strict_types=1);

namespace TotalCMS\Domain\Mcp\Prompt\Handler;

use Mcp\Server\ClientGateway;
use Mcp\Server\Handler\PromptHandlerInterface;
use TotalCMS\Domain\Mcp\Auth\Data\McpPersona;
use TotalCMS\Domain\Mcp\Prompt\Service\PromptRegistrar;

/**
 * Runs an extension-registered MCP prompt.
 *
 * This exists so extension prompts can be registered through the SDK's
 * explicit-schema entry point, `Builder::add()`, rather than `addPrompt()`.
 * The difference is where the advertised argument schema comes from.
 *
 * `addPrompt()` derives a prompt's arguments by REFLECTING its handler, which
 * can only recover parameter names — argument descriptions and required flags
 * have nowhere to travel and are silently lost. Clients then render an
 * unlabelled, apparently-optional field for a required argument, with no hint
 * of what to type. `Builder::add()` takes the `\Mcp\Schema\Prompt` value
 * object as given, so whatever an extension declared — descriptions included —
 * is what `prompts/list` publishes.
 *
 * It also removes the need to generate a handler with named parameters:
 * explicitly-registered handlers receive the caller's raw argument bag, so the
 * documented extension contract (`fn (array $arguments = [])`) is satisfied
 * directly, with no eval() and no filtering of extension-supplied argument
 * names.
 *
 * Access is re-checked here, at call time. The persona filter in
 * McpServerFactory::build() already keeps an inaccessible prompt out of
 * `prompts/list`, but a caller who guesses a name and calls `prompts/get`
 * directly must get a clean error rather than rendered content.
 */
final readonly class ExtensionPromptHandler implements PromptHandlerInterface
{
	/** @param callable $handler The extension's own handler, taking the caller's arguments as an array. */
	public function __construct(
		private mixed $handler,
		private McpPersona $persona,
		private string $name,
		private string $access,
	) {
	}

	/**
	 * @param array<string,mixed> $arguments
	 */
	public function get(array $arguments, ClientGateway $gateway): mixed
	{
		if (!PromptRegistrar::personaCanAccess($this->persona, $this->access)) {
			throw new \Mcp\Exception\PromptGetException(sprintf(
				'Prompt "%s" requires %s access.',
				$this->name,
				$this->access,
			));
		}

		// Transport internals are not the extension's business.
		unset($arguments['_session'], $arguments['_request']);

		return ($this->handler)($arguments);
	}
}
