<?php

declare(strict_types=1);

namespace TotalCMS\Domain\Admin;

use TotalCMS\Domain\Rendering\Utilities\HTMLUtils;

/**
 * Builds the shared `.form-grid-fieldset` markup used by both the formgrid
 * `[[ ]]` parser (TotalForm::fieldContent) and the cms.form.fieldset() Twig
 * helper, so the two authoring paths never diverge.
 */
class FieldsetRenderer
{
	/**
	 * Render a complete fieldset element with optional legend, scoped inner grid
	 * and nested member HTML.
	 *
	 * @param FormGridBuilder $inner the parsed interior grid (may be empty)
	 */
	public function render(?string $legend, string $membersHtml, FormGridBuilder $inner, string $extraClass = '', ?string $gridArea = null): string
	{
		$gridId = 'fieldset-' . bin2hex(random_bytes(6));

		$legendHtml = ($legend === null || $legend === '')
			? ''
			: HTMLUtils::element('legend', htmlspecialchars($legend, ENT_QUOTES, 'UTF-8'));

		$style = $inner->hasGrid() ? $inner->toNestedStyleTag($gridId) : '';

		$grid = HTMLUtils::element('div', $inner->buildGridSectionHtml() . $membersHtml, [
			'id'    => $gridId,
			'class' => 'formgrid',
		]);

		$attrs = ['class' => trim('form-grid-fieldset ' . $extraClass)];
		if ($gridArea !== null) {
			$attrs['style'] = "grid-area: {$gridArea};";
		}

		return HTMLUtils::element('fieldset', $legendHtml . $style . $grid, $attrs);
	}

	/**
	 * Twig-facing variant: build the inner grid from a formgrid string.
	 */
	public function wrap(?string $legend, string $membersHtml, string $innerFormgrid = '', string $extraClass = '', ?string $gridArea = null): string
	{
		return $this->render($legend, $membersHtml, new FormGridBuilder($innerFormgrid), $extraClass, $gridArea);
	}
}
