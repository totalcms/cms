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

		// Pass minHeight and maxHeight as data attributes
		if (isset($this->settings['minHeight'])) {
			$attributes['data-min-height'] = (string)$this->settings['minHeight'];
		}
		if (isset($this->settings['maxHeight'])) {
			$attributes['data-max-height'] = (string)$this->settings['maxHeight'];
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
			'fullscreen'    => $this->settings['fullscreen'] ?? true,
		];

		$attributes['data-editor-options'] = json_encode($editorOptions);

		return $attributes;
	}

	public function buildFormField(): string
	{
		$attributes = $this->formFieldAttributes();

		// Add the code editor class for styling
		$attributes['class'] = 'code-editor-field';

		// Escape content to prevent </textarea> in template code from breaking the HTML
		return HTMLUtils::element('textarea', htmlspecialchars(strval($this->value), ENT_NOQUOTES), $attributes);
	}
}
