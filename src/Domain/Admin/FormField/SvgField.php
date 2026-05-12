<?php

declare(strict_types=1);

namespace TotalCMS\Domain\Admin\FormField;

class SvgField extends TextareaField
{
	protected string $defaultFieldType = 'svg';
	protected string $defaultInputType = 'textarea';
}
