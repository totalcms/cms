<?php

declare(strict_types=1);

namespace TotalCMS\Domain\Admin\FormField;

/**
 * The markdown field edited in the Styled Text visual editor. Same markup as
 * MarkdownField; only the field type, and so the editor, differs.
 */
class StyledmarkdownField extends MarkdownField
{
	protected string $defaultFieldType = 'styledmarkdown';
}
