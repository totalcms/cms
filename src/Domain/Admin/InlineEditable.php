<?php

declare(strict_types=1);

namespace TotalCMS\Domain\Admin;

/**
 * Which field types may be edited straight from the collection table.
 *
 * The rule: a field whose whole editor is one input (or one Tiptap) that
 * TotalForm can boot inside a swapped fragment, and whose value the object
 * form would submit as a plain scalar or list. Identity fields are out
 * because changing an id is a rename, not an edit. Secrets are out because
 * a table is the wrong place to reveal one. Composites (images, files,
 * decks, cards) carry uploads or nested forms that need the full page.
 * Localized fields are out only until the locale switcher works inside a
 * fragment. styledtext is in on purpose — it is the field most worth
 * proving inside a swap before live-site editing depends on it.
 */
final class InlineEditable
{
	/** @var list<string> */
	public const TYPES = [
		'text', 'textarea', 'number', 'range', 'price',
		'toggle', 'checkbox', 'select', 'radio', 'multiselect', 'checklist',
		'date', 'datetime', 'time', 'url', 'email', 'phone', 'color', 'list',
		'styledtext',
	];

	public static function supports(string $fieldType): bool
	{
		return in_array(TotalForm::canonicalFieldType($fieldType), self::TYPES, true);
	}

	/**
	 * Whether a resolved property (its schema entry, or the merged meta from
	 * PropertyMetaResolver) may be edited inline: a supported field type that
	 * is neither readonly nor disabled. `updated` and `created` are the usual
	 * readonly ones — system-managed timestamps the object form hides.
	 *
	 * @param array<string,mixed> $property
	 */
	public static function allows(array $property): bool
	{
		$settings = is_array($property['settings'] ?? null) ? $property['settings'] : [];
		if (!empty($settings['readonly']) || !empty($settings['disabled'])) {
			return false;
		}

		return self::supports((string)($property['field'] ?? ''));
	}
}
