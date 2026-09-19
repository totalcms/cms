<?php

declare(strict_types=1);

namespace TotalCMS\Domain\Admin;

use TotalCMS\Domain\Rendering\Utilities\HTMLUtils;

/**
 * Builds the `.formgrid-accordion` group markup used by both the formgrid
 * `>> <<` parser (TotalForm::fieldContent) and the cms.form.accordion() Twig
 * helper, so the two authoring paths never diverge.
 *
 * Open and solo behaviour is expressed purely as data attributes on the group
 * wrapper — Details._localOptions() reads container.dataset and coerces
 * "true"/"false" to booleans, and these keys already match Details.defaults.
 * A group of one is a disclosure and renders closed; a group of two or more is
 * an accordion, so the first panel opens and the panels are linked.
 */
class AccordionRenderer
{
	/**
	 * @param list<array{title:string,inner:FormGridBuilder,members:string}> $panels
	 */
	public function render(array $panels, ?string $gridArea = null, string $extraClass = ''): string
	{
		if ($panels === []) {
			return '';
		}

		$linked = count($panels) > 1;
		$body   = '';

		foreach ($panels as $panel) {
			$gridId = 'panel-' . bin2hex(random_bytes(6));
			$body .= HTMLUtils::details(
				htmlspecialchars($panel['title'], ENT_QUOTES, 'UTF-8'),
				$panel['inner']->renderNestedLayout($panel['members'], $gridId),
				'formgrid-panel',
			);
		}

		$attrs = [
			'class'           => trim('formgrid-accordion ' . $extraClass),
			'data-solo-mode'  => $linked ? 'true' : 'false',
			'data-open-first' => $linked ? 'true' : 'false',
		];
		if ($gridArea !== null) {
			$attrs['style'] = "grid-area: {$gridArea};";
		}

		return HTMLUtils::element('div', $body, $attrs);
	}

	/**
	 * Twig-facing variant: build each panel's inner grid from a formgrid string.
	 *
	 * @param list<array{title?:string,content?:string,formgrid?:string}> $panels
	 */
	public function wrap(array $panels, string $extraClass = ''): string
	{
		$built = [];

		foreach ($panels as $panel) {
			$title   = trim((string)($panel['title'] ?? ''));
			$built[] = [
				'title'   => $title === '' ? 'Section ' . (count($built) + 1) : $title,
				'inner'   => new FormGridBuilder((string)($panel['formgrid'] ?? '')),
				'members' => (string)($panel['content'] ?? ''),
			];
		}

		return $this->render($built, null, $extraClass);
	}
}
