<?php

declare(strict_types=1);

namespace TotalCMS\Domain\Admin\FormField;

use TotalCMS\Domain\Rendering\Utilities\HTMLUtils;

/**
 * Admin form field for the `localizedstyledtext` property type.
 *
 * Same tab-per-locale structure as LocalizedtextField, but each pane hosts a
 * Tiptap-ready `<textarea>` wrapped in `styledtext-wrapper` (mirroring
 * `StyledtextField`'s output). The JS field class instantiates one Tiptap
 * editor per locale and nudges the editor for the active tab when locales
 * are switched so layout settles correctly.
 *
 * Pro edition only — gated at the schema-builder layer.
 */
class LocalizedstyledtextField extends LocalizedtextField
{
	protected string $defaultFieldType = 'localizedstyledtext';
	protected string $defaultInputType = 'textarea';

	protected function buildLocaleInput(string $code, string $dir, string $id, string $value): string
	{
		$attributes = [
			'id'           => $id,
			'data-locale'  => $code,
			'dir'          => $dir,
			'rows'         => $this->rows > 0 ? (string)$this->rows : '8',
			'placeholder'  => $this->placeholder === '' ? null : $this->placeholder,
			'autocomplete' => 'off',
		];
		$attributes = array_filter($attributes, static fn ($x): bool => $x !== null);

		$textarea = HTMLUtils::element('textarea', $value, $attributes);

		return HTMLUtils::element('div', $textarea, ['class' => 'styledtext-wrapper']);
	}
}
