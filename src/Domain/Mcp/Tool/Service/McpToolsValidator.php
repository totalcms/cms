<?php

declare(strict_types=1);

namespace TotalCMS\Domain\Mcp\Tool\Service;

use Psr\Log\LoggerInterface;
use TotalCMS\Domain\Mcp\Tool\Data\McpToolsValidationResult;
use TotalCMS\Domain\Mcp\Tool\Data\SavedQueryToolDefinition;
use TotalCMS\Domain\Mcp\Tool\Exception\SavedQueryToolException;
use TotalCMS\Support\Config;

/**
 * Validates `mcp.tools` at collection save time.
 *
 * Two phases:
 *  1. Blocking validation — each entry is passed through
 *     SavedQueryToolDefinition::fromArray() which throws on malformed data.
 *     A failed entry produces a 400-worthy error message.  The 64-char limit
 *     is enforced on the BASE name (what customers write), but an additional
 *     prefix-inclusive length check ensures the REGISTERED name (prefix +
 *     base) never exceeds 64 characters when `mcp.toolPrefix` is set.
 *  2. Non-blocking collision check — each surviving tool's name is compared
 *     against the current ToolRegistry (core + extension tools already
 *     registered). Collisions are logged and surfaced as warning strings
 *     returned to the caller (not exceptions). Cross-collection collisions
 *     are detected at server-build time by SchemaToolRegistrar, not here.
 */
final readonly class McpToolsValidator
{
	private const MAX_REGISTERED_NAME_LEN = 64;

	public function __construct(
		private ToolRegistry $registry,
		private LoggerInterface $logger,
		private Config $config,
	) {
	}

	/**
	 * Resolve the active tool-name prefix (with trailing underscore) from config.
	 * Returns '' when unset or invalid — mirrors McpServerFactory::toolNamePrefix().
	 */
	private function resolvedPrefix(): string
	{
		$prefix = trim((string)($this->config->mcp['toolPrefix'] ?? ''));
		if ($prefix === '') {
			return '';
		}

		if (!preg_match('/^[a-z][a-z0-9_]{0,23}$/', $prefix)) {
			return '';
		}

		return $prefix . '_';
	}

	/**
	 * Validate and normalise `mcp.tools`.
	 *
	 * @param  string              $collectionId collection ID (used in validation messages)
	 * @param  string              $access       inherited access level ("admin"|"public")
	 * @param  mixed               $rawTools     value from the incoming request body — may be
	 *                                           a PHP array (from JSON body), a JSON string
	 *                                           (direct API caller), null, or ""
	 *
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

		// Two accepted shapes:
		//   - Keyed object (post-migration): {"featured": {...}, "drafts": {...}}
		//     The key IS the canonical id; we inject it into the entry so the
		//     deck-item form can leave the inner `id` field optional/empty.
		//   - List array (legacy, pre-migration): [{"id": "featured", ...}]
		//     Accepted during the McpToolsArrayToObjectMigration rollout window
		//     so concurrent admin submits don't fail mid-upgrade. Drop one
		//     release after the migration ships.
		$isLegacyArray = array_is_list($rawTools);

		$index = 0;
		foreach ($rawTools as $key => $entry) {
			if (!is_array($entry)) {
				return McpToolsValidationResult::validationFailed(
					$index,
					'each tool definition must be a JSON object',
				);
			}

			if (!$isLegacyArray && is_string($key)) {
				// Deck key is authoritative — overwrite whatever the entry's own
				// `id` field says. Keeps the canonical lookup stable even if the
				// operator edits the inner id-form without re-keying.
				$entry['id'] = $key;
			}

			try {
				$definition = SavedQueryToolDefinition::fromArray($collectionId, $access, $entry);
			} catch (SavedQueryToolException $e) {
				return McpToolsValidationResult::validationFailed($index, $e->getMessage());
			}

			// Blocking: prefix-inclusive registered-name length check.
			// The JSON Schema + fromArray() cap the BASE name at 64 chars, but
			// the registered name is prefix + base. Enforce the true ceiling here
			// so a customer with a long prefix gets a clear save-time error.
			$prefix           = $this->resolvedPrefix();
			$registeredName   = $prefix . $definition->name;
			$registeredLength = strlen($registeredName);
			if ($registeredLength > self::MAX_REGISTERED_NAME_LEN) {
				$prefixLabel = $prefix !== '' ? " (prefix '{$prefix}' uses " . strlen($prefix) . ' chars)' : '';

				return McpToolsValidationResult::validationFailed(
					$index,
					"Tool name '{$definition->name}' results in a registered name '{$registeredName}' of {$registeredLength} characters{$prefixLabel}; the limit is " . self::MAX_REGISTERED_NAME_LEN . ' (including prefix).',
				);
			}

			// Non-blocking: check if this name collides with a core/extension tool.
			if (isset($registeredNames[$definition->name])) {
				$this->logger->warning('save-time schema tool collision with core/extension tool', [
					'collection' => $collectionId,
					'tool'       => $definition->name,
					'index'      => $index,
				]);

				$warnings[] = [
					'type'   => 'collision',
					'name'   => $definition->name,
					'source' => 'core/extension',
				];
			}

			// Non-blocking: warn when a filter value contains {{...}} patterns that
			// don't match the supported {{params.X}} placeholder syntax. Customers
			// sometimes write {{this_month}} expecting substitution — surface a
			// clear warning at save time rather than silently passing literals.
			foreach ($definition->filters as $field => $spec) {
				$value = $spec['value'] ?? null;
				if (!is_string($value)) {
					continue;
				}

				// Find every {{...}} occurrence in the value.
				preg_match_all('/\{\{([^}]+)\}\}/', $value, $matches);
				foreach ($matches[1] as $inner) {
					// Supported syntax: params.<identifier>
					if (preg_match('/^params\.[a-z][a-z0-9_]*$/', $inner)) {
						continue;
					}

					// Unrecognized — log + warn.
					$this->logger->warning('save-time schema tool filter has unrecognized placeholder', [
						'collection'  => $collectionId,
						'tool'        => $definition->name,
						'field'       => $field,
						'placeholder' => $inner,
					]);

					$warnings[] = [
						'type'    => 'placeholder',
						'tool'    => $definition->name,
						'message' => "Filter field '{$field}' value contains '{{{{$inner}}}}' which looks like a placeholder but won't be substituted at runtime — only {{params.X}} is supported. Did you mean to declare a param?",
					];
				}
			}

			$index++;
		}

		return McpToolsValidationResult::ok($warnings);
	}
}
