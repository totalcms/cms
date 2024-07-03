<?php

namespace TotalCMS\Domain\Admin\FormField;

final class IdField extends FormField
{
	protected string $defaultInputType = 'text';
	protected string $defaultFieldType = 'id';

	public function init(): void
	{
		if ($this->name === 'id') {
			$this->required = true;
		}

		if (!empty($this->value)) {
			$this->readonly = true;
		}
		// TODO: make sure that autogen works $this->settings['autogen'] = '{$title}';
	}
}
