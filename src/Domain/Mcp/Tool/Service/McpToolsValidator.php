<?php

declare(strict_types=1);

namespace TotalCMS\Domain\Mcp\Tool\Service;

use Psr\Log\LoggerInterface;
use TotalCMS\Domain\Mcp\Tool\Data\McpToolsValidationResult;
use TotalCMS\Domain\Mcp\Tool\Data\SavedQueryToolDefinition;
use TotalCMS\Domain\Mcp\Tool\Exception\SavedQueryToolException;

/**
 * Validates `mcp.tools` at collection save time.
 *
 * Two phases:
 *  1. Blocking validation — each entry is passed through
 *     SavedQueryToolDefinition::fromArray() which throws on malformed data.
 *     A failed entry produces a 400-worthy error message.
 *  2. Non-blocking collision check — each surviving tool's name is compared
 *     against the current ToolRegistry (core + extension tools already
 *     registered). Collisions are logged and surfaced as warning strings
 *     returned to the caller (not exceptions). Cross-collection collisions
 *     are detected at server-build time by SchemaToolRegistrar, not here.
 */
final readonly class McpToolsValidator
{
	public function __construct(
		private ToolRegistry $registry,
		private LoggerInterface $logger,
	) {
	}

	/**
	 * Validate and normalise `mcp.tools`.
	 *
	 * @param  string              $collectionId Collection ID (used in validation messages).
	 * @param  string              $access       Inherited access level ("admin"|"public").
	 * @param  mixed               $rawTools     Value from the incoming request body — may be
	 *                                           a PHP array (from JSON body), a JSON string
	 *                                           (direct API caller), null, or "".
	 * @return McpToolsValidationResult
	 */
	public function validate(string $collectionId, string $access, mixed $rawTools): McpToolsValidationResult
	{
		// No tools key present or explicitly empty — nothing to do.
		if ($rawTools === null || $rawTools === '' || $rawTools === []) {
			return McpToolsValidationResult::ok([]);
		}

		// If the caller sent a raw JSON string (direct API use), decode it first.
		if (is_string($rawTools)) {
			$decoded = json_decode($rawTools, true);
			if (!is_array($decoded)) {
				return McpToolsValidationResult::invalidJson();
			}
			$rawTools = $decoded;
		}

		if (!is_array($rawTools)) {
			return McpToolsValidationResult::invalidJson();
		}

		// Each element must be a non-empty associative array (object, not scalar).
		$warnings = [];

		// Pre-build set of registered core/extension tool names for collision check.
		$registeredNames = [];
		foreach ($this->registry->all() as $tool) {
			$registeredNames[$tool->name] = true;
		}

		foreach ($rawTools as $index => $entry) {
			if (!is_array($entry)) {
				return McpToolsValidationResult::validationFailed(
					(int)$index,
					'each tool definition must be a JSON object',
				);
			}

			try {
				$definition = SavedQueryToolDefinition::fromArray($collectionId, $access, $entry);
			} catch (SavedQueryToolException $e) {
				return McpToolsValidationResult::validationFailed((int)$index, $e->getMessage());
			}

			// Non-blocking: check if this name collides with a core/extension tool.
			if (isset($registeredNames[$definition->name])) {
				$this->logger->warning('save-time schema tool collision with core/extension tool', [
					'collection' => $collectionId,
					'tool'       => $definition->name,
					'index'      => $index,
				]);

				$warnings[] = [
					'name'   => $definition->name,
					'source' => 'core/extension',
				];
			}
		}

		return McpToolsValidationResult::ok($warnings);
	}
}
