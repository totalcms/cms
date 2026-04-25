<?php

declare(strict_types=1);

namespace TotalCMS\Domain\Admin\FormField;

class IdField extends FormField
{
	protected string $defaultInputType = 'text';
	protected string $defaultFieldType = 'id';

	public function init(): void
	{
		parent::init();

		if ($this->name === 'id') {
			$this->required = true;
		}

		// Only set readonly if we have a value AND we're not in duplicate mode or template form
		// In duplicate mode, user needs to edit the ID before saving
		// Template forms support rename/move, so the ID must remain editable
		if (!empty($this->value) && !$this->form->isDuplicate && $this->form->getFormType() !== 'template') {
			$this->readonly = true;
		}
	}

	/** @return array<string,?string> */
	protected function formFieldAttributes(): array
	{
		$attributes                   = parent::formFieldAttributes();
		$attributes['autocapitalize'] = 'off';

		return $attributes;
	}
}
