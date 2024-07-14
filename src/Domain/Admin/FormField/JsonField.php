<?php

namespace TotalCMS\Domain\Admin\FormField;

use TotalCMS\Utils\HTMLUtils;

class JsonField extends TextareaField
{
	protected string $defaultFieldType = 'json';
	protected string $defaultInputType = 'textarea';
}
