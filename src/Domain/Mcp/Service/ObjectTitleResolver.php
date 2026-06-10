<?php

declare(strict_types=1);

namespace TotalCMS\Domain\Mcp\Service;

/**
 * Derives a single human-readable title for an MCP object, used by the
 * ChatGPT-compat `search`/`fetch` tools (ChatGPT renders this as the clickable
 * source label).
 *
 * T3 schemas have no designated title property, so resolution is:
 *   1. An explicit collection-level `mcp.titleProperty`, when it names a
 *      property carrying a non-empty scalar on this object.
 *   2. Convention fallback — first non-empty scalar among CONVENTION_KEYS.
 *   3. The object id (slug) as a last resort.
 *
 * Pure function of (object, titleProperty): the object is already
 * persona-filtered by the caller, so a titleProperty pointing at a non-exposed
 * (hence absent) property naturally falls through to convention.
 */
final readonly class ObjectTitleResolver
{
	private const CONVENTION_KEYS = ['title', 'name', 'label', 'heading', 'headline'];
	private const MAX_LENGTH      = 200;

	/**
	 * @param array<string,mixed> $object
	 */
	public function resolve(array $object, string $titleProperty = ''): string
	{
		$candidate = $this->valueAt($object, $titleProperty);

		if ($candidate === '') {
			foreach (self::CONVENTION_KEYS as $key) {
				$candidate = $this->valueAt($object, $key);
				if ($candidate !== '') {
					break;
				}
			}
		}

		if ($candidate === '') {
			$candidate = isset($object['id']) && is_scalar($object['id']) ? (string)$object['id'] : '';
		}

		return $this->normalize($candidate);
	}

	/**
	 * @param array<string,mixed> $object
	 */
	private function valueAt(array $object, string $key): string
	{
		if ($key === '' || !array_key_exists($key, $object) || !is_scalar($object[$key])) {
			return '';
		}

		$trimmed = trim((string)$object[$key]);

		return $trimmed === '' ? '' : $trimmed;
	}

	private function normalize(string $value): string
	{
		$value = strip_tags($value);
		// Titles may originate from rendered HTML content, so decode any residual entities.
		$value = html_entity_decode($value, ENT_QUOTES | ENT_HTML5, 'UTF-8');
		$value = (string)preg_replace('/\s+/', ' ', $value);
		$value = trim($value);

		return mb_substr($value, 0, self::MAX_LENGTH);
	}
}
