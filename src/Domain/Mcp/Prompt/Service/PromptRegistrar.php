<?php

declare(strict_types=1);

namespace TotalCMS\Domain\Mcp\Prompt\Service;

use Mcp\Exception\PromptGetException;
use Mcp\Schema\Result\GetPromptResult;
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
			$renderer = $this->renderer;
			$builder->addPrompt(
				handler: static function (array $arguments = []) use ($renderer, $prompt, $persona): GetPromptResult {
					if (!self::personaCanAccess($persona, $prompt->access)) {
						throw new PromptGetException(sprintf(
							'Prompt "%s" requires %s access.',
							$prompt->name,
							$prompt->access,
						));
					}
					return $renderer->render($prompt, $arguments);
				},
				name:        $prompt->name,
				description: $prompt->description,
			);
		}
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
