<?php

declare(strict_types=1);

namespace TotalCMS\Domain\Admin\FormField;

use TotalCMS\Domain\Rendering\Utilities\HTMLUtils;

/**
 * The markdown source field. StyledmarkdownField is the same markup with the
 * visual editor: the JavaScript field class decides which editor mounts.
 */
class MarkdownField extends TextareaField
{
	protected string $defaultFieldType = 'markdown';
	protected string $defaultInputType = 'textarea';
	/** An editor replaces the textarea and sizes itself. */
	protected bool $sizesToContent = false;

	public function buildFormField(): string
	{
		$attributes = $this->formFieldAttributes();

		// Markdown is stored unsanitized, so the value is escaped here: a
		// literal </textarea> would otherwise end the field and run as markup.
		// The browser decodes it again, so the editor reads the original text.
		// The leading newline is for the HTML parser, which drops one straight
		// after <textarea>: without it a value that begins with a newline
		// would lose it.
		$value    = "\n" . htmlspecialchars((string)$this->value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
		$textarea = HTMLUtils::element('textarea', $value, $attributes);

		// styledtext-wrapper carries the editor layout the fields share.
		return HTMLUtils::element('div', $textarea, ['class' => 'styledtext-wrapper markdown-wrapper']);
	}
}
