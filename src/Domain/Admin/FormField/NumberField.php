<?php

declare(strict_types=1);

namespace TotalCMS\Domain\Admin\FormField;

class NumberField extends FormField
{
	protected string $defaultInputType = 'number';
	protected string $defaultFieldType = 'number';
}
