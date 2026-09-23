<?php

namespace TotalCMS\Domain\Admin\Form\Layout;

use TotalCMS\Domain\Rendering\Utilities\HTMLUtils;

/**
 * Builds CSS Grid layouts and HTML sections from formgrid definitions.
 *
 * Handles the conversion of text-based grid layouts to CSS Grid properties
 * and generates section headers/dividers for visual organization.
 *
 * Block types:
 *   - ['type' => 'row',      'line'   => string]
 *   - ['type' => 'divider']
 *   - ['type' => 'header',   'title'  => string]
 *   - ['type' => 'fieldset', 'id'     => string, 'legend' => ?string, 'inner' => self]
 *   - ['type' => 'accordion', 'id'    => string, 'panels' => list<array{title: string, inner: self}>]
 */
class FormGridBuilder
{
	private const DIVIDER         = '---';
	private const FIELDSET_OPEN   = '[[';
	private const FIELDSET_CLOSE  = ']]';
	private const FIELDSET_AREA   = 'formgrid-fieldset-'; // reserved synthetic-area prefix
	private const ACCORDION_OPEN  = '>>';
	private const ACCORDION_CLOSE = '<<';
	private const ACCORDION_AREA  = 'formgrid-accordion-'; // reserved synthetic-area prefix

	/** @var list<array<string,mixed>> */
	private array $blocks = [];

	public function __construct(private readonly string $formgrid = '')
	{
		$this->blocks = $this->parseBlocks($this->cleanupFormGrid());
	}

	// -------------------------------------------------------------------------
	// Parsing
	// -------------------------------------------------------------------------

	/**
	 * Classify a non-fieldset line. `---` → divider; `--- X` / `--- X ---` → header.
	 *
	 * @return array{type:string,title?:string}|null  null when the line is not a section marker
	 */
	private function classifySection(string $line): ?array
	{
		if (!str_starts_with($line, self::DIVIDER)) {
			return null;
		}

		// Strip the mandatory leading run of dashes, then an optional trailing run
		$rest  = (string)preg_replace('/^-+/', '', $line);
		$rest  = (string)preg_replace('/-+$/', '', $rest);
		$title = trim($rest);

		return $title === '' ? ['type' => 'divider'] : ['type' => 'header', 'title' => $title];
	}

	/**
	 * @param  list<string>            $lines
	 *
	 * @return list<array<string,mixed>>
	 */
	private function parseBlocks(array $lines): array
	{
		$blocks      = [];
		$fieldsetNo  = 0;
		$accordionNo = 0;
		$i           = 0;
		$count       = count($lines);

		while ($i < $count) {
			$line = $lines[$i];

			// Fieldset open: [[ optional legend text
			if (str_starts_with($line, self::FIELDSET_OPEN)) {
				$fieldsetNo++;
				$legend = trim(substr($line, strlen(self::FIELDSET_OPEN)));
				$inner  = [];
				$i++;
				// Collect inner lines until closing ]] or end-of-input (lenient)
				while ($i < $count && trim($lines[$i]) !== self::FIELDSET_CLOSE) {
					$inner[] = $lines[$i];
					$i++;
				}
				$i++; // consume the closing ]] (or fall off the end — lenient)

				$blocks[] = [
					'type'   => 'fieldset',
					'id'     => self::FIELDSET_AREA . $fieldsetNo,
					'legend' => $legend === '' ? null : $legend,
					'inner'  => new self(implode("\n", $inner)),
				];
				continue;
			}

			// Accordion group: one or more `>> Title` panels, terminated by `<<`
			// or by end-of-input. A panel ends where the next panel begins, so a
			// panel can never contain a panel.
			if (str_starts_with($line, self::ACCORDION_OPEN)) {
				$accordionNo++;
				$panels = [];

				while ($i < $count && str_starts_with($lines[$i], self::ACCORDION_OPEN)) {
					$title = trim(substr($lines[$i], strlen(self::ACCORDION_OPEN)));
					$i++;

					$body = [];
					while ($i < $count
						&& !str_starts_with($lines[$i], self::ACCORDION_OPEN)
						&& !str_starts_with($lines[$i], self::ACCORDION_CLOSE)) {
						$body[] = $lines[$i];
						$i++;
					}

					$panels[] = [
						'title' => $title === '' ? 'Section ' . (count($panels) + 1) : $title,
						'inner' => new self(implode("\n", $body)),
					];
				}

				// Consume the closing `<<` when there is one — falling off the end
				// is lenient, matching the unterminated `[[` behaviour.
				if ($i < $count && str_starts_with($lines[$i], self::ACCORDION_CLOSE)) {
					$i++;
				}

				$blocks[] = [
					'type'   => 'accordion',
					'id'     => self::ACCORDION_AREA . $accordionNo,
					'panels' => $panels,
				];
				continue;
			}

			// A `<<` with no group open is meaningless — drop it rather than let
			// it become a row block that inflates the column count.
			if (str_starts_with($line, self::ACCORDION_CLOSE)) {
				$i++;
				continue;
			}

			// Divider / header
			$section = $this->classifySection($line);
			if ($section !== null) {
				$blocks[] = $section;
				$i++;
				continue;
			}

			// Regular grid row
			$blocks[] = ['type' => 'row', 'line' => $line];
			$i++;
		}

		return $blocks;
	}

	// -------------------------------------------------------------------------
	// Public accessors (used by Task 3 + tests)
	// -------------------------------------------------------------------------

	/**
	 * Every container block in source order, whatever its kind. This is the one
	 * accessor callers should reach for — it is what keeps fieldset and accordion
	 * field-bucketing from forking into two near-identical code paths.
	 *
	 * @return list<array{id:string,kind:string,fields:list<string>,block:array<string,mixed>}>
	 */
	public function getContainers(): array
	{
		$out = [];

		foreach ($this->blocks as $b) {
			$type = (string)($b['type'] ?? '');

			if ($type === 'fieldset') {
				/** @var self $inner */
				$inner = $b['inner'];
				$out[] = [
					'id'     => (string)$b['id'],
					'kind'   => 'fieldset',
					'fields' => $inner->getFieldNames(),
					'block'  => $b,
				];
				continue;
			}

			if ($type === 'accordion') {
				/** @var list<array{title:string,inner:self}> $panels */
				$panels = $b['panels'];
				$fields = [];
				foreach ($panels as $panel) {
					$fields = array_merge($fields, $panel['inner']->getFieldNames());
				}
				$out[] = [
					'id'     => (string)$b['id'],
					'kind'   => 'accordion',
					'fields' => $fields,
					'block'  => $b,
				];
			}
		}

		return $out;
	}

	/** @return list<array{id:string,legend:?string,fields:list<string>,inner:self}> */
	public function getFieldsets(): array
	{
		$out = [];

		foreach ($this->getContainers() as $c) {
			if ($c['kind'] !== 'fieldset') {
				continue;
			}
			/** @var self $inner */
			$inner = $c['block']['inner'];
			/** @var string|null $legend */
			$legend = $c['block']['legend'];
			$out[]  = [
				'id'     => $c['id'],
				'legend' => $legend,
				'fields' => $c['fields'],
				'inner'  => $inner,
			];
		}

		return $out;
	}

	/** @return list<string> */
	public function sectionTitles(): array
	{
		return array_values(array_map(
			static fn (array $b): string => (string)$b['title'],
			array_filter($this->blocks, static fn (array $b): bool => ($b['type'] ?? '') === 'header'),
		));
	}

	public function dividerCount(): int
	{
		return count(array_filter($this->blocks, static fn (array $b): bool => ($b['type'] ?? '') === 'divider'));
	}

	/**
	 * The <style> tag for a form grid: the form is its own container query
	 * ancestor, then the grid rules.
	 */
	public function toStyleTag(string $formId): string
	{
		$rules = $this->gridRules($formId);
		if ($rules === '') {
			return '';
		}

		return "<style>\n#$formId-container {\n\tcontainer-type: inline-size;\n}\n" . $rules . '</style>';
	}

	/**
	 * The <style> tag for a nested fieldset grid — the same rules without the
	 * container block, because the outer form already provides the ancestor.
	 */
	public function toNestedStyleTag(string $gridId): string
	{
		$rules = $this->gridRules($gridId);

		return $rules === '' ? '' : "<style>\n" . $rules . '</style>';
	}

	/** Grid areas for mobile, and for desktop from 500px up; empty when there is no layout. */
	private function gridRules(string $gridId): string
	{
		$desktopAreas = $this->getDesktopGridAreas();
		$mobileAreas  = $this->getMobileGridAreas();

		if ($desktopAreas === [] || $mobileAreas === []) {
			return '';
		}

		$columnCount     = $this->getColumnCount();
		$desktopAreasStr = $this->buildAreasString($desktopAreas, "\n\t\t\t");
		$mobileAreasStr  = $this->buildAreasString($mobileAreas, "\n\t\t");

		return <<<CSS
#$gridId {
	grid-template-areas:
		$mobileAreasStr;
	grid-template-columns: 1fr;
}
@container (min-width: 500px) {
	#$gridId {
		grid-template-areas:
			$desktopAreasStr;
		grid-template-columns: repeat($columnCount, 1fr);
	}
}

CSS;
	}

	/**
	 * Build a CSS grid-template-areas string from an array of area-row strings,
	 * wrapping each row in single quotes and joining with the given separator.
	 *
	 * @param array<string> $areas
	 */
	private function buildAreasString(array $areas, string $separator): string
	{
		return implode($separator, array_map(fn (string $area): string => "'$area'", $areas));
	}

	/**
	 * Get desktop grid areas as an array of strings.
	 *
	 * @return array<string>
	 */
	private function getDesktopGridAreas(): array
	{
		$gridLines      = [];
		$sectionCounter = 0;
		$columnCount    = $this->getColumnCount();

		// Generates extra "." columns for headers and dividers
		$extraColumns = '';
		for ($i = 1; $i < $columnCount; $i++) {
			$extraColumns .= ' .';
		}

		foreach ($this->blocks as $block) {
			switch ($block['type'] ?? '') {
				case 'divider':
					$sectionCounter++;
					$gridLines[] = "section-divider-$sectionCounter $extraColumns";
					break;

				case 'header':
					$sectionCounter++;
					$gridLines[] = "section-header-$sectionCounter $extraColumns";
					break;

				case 'accordion':
				case 'fieldset':
					$id          = (string)($block['id'] ?? '');
					$gridLines[] = implode(' ', array_fill(0, $columnCount, $id));
					break;

				case 'row':
					$line       = (string)($block['line'] ?? '');
					$normalized = (string)preg_replace('/\s+/', ' ', $line);
					$columns    = explode(' ', $normalized);

					// Validate each area name — skip the entire row if any name is invalid
					$valid = true;
					foreach ($columns as $area) {
						if (!$this->isValidGridAreaName($area)) {
							$valid = false;
							break;
						}
					}
					if (!$valid) {
						break;
					}

					// Escape area names for CSS
					$escapedAreas = array_map(fn (string $area): string => htmlspecialchars($area, ENT_QUOTES, 'UTF-8'), $columns);
					$gridLines[]  = implode(' ', $escapedAreas);
					break;
			}
		}

		return $gridLines;
	}

	/**
	 * Get grid areas flattened to single column for mobile.
	 * Each area gets its own row, maintaining the formgrid order.
	 *
	 * @return array<string>
	 */
	private function getMobileGridAreas(): array
	{
		$mobileAreas    = [];
		$sectionCounter = 0;
		$seenAreas      = [];

		foreach ($this->blocks as $block) {
			switch ($block['type'] ?? '') {
				case 'divider':
					$sectionCounter++;
					$mobileAreas[] = "section-divider-$sectionCounter";
					break;

				case 'header':
					$sectionCounter++;
					$mobileAreas[] = "section-header-$sectionCounter";
					break;

				case 'accordion':
				case 'fieldset':
					$mobileAreas[] = (string)($block['id'] ?? '');
					break;

				case 'row':
					$line       = (string)($block['line'] ?? '');
					$normalized = (string)preg_replace('/\s+/', ' ', $line);
					$columns    = explode(' ', $normalized);

					foreach ($columns as $area) {
						// Skip invalid names and dots (empty cells)
						if (!$this->isValidGridAreaName($area) || $area === '.') {
							continue;
						}

						// Skip duplicate areas (e.g., 'id id' becomes just 'id')
						if (isset($seenAreas[$area])) {
							continue;
						}
						$seenAreas[$area] = true;

						$mobileAreas[] = htmlspecialchars($area, ENT_QUOTES, 'UTF-8');
					}
					break;
			}
		}

		return $mobileAreas;
	}

	// -------------------------------------------------------------------------
	// Section HTML
	// -------------------------------------------------------------------------

	/**
	 * Build HTML for section headers and dividers.
	 */
	public function buildGridSectionHtml(): string
	{
		$sections = $this->getSections();
		$content  = '';

		foreach ($sections as $section) {
			switch ($section['type']) {
				case 'header':
					$content .= $this->buildHeaderHtml($section['title'], $section['area']);
					break;

				case 'divider':
					$content .= $this->buildDividerHtml($section['area']);
					break;
			}
		}

		return $content;
	}

	private function buildDividerHtml(string $gridArea): string
	{
		return HTMLUtils::inlineElement('hr', [
			'class' => 'form-grid-section-divider',
			'style' => "grid-area: $gridArea;",
		]);
	}

	private function buildHeaderHtml(string $title, string $gridArea): string
	{
		return HTMLUtils::element('h3', htmlspecialchars($title, ENT_QUOTES, 'UTF-8'), [
			'class' => 'form-grid-section-header',
			'style' => "grid-area: $gridArea;",
		]);
	}

	/**
	 * Get section metadata for rendering section headers and dividers.
	 *
	 * @return array<int,array<string,mixed>>
	 */
	private function getSections(): array
	{
		$sections       = [];
		$sectionCounter = 0;

		foreach ($this->blocks as $block) {
			switch ($block['type'] ?? '') {
				case 'divider':
					$sectionCounter++;
					$sections[] = [
						'type' => 'divider',
						'area' => 'section-divider-' . $sectionCounter,
					];
					break;

				case 'header':
					$sectionCounter++;
					$sections[] = [
						'type'  => 'header',
						'title' => (string)$block['title'],
						'area'  => 'section-header-' . $sectionCounter,
					];
					break;
			}
		}

		return $sections;
	}

	// -------------------------------------------------------------------------
	// Layout helpers
	// -------------------------------------------------------------------------

	private function getColumnCount(): int
	{
		$maxColumns = 0;

		foreach ($this->blocks as $block) {
			// Only row blocks contribute column counts
			if (($block['type'] ?? '') !== 'row') {
				continue;
			}

			$columns    = preg_split('/\s+/', (string)($block['line'] ?? '')) ?: [];
			$maxColumns = max($maxColumns, count($columns));
		}

		// Floor at 1 column when there's any layout block (e.g. a formgrid that is
		// only a fieldset, or only dividers) so full-width areas don't emit an
		// empty template / `repeat(0, 1fr)`. Grids with rows are unaffected.
		return $this->blocks === [] ? 0 : max(1, $maxColumns);
	}

	public function hasGrid(): bool
	{
		return $this->blocks !== [];
	}

	/**
	 * Render form content inside a grid layout container.
	 *
	 * Always wraps content in a `.formgrid` div so fields have consistent vertical spacing.
	 * When the formgrid layout is non-empty, prepends a <style> tag with grid-template-areas,
	 * inserts section headers/dividers ahead of the fields, and wraps in an outer
	 * container-queries div.
	 *
	 * @param string $content Pre-rendered field HTML
	 * @param string $extraClass Additional class names for the inner grid element
	 */
	public function renderLayout(string $content, string $extraClass = '', ?string $gridId = null): string
	{
		$classes = trim('formgrid ' . $extraClass);

		if (!$this->hasGrid()) {
			return HTMLUtils::element('div', $content, ['class' => $classes]);
		}

		$gridId ??= 'formgrid-' . bin2hex(random_bytes(8));
		$inner  = HTMLUtils::element('div', $this->buildGridSectionHtml() . $content, [
			'id'    => $gridId,
			'class' => $classes,
		]);
		$container = HTMLUtils::element('div', $inner, ['id' => $gridId . '-container']);

		return $this->toStyleTag($gridId) . $container;
	}

	/**
	 * Render container interior HTML inside a scoped nested grid.
	 *
	 * Only builds a nested `.formgrid` (with its scoped grid-template-areas) when
	 * there actually is an inner grid. Without one, wrapping in `.formgrid` would
	 * make `.formgrid > .form-field { grid-area: var(--grid-area) }` apply each
	 * field's `--grid-area` against an undefined template, throwing off the
	 * layout — so the content is returned to flow normally.
	 *
	 * Shared by FieldsetRenderer and AccordionRenderer so the two cannot drift.
	 */
	public function renderNestedLayout(string $content, ?string $gridId = null): string
	{
		if (!$this->hasGrid()) {
			return $content;
		}

		$gridId ??= 'formgrid-' . bin2hex(random_bytes(8));

		return $this->toNestedStyleTag($gridId)
			. HTMLUtils::element('div', $this->buildGridSectionHtml() . $content, [
				'id'    => $gridId,
				'class' => 'formgrid',
			]);
	}

	/**
	 * Ensure all given field names are included in the grid layout.
	 * Any missing fields are appended as full-width rows spanning all columns.
	 *
	 * @param array<string> $fieldNames
	 */
	public function ensureFieldsIncluded(array $fieldNames): void
	{
		$existingFields = $this->getFieldNames();
		// Container members live in their container's inner grid, not the outer
		// grid — treat them as already included so they aren't appended as rows.
		foreach ($this->getContainers() as $container) {
			$existingFields = array_merge($existingFields, $container['fields']);
		}
		$columnCount = max(1, $this->getColumnCount());

		foreach ($fieldNames as $name) {
			if (!$this->isValidGridAreaName($name) || $name === '.' || in_array($name, $existingFields, true)) {
				continue;
			}

			// Add as a full-width row by repeating the name across all columns
			$line           = implode(' ', array_fill(0, $columnCount, $name));
			$this->blocks[] = ['type' => 'row', 'line' => $line];
		}
	}

	/**
	 * Get all unique field names referenced in the grid layout.
	 * Only outer `row` blocks contribute — fieldset inner fields are NOT outer fields.
	 *
	 * @return list<string>
	 */
	public function getFieldNames(): array
	{
		$fields = [];

		foreach ($this->blocks as $block) {
			// Only row blocks contribute field names
			if (($block['type'] ?? '') !== 'row') {
				continue;
			}

			$columns = preg_split('/\s+/', (string)($block['line'] ?? '')) ?: [];
			foreach ($columns as $area) {
				if ($area !== '.' && $this->isValidGridAreaName($area)) {
					$fields[$area] = true;
				}
			}
		}

		return array_keys($fields);
	}

	// -------------------------------------------------------------------------
	// Utilities
	// -------------------------------------------------------------------------

	/** @return list<string> */
	private function cleanupFormGrid(): array
	{
		$lines = preg_split('/\r\n|\r|\n/', trim($this->formgrid));
		$lines = $lines === false ? [] : array_map(trim(...), $lines);

		return array_values(array_filter($lines, fn (string $line): bool => $line !== ''));
	}

	/**
	 * Validate CSS grid area names according to CSS identifier rules.
	 */
	private function isValidGridAreaName(string $name): bool
	{
		// CSS identifier rules: must start with letter, underscore, or hyphen
		// followed by letters, digits, hyphens, or underscores
		// Also allow the special "." for empty grid cells
		return $name === '.' || preg_match('/^[a-zA-Z_-][a-zA-Z0-9_-]*$/', $name) === 1;
	}
}
