<?php

namespace TotalCMS\Domain\Admin\FormField;

final class IdField extends FormField
{
	protected string $defaultInputType = 'text';
	protected string $defaultFieldType = 'id';

	public function init(): void
	{
		if (!empty($this->value)) {
			$this->readonly = true;
		}
	}
}
