<?php

declare(strict_types=1);

namespace TotalCMS\Domain\Mcp\Prompt\Service;

use Mcp\Schema\Content\PromptMessage;
use Mcp\Schema\Content\TextContent;
use Mcp\Schema\Enum\Role;
use TotalCMS\Domain\Mcp\Prompt\Data\PromptData;
use TotalCMS\Domain\Mcp\Prompt\Exception\PromptRenderException;
use TotalCMS\Domain\Twig\Service\TwigEngine;

final readonly class PromptRenderer
{
	public function __construct(
		private TwigEngine $twig,
	) {
	}

	/**
	 * Renders a prompt body into an array of PromptMessage objects.
	 *
	 * The MCP SDK's GetPromptHandler wraps the returned array in a
	 * GetPromptResult itself, so the handler MUST return PromptMessage[]
	 * (or one PromptMessage) — not a pre-wrapped GetPromptResult.
	 *
	 * @param array<string,mixed> $arguments
	 * @return list<PromptMessage>
	 */
	public function render(PromptData $prompt, array $arguments): array
	{
		$args = $this->validateAndCoerce($prompt, $arguments);

		try {
			$rendered = $this->twig->renderString($prompt->body, ['args' => $args]);
		} catch (\Twig\Error\Error $e) {
			throw new PromptRenderException(
				sprintf('Twig error in prompt "%s": %s', $prompt->name, $e->getMessage()),
				0,
				$e,
			);
		}

		return [new PromptMessage(Role::User, new TextContent($rendered))];
	}

	/**
	 * Validate required args are present and drop any extras not declared.
	 *
	 * @param array<string,mixed> $arguments
	 * @return array<string,mixed>
	 */
	private function validateAndCoerce(PromptData $prompt, array $arguments): array
	{
		$declared = [];
		foreach ($prompt->args as $arg) {
			if ($arg->required && !array_key_exists($arg->name, $arguments)) {
				throw new PromptRenderException(sprintf(
					'Missing required argument "%s" for prompt "%s".',
					$arg->name,
					$prompt->name,
				));
			}
			if (array_key_exists($arg->name, $arguments)) {
				$declared[$arg->name] = $arguments[$arg->name];
			}
		}
		return $declared;
	}
}
