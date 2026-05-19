<?php

declare(strict_types=1);

namespace TotalCMS\Domain\Property\Data;

/**
 * Property data for both `localizedtext` and `localizedstyledtext` field types.
 *
 * Stores a dict keyed by mixed-case POSIX locale code (en_US, de, pt_BR) to
 * string value. Same storage shape across the plain-text and rich-text
 * field types — they differ only in admin UI (labeled inputs vs Tiptap) and
 * are routed to this single Data class by `PROPERTY_TYPE_TO_REF` pointing
 * both type names at the same property schema URL (so the reverse-lookup
 * in `PropertyDefinition::resolveType()` always returns `localizedtext`).
 *
 * Per-locale normalization is delegated to `StringData` — HTML sanitization,
 * empty-paragraph trim, and `textTransform` all behave identically to a
 * non-localized `text` / `styledtext` field, with the same `htmlclean`
 * precedence rules.
 *
 * Pro edition is required transitively because these fields are only
 * usable inside custom schemas, which are gated by
 * `EditionFeature::CUSTOM_SCHEMAS`.
 */
class LocalizedtextData extends PropertyData implements \Stringable
{
	/** @var array<string,string> Locale code => normalized text value. */
	public array $values = [];

	/**
	 * @param array<string,mixed>|string $raw
	 * @param array<string,mixed>        $settings
	 */
	public function __construct(array|string $raw = [], public array $settings = [])
	{
		if (is_string($raw)) {
			$raw = $raw !== '' ? (json_decode($raw, true) ?: []) : [];
		}

		if (!is_array($raw)) {
			return;
		}

		foreach ($raw as $locale => $value) {
			if (!is_string($locale) || $locale === '') {
				continue;
			}
			$text = is_scalar($value) ? (string)$value : '';

			// Delegate per-locale normalization to StringData so localized
			// fields get the same htmlclean / trim / textTransform behavior
			// as their non-localized counterparts. Field-level settings
			// (htmlclean, textTransform) flow through unchanged.
			$this->values[$locale] = (new StringData($text, $this->settings))->transform();
		}
	}

	/** @return array<string,string> */
	public function transform(): array
	{
		return $this->values;
	}

	/**
	 * Return the value at the field's `defaultLocale`, falling back to the first
	 * available value. The full lookup chain (region fall-up, region fall-down,
	 * case canonicalization) lives in `LocaleTwigAdapter::text()`.
	 */
	public function __toString(): string
	{
		$default = (string)($this->settings['defaultLocale'] ?? '');
		if ($default !== '' && isset($this->values[$default])) {
			return $this->values[$default];
		}

		return $this->values !== [] ? (string)reset($this->values) : '';
	}
}
