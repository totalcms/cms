<?php

namespace TotalCMS\Domain\Rendering\Utilities;

/**
 * Canonical ${placeholder} template substitution used across T3.
 *
 * Used by autogen, deckItemLabel, calc field expressions, and relationalOptions
 * format. Owns the regex so consumers cannot drift apart on syntax.
 *
 * Each consumer supplies its own resolver callback to handle special tokens
 * (${oid-00000}, ${uuid}, etc.) and value coercion (string, numeric, slug).
 */
class TemplatePlaceholder
{
	/** Matches ${anything-without-a-closing-brace}. */
	public const PATTERN = '/\$\{([^}]+)\}/';

	/**
	 * Extract the unique placeholder keys from a template, in order of first appearance.
	 *
	 * @return array<int,string>
	 */
	public static function extractKeys(string $template): array
	{
		preg_match_all(self::PATTERN, $template, $matches);

		return array_values(array_unique($matches[1]));
	}

	/**
	 * Render a template by passing each placeholder key to the resolver.
	 *
	 * The resolver receives the raw key (without the ${}) and returns the
	 * replacement value. Returning anything castable to string is fine.
	 *
	 * @param callable(string):(string|int|float) $resolver
	 */
	public static function render(string $template, callable $resolver): string
	{
		return (string)preg_replace_callback(
			self::PATTERN,
			fn (array $matches): string => (string)$resolver($matches[1]),
			$template,
		);
	}

	/**
	 * Resolve a (possibly dotted) placeholder key against a nested data array
	 * and coerce it to a display string.
	 *
	 * Dot notation walks into nested values, so one rule covers every composite
	 * field a deck item can hold:
	 *   - `text.es`         localizedtext → locale value
	 *   - `card.title`      card → sub-field value
	 *   - `card.headline.es` card → localizedtext sub-field → locale value
	 *   - `id`, `type`, …   plain top-level keys (no dot)
	 *
	 * A key that resolves to a non-scalar (e.g. a bare `card`/`text`, or a path
	 * that lands on a nested object) returns '' rather than dumping the array —
	 * so labels never show `Array` / `[object Object]`. Mirror of the
	 * `resolveLabelKey()` walk in `javascript/totalform/deckItem.js`.
	 *
	 * @param array<string,mixed> $data
	 */
	public static function resolvePath(array $data, string $key): string
	{
		$segments = explode('.', $key);
		$value    = $data[array_shift($segments)] ?? '';

		foreach ($segments as $segment) {
			if (!is_array($value) || !array_key_exists($segment, $value)) {
				return '';
			}
			$value = $value[$segment];
		}

		return is_scalar($value) ? trim((string)$value) : '';
	}
}
