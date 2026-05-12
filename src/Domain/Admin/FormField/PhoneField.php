<?php

declare(strict_types=1);

namespace TotalCMS\Domain\Admin\FormField;

class PhoneField extends FormField
{
	protected string $defaultInputType = 'tel';
	protected string $defaultFieldType = 'phone';
}
