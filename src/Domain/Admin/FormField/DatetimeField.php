<?php

namespace TotalCMS\Domain\Admin\FormField;

final class DatetimeField extends DateField
{
	protected string $defaultInputType = 'datetime-local';
	protected string $defaultFieldType = 'datetime';
	protected string $dateFormat = 'Y-m-d\\TH:i';
}
