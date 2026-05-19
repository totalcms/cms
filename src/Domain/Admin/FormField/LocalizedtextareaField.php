<?php

declare(strict_types=1);

namespace TotalCMS\Domain\Admin\FormField;

use TotalCMS\Domain\Rendering\Utilities\HTMLUtils;

/**
 * Admin form field for plain-text localized content rendered as a `<textarea>`.
 *
 * Same per-locale tab structure as LocalizedtextField but each pane hosts a
 * multi-line textarea instead of a single-line input. Stored type and value
 * shape are identical to localizedtext — the only difference is admin UI.
 *
 * Pro edition is required transitively because this field lives only in
 * custom schemas (gated by EditionFeature::CUSTOM_SCHEMAS).
 */
class LocalizedtextareaField extends LocalizedtextField
{
	protected string $defaultFieldType = 'localizedtextarea';
	protected string $defaultInputType = 'textarea';

	protected function buildLocaleInput(string $code, string $dir, string $id, string $value): string
	{
		$attributes = [
			'id'           => $id,
			'data-locale'  => $code,
			'dir'          => $dir,
			'rows'         => $this->rows > 0 ? (string)$this->rows : '4',
			'placeholder'  => $this->placeholder === '' ? null : $this->placeholder,
			'autocomplete' => 'off',
		];
		$attributes = array_filter($attributes, static fn (?string $x): bool => $x !== null);

		return HTMLUtils::element('textarea', $value, $attributes);
	}
}
