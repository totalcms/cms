<?php

declare(strict_types=1);

namespace TotalCMS\Domain\Admin\FormField;

class JsonField extends TextareaField
{
	protected string $defaultFieldType = 'json';
	protected string $defaultInputType = 'textarea';

	public function init(): void
	{
		parent::init();

		if (!is_string($this->value)) {
			$this->value = json_encode($this->value, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
		}
	}
}
