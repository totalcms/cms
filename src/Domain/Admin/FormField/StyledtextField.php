<?php

namespace TotalCMS\Domain\Admin\FormField;

use TotalCMS\Utils\HTMLUtils;

final class StyledtextField extends TextareaField
{
	protected string $defaultFieldType = 'styledtext';
	protected string $defaultInputType = 'textarea';

	public function buildFormField(): string
	{
		$attributes = $this->formFieldAttributes();
		$textarea = HTMLUtils::createHTMLElement('textarea', (string)$this->value, $attributes);

		return HTMLUtils::createHTMLElement('div', $textarea, ['class' => 'styledtext-wrapper']);
	}
}
