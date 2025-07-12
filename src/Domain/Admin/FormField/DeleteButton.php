<?php

namespace TotalCMS\Domain\Admin\FormField;

use TotalCMS\Domain\Rendering\Utilities\HTMLUtils;

final class DeleteButton
{
	public function __construct(
		private string $label = 'Delete',
	) {
	}

	public function build(): string
	{
		return HTMLUtils::button($this->label, ['class' => 'cms-delete']);
	}
}
