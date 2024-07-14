<?php

namespace TotalCMS\Domain\Admin\FormField;

final class PhoneField extends FormField
{
	protected string $defaultInputType = 'tel';
	protected string $defaultFieldType = 'phone';
}
