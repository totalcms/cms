<?php

namespace TotalCMS\Domain\Admin\FormField;

use TotalCMS\Utils\HTMLUtils;

final class SaveButton
{
	public function __construct(
		private string $label = 'Save',
	) {
	}

	public function build(): string
	{
		$attributes = [
			'class' => "cms-save button btn",
			'type'  => "submit",
		];

		return HTMLUtils::createHTMLElement('button', $this->label, $attributes);
	}
}
