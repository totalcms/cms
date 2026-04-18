<?php

namespace TotalCMS\Domain\Admin;

use TotalCMS\Domain\Rendering\Utilities\HTMLUtils;

/**
 * Builds CSS Grid layouts and HTML sections from formgrid definitions.
 *
 * Handles the conversion of text-based grid layouts to CSS Grid properties
 * and generates section headers/dividers for visual organization.
 */
class FormGridBuilder
{
	private const HEADER_REGEX = '/^---(.+?)---$/';
	private const DIVIDER      = '---';

	/** @var array<string> */
	private array $lines = [];

	public function __construct(private readonly string $formgrid = '')
	{
		$this->lines = $this->cleanupFormGrid();
	}

	public function toCssGridAreas(): string
	{
		$gridLines      = [];
		$sectionCounter = 0;
		$columnCount    = $this->getColumnCount();

		// Generates extra "." columns for headers and dividers
		// to ensure they span the same number of columns as the grid.
		$extraColumns = '';
		for ($i = 1; $i < $columnCount; $i++) {
			$extraColumns .= ' .';
		}

		foreach ($this->lines as $line) {
			// Process dividers: ---
			if (self::DIVIDER === $line) {
				$sectionCounter++;
				$gridLines[] = "'section-divider-$sectionCounter $extraColumns'";
				continue;
			}

			// Process section headers: ---Title---
			if (preg_match(self::HEADER_REGEX, $line)) {
				$sectionCounter++;
				$gridLines[] = "'section-header-$sectionCounter $extraColumns'";
				continue;
			}

			// Process regular grid areas
			$normalized = (string)preg_replace('/\s+/', ' ', $line);
			$columns    = explode(' ', $normalized);

			// Validate each area name
			foreach ($columns as $area) {
				if (!$this->isValidGridAreaName($area)) {
					// Skip invalid lines or throw exception
					continue 2;
				}
			}

			// Escape area names for CSS
			$escapedAreas = array_map(fn (string $area): string => htmlspecialchars($area, ENT_QUOTES, 'UTF-8'), $columns);

			$gridLines[]   = "'" . implode(' ', $escapedAreas) . "'";
			$columnCount   = max($columnCount, count($columns));
		}

		// Return empty string if no valid lines
		if ($gridLines === []) {
			return '';
		}

		// Return the formatted CSS
		$areas = implode("\n", $gridLines);

		return <<<CSS
		grid-template-areas:
			$areas;
		grid-template-columns: repeat($columnCount, 1fr);
		CSS;
	}

	/**
	 * Generate a <style> tag with mobile-first responsive grid CSS.
	 * Uses container queries so the form responds to its available space,
	 * not the viewport (useful when sidebar is open on tablets).
	 *
	 * Note: Container queries require styling descendants, not the container itself.
	 * The wrapper div has container-type, and the form inside responds to it.
	 */
	public function toStyleTag(string $formId): string
	{
		$desktopAreas = $this->getDesktopGridAreas();
		$mobileAreas  = $this->getMobileGridAreas();

		if ($desktopAreas === [] || $mobileAreas === []) {
			return '';
		}

		$columnCount     = $this->getColumnCount();
		$desktopAreasStr = implode("\n\t\t\t", array_map(fn (string $area): string => "'$area'", $desktopAreas));
		$mobileAreasStr  = implode("\n\t\t", array_map(fn (string $area): string => "'$area'", $mobileAreas));

		return <<<HTML
<style>
#$formId-container {
	container-type: inline-size;
}
#$formId {
	grid-template-areas:
		$mobileAreasStr;
	grid-template-columns: 1fr;
}
@container (min-width: 500px) {
	#$formId {
		grid-template-areas:
			$desktopAreasStr;
		grid-template-columns: repeat($columnCount, 1fr);
	}
}
</style>
HTML;
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

		foreach ($this->lines as $line) {
			// Process dividers: ---
			if (self::DIVIDER === $line) {
				$sectionCounter++;
				$gridLines[] = "section-divider-$sectionCounter $extraColumns";
				continue;
			}

			// Process section headers: ---Title---
			if (preg_match(self::HEADER_REGEX, $line)) {
				$sectionCounter++;
				$gridLines[] = "section-header-$sectionCounter $extraColumns";
				continue;
			}

			// Process regular grid areas
			$normalized = (string)preg_replace('/\s+/', ' ', $line);
			$columns    = explode(' ', $normalized);

			// Validate each area name
			foreach ($columns as $area) {
				if (!$this->isValidGridAreaName($area)) {
					continue 2;
				}
			}

			// Escape area names for CSS
			$escapedAreas = array_map(fn (string $area): string => htmlspecialchars($area, ENT_QUOTES, 'UTF-8'), $columns);
			$gridLines[]  = implode(' ', $escapedAreas);
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

		foreach ($this->lines as $line) {
			// Process dividers: ---
			if (self::DIVIDER === $line) {
				$sectionCounter++;
				$mobileAreas[] = "section-divider-$sectionCounter";
				continue;
			}

			// Process section headers: ---Title---
			if (preg_match(self::HEADER_REGEX, $line)) {
				$sectionCounter++;
				$mobileAreas[] = "section-header-$sectionCounter";
				continue;
			}

			// Process regular grid areas - split into individual rows
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
		}

		return $mobileAreas;
	}

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

		foreach ($this->lines as $line) {
			// Process dividers: ---
			if (self::DIVIDER === $line) {
				$sectionCounter++;
				$sections[] = [
					'type' => 'divider',
					'area' => 'section-divider-' . $sectionCounter,
				];
				continue;
			}

			// Process section headers: ---Title---
			if (preg_match(self::HEADER_REGEX, $line, $matches)) {
				$sectionCounter++;
				$sections[] = [
					'type'  => 'header',
					'title' => trim($matches[1]),
					'area'  => 'section-header-' . $sectionCounter,
				];
				continue;
			}
		}

		return $sections;
	}

	/** @return array<string> */
	private function cleanupFormGrid(): array
	{
		$lines = preg_split('/\r\n|\r|\n/', trim($this->formgrid));
		$lines = $lines === false ? [] : array_map(trim(...), $lines);

		return array_filter($lines, function (string $line): bool {
			return $line !== '' && $line !== '0'; // Filter out empty lines
		});
	}

	private function getColumnCount(): int
	{
		$maxColumns = 0;

		foreach ($this->lines as $line) {
			// Skip dividers and headers - they always span full width
			if (self::DIVIDER === $line || preg_match(self::HEADER_REGEX, $line)) {
				continue;
			}

			// Count columns in the current line
			$columns    = preg_split('/\s+/', $line) ?: [];
			$maxColumns = max($maxColumns, count($columns));
		}

		return $maxColumns;
	}

	public function hasGrid(): bool
	{
		return $this->lines !== [];
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
	public function renderLayout(string $content, string $extraClass = ''): string
	{
		$classes = trim('formgrid ' . $extraClass);

		if (!$this->hasGrid()) {
			return HTMLUtils::element('div', $content, ['class' => $classes]);
		}

		$gridId = 'formgrid-' . bin2hex(random_bytes(8));
		$inner  = HTMLUtils::element('div', $this->buildGridSectionHtml() . $content, [
			'id'    => $gridId,
			'class' => $classes,
		]);
		$container = HTMLUtils::element('div', $inner, ['id' => $gridId . '-container']);

		return $this->toStyleTag($gridId) . $container;
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
		$columnCount    = max(1, $this->getColumnCount());

		foreach ($fieldNames as $name) {
			if (!$this->isValidGridAreaName($name) || $name === '.' || in_array($name, $existingFields, true)) {
				continue;
			}

			// Add as a full-width row by repeating the name across all columns
			$this->lines[] = implode(' ', array_fill(0, $columnCount, $name));
		}
	}

	/**
	 * Get all unique field names referenced in the grid layout.
	 *
	 * @return array<string>
	 */
	private function getFieldNames(): array
	{
		$fields = [];

		foreach ($this->lines as $line) {
			// Skip dividers and headers
			if (self::DIVIDER === $line || preg_match(self::HEADER_REGEX, $line)) {
				continue;
			}

			$columns = preg_split('/\s+/', $line) ?: [];
			foreach ($columns as $area) {
				if ($area !== '.' && $this->isValidGridAreaName($area)) {
					$fields[$area] = true;
				}
			}
		}

		return array_keys($fields);
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
