<?php

namespace TotalCMS\Domain\Admin;

use TotalCMS\Utils\HTMLUtils;

/**
 * Builds CSS Grid layouts and HTML sections from formgrid definitions.
 * 
 * Handles the conversion of text-based grid layouts to CSS Grid properties
 * and generates section headers/dividers for visual organization.
 */
final class FormGridBuilder
{
	public function __construct(
		private string $formgrid = ''
	) {
	}

	/**
	 * Convert formgrid text definition to CSS Grid template areas.
	 */
	public function toCss(): string
	{
		// Return empty string if no grid is defined
		if (empty($this->formgrid)) {
			return '';
		}

		// Split the grid into lines and normalize line endings
		$lines = preg_split('/\r\n|\r|\n/', trim($this->formgrid));
		if ($lines === false) {
			return '';
		}

		$columnCount = 1;
		$quotedLines = [];
		$sectionCounter = 0;

		foreach ($lines as $line) {
			$trimmed = trim($line);
			// Skip empty lines
			if (empty($trimmed)) {
				continue;
			}

			// Process section headers: ---Title---
			if (preg_match('/^---(.+?)---$/', $trimmed, $matches)) {
				$sectionCounter++;
				$sectionId = 'section-header-' . $sectionCounter;
				$quotedLines[] = "'" . $sectionId . " " . $sectionId . " " . $sectionId . "'";
				$columnCount = max($columnCount, 3); // Section headers span 3 columns minimum
				continue;
			}

			// Process dividers: ---
			if ($trimmed === '---') {
				$sectionCounter++;
				$divId = 'section-divider-' . $sectionCounter;
				$quotedLines[] = "'" . $divId . " " . $divId . " " . $divId . "'";
				$columnCount = max($columnCount, 3); // Dividers span 3 columns minimum
				continue;
			}

			// Process regular grid areas
			$normalized = (string)preg_replace('/\s+/', ' ', $trimmed);
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

			$quotedLines[] = "'" . implode(' ', $escapedAreas) . "'";
			$columnCount   = max($columnCount, count($columns));
		}

		// Return empty string if no valid lines
		if (empty($quotedLines)) {
			return '';
		}

		// Return the formatted CSS
		$areas = implode("\n\t\t\t", $quotedLines);

		return <<<CSS
		grid-template-areas:
			$areas;
		grid-template-columns: repeat($columnCount, 1fr);
		CSS;
	}

	/**
	 * Get section metadata for rendering section headers and dividers.
	 *
	 * @return array<int,array<string,mixed>>
	 */
	public function getSections(): array
	{
		// Return empty array if no grid is defined
		if (empty($this->formgrid)) {
			return [];
		}

		// Split the grid into lines and normalize line endings
		$lines = preg_split('/\r\n|\r|\n/', trim($this->formgrid));
		if ($lines === false) {
			return [];
		}

		$sections = [];
		$sectionCounter = 0;

		foreach ($lines as $line) {
			$trimmed = trim($line);
			// Skip empty lines
			if (empty($trimmed)) {
				continue;
			}

			// Process section headers: ---Title---
			if (preg_match('/^---(.+?)---$/', $trimmed, $matches)) {
				$sectionCounter++;
				$sections[] = [
					'type' => 'header',
					'title' => trim($matches[1]),
					'id' => 'section-header-' . $sectionCounter,
					'grid_area' => 'section-header-' . $sectionCounter,
				];
				continue;
			}

			// Process dividers: ---
			if ($trimmed === '---') {
				$sectionCounter++;
				$sections[] = [
					'type' => 'divider',
					'id' => 'section-divider-' . $sectionCounter,
					'grid_area' => 'section-divider-' . $sectionCounter,
				];
				continue;
			}
		}

		return $sections;
	}

	/**
	 * Build HTML for section headers and dividers.
	 */
	public function buildSectionHtml(): string
	{
		$sections = $this->getSections();
		if (empty($sections)) {
			return '';
		}

		$content = '';
		foreach ($sections as $section) {
			if ($section['type'] === 'header') {
				$content .= HTMLUtils::element('div', 
					HTMLUtils::element('h3', htmlspecialchars($section['title'], ENT_QUOTES, 'UTF-8')), 
					[
						'class' => 'form-grid-section-header',
						'style' => 'grid-area: ' . $section['grid_area'] . ';',
						'id' => $section['id'],
					]
				);
			} elseif ($section['type'] === 'divider') {
				$content .= HTMLUtils::element('div', '', 
					[
						'class' => 'form-grid-section-divider',
						'style' => 'grid-area: ' . $section['grid_area'] . ';',
						'id' => $section['id'],
					]
				);
			}
		}

		return $content;
	}

	/**
	 * Check if the formgrid has any content.
	 */
	public function hasContent(): bool
	{
		return !empty(trim($this->formgrid));
	}

	/**
	 * Check if the formgrid contains sections (headers or dividers).
	 */
	public function hasSections(): bool
	{
		return !empty($this->getSections());
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