<?php

namespace TotalCMS\Domain\Admin\FormField;

use TotalCMS\Domain\Rendering\Utilities\HTMLUtils;

class CodeField extends TextareaField
{
	protected string $defaultFieldType = 'text';
	protected string $defaultInputType = 'code';

	/** @return array<string,string> */
	protected function formFieldAttributes(): array
	{
		// Get base textarea attributes
		$attributes = parent::formFieldAttributes();

		// Add code-specific attributes
		$attributes['data-field-type'] = 'code';

		// Add language mode if specified in settings
		if (isset($this->settings['mode'])) {
			$attributes['data-mode'] = $this->settings['mode'];
		} else {
			$attributes['data-mode'] = 'twig'; // Default to Twig mode
		}

		// Add theme if specified in settings
		if (isset($this->settings['theme'])) {
			$attributes['data-theme'] = $this->settings['theme'];
		} else {
			$attributes['data-theme'] = 'elegant'; // Default theme
		}

		// Code editor specific options
		$editorOptions = [
			'autofocus'     => $this->settings['autofocus'] ?? false,
			'lineNumbers'   => $this->settings['lineNumbers'] ?? true,
			'lineWrapping'  => $this->settings['lineWrapping'] ?? true,
			'indentUnit'    => $this->settings['indentUnit'] ?? 2,
			'tabSize'       => $this->settings['tabSize'] ?? 2,
			'foldGutter'    => $this->settings['foldGutter'] ?? true,
			'matchBrackets' => $this->settings['matchBrackets'] ?? true,
			'autoCloseTags' => $this->settings['autoCloseTags'] ?? true,
		];

		$attributes['data-editor-options'] = json_encode($editorOptions);

		return $attributes;
	}

	public function buildFormField(): string
	{
		$attributes = $this->formFieldAttributes();

		// Add the code editor class for styling
		$attributes['class'] = 'code-editor-field';

		return HTMLUtils::element('textarea', strval($this->value), $attributes);
	}
}
