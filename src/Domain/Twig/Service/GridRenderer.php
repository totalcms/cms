<?php

namespace TotalCMS\Domain\Twig\Service;

use TotalCMS\Domain\Rendering\Utilities\HTMLUtils;
use TotalCMS\Domain\Twig\Extension\TotalCMSTwigFilters;

/**
 * Service for rendering CMS grids.
 *
 * Handles grid template generation, auto-detection, and processing
 */
class GridRenderer
{
	// -------------------------
	// Grid Helper Methods (accessible via cms.grid.*)
	// -------------------------

	/**
	 * Format meta information for grid display.
	 *
	 * @param string $data Meta data (date, author, etc.)
	 *
	 * @return string Formatted meta HTML
	 */
	public function meta(string $data): string
	{
		if ($data === '') {
			return '';
		}

		return HTMLUtils::metaData($data);
	}

	/**
	 * Format tags for grid display.
	 *
	 * @param array<string>|string|null $tags Tags array or comma-separated string
	 * @param string|null $linkBase Base URL for tag links (null for no links)
	 *
	 * @return string Formatted tags HTML
	 */
	public function tags(array|string|null $tags, ?string $linkBase = null): string
	{
		if (empty($tags)) {
			return '';
		}
		// Convert string to array if needed
		if (is_string($tags)) {
			$tags = array_map('trim', explode(',', $tags));
		}

		return HTMLUtils::tagList($tags, $linkBase);
	}

	/**
	 * Format date for grid display.
	 *
	 * @param string $date Date string or timestamp
	 * @param string $format Format type (relative, short, long, custom)
	 *
	 * @return string Formatted date HTML
	 */
	public function date(string $date, string $format = 'relative'): string
	{
		if ($date === '') {
			return '';
		}

		return HTMLUtils::time($date, $format, ['class' => 'cms-date']);
	}

	/**
	 * Format excerpt for grid display.
	 *
	 * @param string|null $text Text to excerpt
	 * @param int $length Maximum length in characters
	 *
	 * @return string Formatted excerpt HTML
	 */
	public function excerpt(?string $text, int $length = 100): string
	{
		$text = TotalCMSTwigFilters::truncate($text, $length, true);

		return HTMLUtils::element('p', $text, ['class' => 'cms-excerpt']);
	}

	/**
	 * Format price for grid display with HTML wrapper.
	 *
	 * @param mixed $price Price value
	 * @param string $currency Currency symbol or code
	 * @param string $format Format type (prepend, append, none)
	 *
	 * @return string Formatted price HTML
	 */
	public function price(mixed $price, string $currency = '$', string $format = 'prepend'): string
	{
		$formatted = TotalCMSTwigFilters::price($price, $currency, $format);

		if ($formatted === '') {
			return '';
		}

		return HTMLUtils::element('span', $formatted, ['class' => 'cms-price']);
	}
}
