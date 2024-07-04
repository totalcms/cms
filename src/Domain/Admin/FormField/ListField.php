<?php

namespace TotalCMS\Domain\Admin\FormField;

final class ListField extends FormField
{
	protected string $defaultInputType = 'multiselect';
	protected string $defaultFieldType = 'list';
}
