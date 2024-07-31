<?php

namespace TotalCMS\Domain\Admin\FormField;

class JsonField extends TextareaField
{
	protected string $defaultFieldType = 'json';
	protected string $defaultInputType = 'textarea';
}
