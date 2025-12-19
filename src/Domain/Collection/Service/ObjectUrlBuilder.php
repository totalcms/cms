<?php

namespace TotalCMS\Domain\Collection\Service;

use TotalCMS\Domain\Collection\Data\CollectionData;
use TotalCMS\Domain\Schema\Service\SchemaFetcher;

/**
 * Builds object URLs with support for Twig-like template syntax.
 *
 * Allows collection URLs to contain templates like:
 * /campsites/{{ region }}/{{ county | slug | lower }}/{{ id }}
 *
 * Supports filters: slug, lower, upper, trim
 */
readonly class ObjectUrlBuilder
{
	public function __construct(
		private SchemaFetcher $schemaFetcher,
	) {
	}

	/**
	 * Build the URL for an object, supporting template syntax.
	 *
	 * Template URLs require prettyUrl to be enabled. If prettyUrl is disabled,
	 * the template syntax is ignored and query string format (?id=xxx) is used.
	 *
	 * @param CollectionData $collectionData The collection configuration
	 * @param array<string,mixed> $object The object data (must include 'id')
	 *
	 * @return string The rendered URL
	 */
	public function buildUrl(CollectionData $collectionData, array $object): string
	{
		$url = $collectionData->url;

		if ($url === '') {
			return '';
		}

		$id = (string)($object['id'] ?? '');

		// If prettyUrl is disabled, always use query string format (ignore template syntax)
		if (!$collectionData->prettyUrl) {
			return sprintf('%s?id=%s', $url, $id);
		}

		// If no template syntax, use simple pretty URL format
		if (!$this->isTemplateUrl($url)) {
			$url = rtrim($url, '/');

			return sprintf('%s/%s', $url, $id);
		}

		// Auto-append {{ id }} if not present in template
		if (!$this->containsIdTemplate($url)) {
			$url = rtrim($url, '/') . '/{{ id }}';
		}

		// Render the template with object data using lightweight renderer
		return $this->renderUrlTemplate($url, $object);
	}

	/**
	 * Render a URL template with object data.
	 * Supports basic Twig-like syntax: {{ field }} and {{ field | filter | filter }}
	 * All values are automatically slugified for URL safety unless 'raw' filter is used.
	 * Available filters: lower, upper, trim, raw (skip auto-slugify).
	 *
	 * @param string $template The URL template
	 * @param array<string,mixed> $data The object data
	 *
	 * @return string The rendered URL
	 */
	private function renderUrlTemplate(string $template, array $data): string
	{
		// Match {{ field }} or {{ field | filter | filter }}
		return (string)preg_replace_callback(
			'/\{\{\s*(\w+)(\s*\|[^}]*)?\s*\}\}/',
			function (array $matches) use ($data): string {
				$field   = $matches[1];
				$filters = isset($matches[2]) ? trim($matches[2]) : '';

				// Get the value from data
				$value = $data[$field] ?? '';

				// Convert to string
				$value = is_array($value) ? '' : (string)$value;

				// Check for 'raw' filter which skips auto-slugify
				$skipSlugify = str_contains($filters, 'raw');

				// Apply explicit filters if present
				if ($filters !== '') {
					$value = $this->applyFilters($value, $filters);
				}

				// Always slugify for URL safety (unless raw filter was used)
				if (!$skipSlugify) {
					$value = $this->slugify($value);
				}

				return $value;
			},
			$template
		);
	}

	/**
	 * Apply Twig-like filters to a value.
	 * Note: 'raw' filter is handled separately and skips auto-slugify.
	 *
	 * @param string $value The value to filter
	 * @param string $filterString Filters like "| lower | trim"
	 *
	 * @return string The filtered value
	 */
	private function applyFilters(string $value, string $filterString): string
	{
		// Parse filters: "| lower | trim" -> ['lower', 'trim']
		$filters = array_filter(array_map(trim(...), explode('|', $filterString)));

		foreach ($filters as $filter) {
			$value = match ($filter) {
				'lower' => strtolower($value),
				'upper' => strtoupper($value),
				'trim'  => trim($value),
				'raw'   => $value, // No-op, handled separately for skip-slugify flag
				default => $value,
			};
		}

		return $value;
	}

	/**
	 * Convert a string to a URL-friendly slug.
	 */
	private function slugify(string $text): string
	{
		// Convert to lowercase
		$text = strtolower($text);

		// Replace non-alphanumeric characters with hyphens
		$text = (string)preg_replace('/[^a-z0-9]+/', '-', $text);

		// Remove leading/trailing hyphens
		return trim($text, '-');
	}

	/**
	 * Check if a URL string contains Twig template syntax.
	 */
	public function isTemplateUrl(string $url): bool
	{
		return str_contains($url, '{{');
	}

	/**
	 * Check if a rendered URL has empty segments (indicates missing data).
	 *
	 * Empty segments appear as // in the URL path.
	 */
	public function hasEmptySegments(string $url): bool
	{
		// Match consecutive slashes (empty segment)
		return (bool)preg_match('#//+#', $url);
	}

	/**
	 * Extract field names from a Twig template string.
	 *
	 * @param string $template The Twig template string
	 *
	 * @return array<string> Field names found in template
	 */
	public function extractTemplateFields(string $template): array
	{
		$fields = [];

		// Match {{ fieldName }} or {{ fieldName | filter | filter }}
		if (preg_match_all('/\{\{\s*(\w+)(?:\s*\|[^}]*)?\s*\}\}/', $template, $matches)) {
			$fields = array_unique($matches[1]);
		}

		return array_values($fields);
	}

	/**
	 * Validate a URL template against schema index and required fields.
	 *
	 * Returns information about fields that may cause issues:
	 * - notIndexed: Fields not in schema index (won't be available in sitemap/RSS)
	 * - notRequired: Fields not marked as required (may be empty, causing broken URLs)
	 *
	 * @param string $urlTemplate The URL template string
	 * @param string $schemaId The schema ID to check against
	 *
	 * @return array{notIndexed: array<string>, notRequired: array<string>}
	 */
	public function validateTemplateFields(string $urlTemplate, string $schemaId): array
	{
		$result = [
			'notIndexed'  => [],
			'notRequired' => [],
		];

		$templateFields = $this->extractTemplateFields($urlTemplate);

		try {
			$schema = $this->schemaFetcher->fetchSchema($schemaId);
		} catch (\Exception) {
			// Schema not found, can't validate
			return $result;
		}

		// 'id' is always available and required
		$templateFields = array_filter($templateFields, fn (string $f): bool => $f !== 'id');

		foreach ($templateFields as $field) {
			if (!in_array($field, $schema->index, true)) {
				$result['notIndexed'][] = $field;
			}
			if (!in_array($field, $schema->required, true)) {
				$result['notRequired'][] = $field;
			}
		}

		return $result;
	}

	/**
	 * Check if URL template contains an id reference.
	 */
	private function containsIdTemplate(string $url): bool
	{
		// Check for {{ id }} with optional whitespace
		return (bool)preg_match('/\{\{\s*id\s*(?:\|[^}]*)?\}\}/', $url);
	}
}
