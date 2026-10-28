<?php

namespace TotalCMS\Domain\Admin\FormField;

use TotalCMS\Domain\Property\Data\DateData;
use TotalCMS\Domain\Rendering\Utilities\HTMLUtils;

class DateField extends FormField
{
	protected string $defaultInputType = 'date';
	protected string $defaultFieldType = 'date';
	protected string $dateFormat       = 'Y-m-d';

	public function init(): void
	{
		parent::init();

		if ((isset($this->settings[DateData::CREATION_DATE]) && $this->settings[DateData::CREATION_DATE] === true)
			 || (isset($this->settings[DateData::UPDATE_DATE]) && $this->settings[DateData::UPDATE_DATE] === true)) {
			$this->readonly = true;
		}

		// Handle smart defaults for date fields
		if (!empty($this->default) && ($this->value === null || $this->value === '')) {
			$this->value = DateData::cleanDate($this->default, $this->dateFormat);
		}

		// Process existing value with smart parsing
		if (!empty($this->value)) {
			$this->value = DateData::cleanDate($this->value, $this->dateFormat);
		}
	}

	/**
	 * Safari and Chrome give a native date input no way to empty it once set,
	 * so an editable, optional date gets a button that clears it. CSS shows it
	 * only while the field holds a date. It goes last in the group, as the
	 * select's clear button does, so the input stays next to its icon.
	 */
	public function createFormGroup(string $content): string
	{
		if (!$this->clearable()) {
			return parent::createFormGroup($content);
		}

		$label = $this->t('date.clear', 'Clear date');

		// No text glyph: the icon is a CSS mask, the label is for assistive tech.
		return parent::createFormGroup($content . HTMLUtils::element('button', '', [
			'type'       => 'button',
			'class'      => 'date-clear',
			'title'      => $label,
			'aria-label' => $label,
		]));
	}

	/**
	 * @param array<string,string> $extraStyles
	 * @param list<string>         $extraClasses
	 *
	 * @return array<string,string>
	 */
	protected function buildFieldAttributes(array $extraStyles = [], array $extraClasses = []): array
	{
		if ($this->clearable() && empty($this->value)) {
			$extraClasses[] = 'date-empty';
		}

		return parent::buildFieldAttributes($extraStyles, $extraClasses);
	}

	private function clearable(): bool
	{
		return !$this->readonly && !$this->required;
	}
}
