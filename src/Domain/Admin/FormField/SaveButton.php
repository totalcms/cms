<?php

namespace TotalCMS\Domain\Admin\FormField;

use TotalCMS\Domain\Rendering\Utilities\HTMLUtils;

readonly class SaveButton
{
	public function __construct(
		private string $label = 'Save',
	) {
	}

	public function build(): string
	{
		return HTMLUtils::button($this->label, [
			'class' => 'cms-save',
			'type'  => 'submit',
		]);
	}
}
