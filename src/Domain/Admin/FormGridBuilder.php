<?php

namespace TotalCMS\Domain\Admin;

use TotalCMS\Domain\Rendering\Utilities\HTMLUtils;

/**
 * Builds CSS Grid layouts and HTML sections from formgrid definitions.
 *
 * Handles the conversion of text-based grid layouts to CSS Grid properties
 * and generates section headers/dividers for visual organization.
 */
final class FormGridBuilder
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
			$escapedAreas = array_map(function ($area) {
				return htmlspecialchars($area, ENT_QUOTES, 'UTF-8');
			}, $columns);

			$gridLines[]   = "'" . implode(' ', $escapedAreas) . "'";
			$columnCount   = max($columnCount, count($columns));
		}

		// Return empty string if no valid lines
		if (empty($gridLines)) {
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
		$lines = $lines === false ? [] : array_map('trim', $lines);
		$lines = array_filter($lines, function ($line) {
			return !empty($line); // Filter out empty lines
		});

		return $lines;
	}

	private function getColumnCount(): int
	{
		$maxColumns = 0;

		foreach ($this->lines as $line) {
			// Count columns in the current line
			$columns    = preg_split('/\s+/', $line) ?: [];
			$maxColumns = max($maxColumns, count($columns));
		}

		return $maxColumns;
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
