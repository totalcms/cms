<?php

declare(strict_types=1);

namespace TotalCMS\Domain\Admin\FormField;

use TotalCMS\Domain\Rendering\Utilities\HTMLUtils;

class StyledtextField extends TextareaField
{
	protected string $defaultFieldType = 'styledtext';
	protected string $defaultInputType = 'textarea';

	public function buildFormField(): string
	{
		$attributes = $this->formFieldAttributes();
		$textarea   = HTMLUtils::element('textarea', (string)$this->value, $attributes);

		return HTMLUtils::element('div', $textarea, ['class' => 'styledtext-wrapper']);
	}
}
