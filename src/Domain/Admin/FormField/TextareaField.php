<?php

namespace TotalCMS\Domain\Admin\FormField;

use TotalCMS\Domain\Admin\TotalForm;
use TotalCMS\Domain\Rendering\Utilities\HTMLUtils;

class TextareaField extends FormField
{
	protected string $defaultFieldType = 'text';
	protected string $defaultInputType = 'textarea';

	/**
	 * Whether the textarea sizes to its content (CSS `field-sizing: content`,
	 * floored at its rows). Off for fields that hand their textarea to an
	 * editor, which sizes itself; `autoGrow: false` turns it off per field.
	 */
	protected bool $sizesToContent = true;

	/** @return array<string,string> */
	protected function formFieldAttributes(): array
	{
		if (isset($this->settings['rows'])) {
			$this->rows = $this->settings['rows'];
		}

		$attributes = [
			'id'               => "field-{$this->uuid}",
			'name'             => $this->name,
			'required'         => $this->required ? '' : null,
			'disabled'         => $this->disabled ? '' : null,
			'readonly'         => $this->readonly ? '' : null,
			'rows'             => $this->rows > 0 ? strval($this->rows) : '8',
			'placeholder'      => $this->placeholder === '' ? null : $this->placeholder,
			'autocomplete'     => 'off', // Stop 1Password Managers from filling in the field
			'aria-describedby' => $this->help === '' ? null : "help-{$this->uuid}",
		];

		if ($this->autosizes()) {
			// The stylesheet floors a content-sized textarea at its rows: it reads
			// the count from --rows, since CSS cannot read the rows attribute. An
			// operator's own style attribute is kept after it.
			$rows                        = $attributes['rows'];
			$extra                       = TotalForm::extraAttributes(is_array($this->settings['attributes'] ?? null) ? $this->settings['attributes'] : []);
			$attributes['data-autosize'] = '';
			$attributes['style']         = '--rows:' . $rows . (isset($extra['style']) && $extra['style'] !== '' ? ';' . $extra['style'] : '');
		}

		// Remove null values from the attributes array
		$attributes = array_filter($attributes, fn (?string $x): bool => !is_null($x));

		return $this->withExtraAttributes($attributes);
	}

	private function autosizes(): bool
	{
		return $this->sizesToContent && ($this->settings['autoGrow'] ?? true) !== false;
	}

	public function buildFormField(): string
	{
		$attributes = $this->formFieldAttributes();

		return HTMLUtils::element('textarea', strval($this->value), $attributes);
	}
}
