<?php

declare(strict_types=1);

namespace TotalCMS\Domain\Admin\FormField;

class DatetimeField extends DateField
{
	protected string $defaultInputType = 'datetime-local';
	protected string $defaultFieldType = 'datetime';
	protected string $dateFormat       = 'Y-m-d\\TH:i';
}
