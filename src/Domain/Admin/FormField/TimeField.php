<?php

declare(strict_types=1);

namespace TotalCMS\Domain\Admin\FormField;

class TimeField extends FormField
{
	protected string $defaultFieldType = 'time';
	protected string $defaultInputType = 'time';
}
