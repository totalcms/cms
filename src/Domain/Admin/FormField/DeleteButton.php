<?php

namespace TotalCMS\Domain\Admin\FormField;

use TotalCMS\Utils\HTMLUtils;

final class DeleteButton
{
	public function __construct(
		private string $label = 'Delete',
	) {
	}

	public function build(): string
	{
		$attributes = [
			'class' => 'cms-delete button btn',
		];

		return HTMLUtils::createHTMLElement('button', $this->label, $attributes);
	}
}
