<?php

declare(strict_types=1);

namespace TotalCMS\Domain\Admin\FormField;

use TotalCMS\Domain\Rendering\Utilities\HTMLUtils;

class StyledmarkdownField extends TextareaField
{
	protected string $defaultFieldType = 'styledmarkdown';
	protected string $defaultInputType = 'textarea';
	/** An editor replaces the textarea and sizes itself. */
	protected bool $sizesToContent = false;

	public function buildFormField(): string
	{
		$attributes = $this->formFieldAttributes();

		// Markdown is stored unsanitized, so the value is escaped here: a
		// literal </textarea> would otherwise end the field and run as markup.
		// The browser decodes it again, so the editor reads the original text.
		$value    = htmlspecialchars((string)$this->value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
		$textarea = HTMLUtils::element('textarea', $value, $attributes);

		// styledtext-wrapper carries the editor layout the two fields share.
		return HTMLUtils::element('div', $textarea, ['class' => 'styledtext-wrapper markdown-wrapper']);
	}
}
