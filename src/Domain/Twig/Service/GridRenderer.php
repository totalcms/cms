<?php

namespace TotalCMS\Domain\Twig\Service;

use TotalCMS\Domain\Rendering\Utilities\HTMLUtils;
use TotalCMS\Domain\Twig\Extension\TotalCMSTwigFilters;

/**
 * Service for rendering CMS grids
 *
 * Handles grid template generation, auto-detection, and processing
 */
final class GridRenderer
{
	/** @var callable|null */
	private $imageCallback = null;

	/**
	 * Set image callback for processing image_html placeholders
	 *
	 * @param callable $callback Function that takes (id, imageworks, options) and returns HTML
	 */
	public function setImageCallback(callable $callback): void
	{
		$this->imageCallback = $callback;
	}

	// -------------------------
	// Grid Helper Methods (accessible via cms.grid.*)
	// -------------------------

	/**
	 * Format meta information for grid display
	 *
	 * @param mixed $data Meta data (date, author, etc.)
	 * @param string $format Format type (default, date, author, etc.)
	 * @return string Formatted meta HTML
	 */
	public function meta(mixed $data, string $format = 'default'): string
	{
		if (empty($data)) {
			return '';
		}

		$content = '';

		switch ($format) {
			case 'date':
				$content = TotalCMSTwigFilters::dateRelative($data);
				break;
			case 'author':
				$content = is_string($data) ? htmlspecialchars($data, ENT_QUOTES, 'UTF-8') : '';
				break;
			case 'category':
				$content = is_string($data) ? htmlspecialchars($data, ENT_QUOTES, 'UTF-8') : '';
				break;
			default:
				$content = is_string($data) ? htmlspecialchars($data, ENT_QUOTES, 'UTF-8') : '';
		}

		return HTMLUtils::metaData($content, $format);
	}

	/**
	 * Format tags for grid display
	 *
	 * @param array<string>|string|null $tags Tags array or comma-separated string
	 * @param string|null $linkBase Base URL for tag links (null for no links)
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
	 * Format date for grid display
	 *
	 * @param mixed $date Date string or timestamp
	 * @param string $format Format type (relative, short, long, custom)
	 * @return string Formatted date HTML
	 */
	public function date(mixed $date, string $format = 'relative'): string
	{
		if (empty($date)) {
			return '';
		}

		// Use existing Chronos date filters
		switch ($format) {
			case 'relative':
				$formatted = TotalCMSTwigFilters::dateRelative($date);
				break;
			case 'short':
				$formatted = TotalCMSTwigFilters::dateFormat($date, 'M j, Y');
				break;
			case 'long':
				$formatted = TotalCMSTwigFilters::dateFormat($date, 'F j, Y');
				break;
			case 'iso':
				$formatted = TotalCMSTwigFilters::dateFormat($date, 'c');
				break;
			default:
				// Custom format - pass directly to dateFormat
				$formatted = TotalCMSTwigFilters::dateFormat($date, $format);
		}

		// Get ISO datetime for the datetime attribute
		$datetime = TotalCMSTwigFilters::dateFormat($date, 'c');

		return HTMLUtils::time($formatted, $datetime, ['class' => 'cms-date']);
	}

	/**
	 * Format excerpt for grid display
	 *
	 * @param string|null $text Text to excerpt
	 * @param int $length Maximum length in characters
	 * @param string $suffix Suffix to append when truncated
	 * @return string Formatted excerpt HTML
	 */
	public function excerpt(?string $text, int $length = 100, string $suffix = '…'): string
	{
		if (empty($text)) {
			return '';
		}

		// Strip HTML tags
		$text = strip_tags($text);

		// Truncate if needed
		if (strlen($text) > $length) {
			$text = substr($text, 0, $length);
			// Find last space to avoid cutting words
			$lastSpace = strrpos($text, ' ');
			if ($lastSpace !== false && $lastSpace > $length * 0.8) {
				$text = substr($text, 0, $lastSpace);
			}
			$text .= $suffix;
		}

		return HTMLUtils::element('div', htmlspecialchars($text, ENT_QUOTES, 'UTF-8'), ['class' => 'cms-excerpt']);
	}

	/**
	 * Format price for grid display
	 *
	 * @param mixed $price Price value
	 * @param string $currency Currency symbol or code
	 * @param string $format Format type (symbol, code, none)
	 * @return string Formatted price HTML
	 */
	public function price(mixed $price, string $currency = '$', string $format = 'symbol'): string
	{
		if (empty($price) && $price !== 0 && $price !== '0') {
			return '';
		}

		$numericPrice = is_numeric($price) ? floatval($price) : 0;

		switch ($format) {
			case 'symbol':
				$formatted = $currency . number_format($numericPrice, 2);
				break;
			case 'code':
				$formatted = number_format($numericPrice, 2) . ' ' . $currency;
				break;
			case 'none':
				$formatted = number_format($numericPrice, 2);
				break;
			default:
				$formatted = $currency . number_format($numericPrice, 2);
		}

		return HTMLUtils::element('span', $formatted, ['class' => 'cms-price']);
	}

	/**
	 * Generate image HTML for grid display
	 *
	 * @param mixed $imageData Image data array or string
	 * @param array<string,mixed> $options Options for image processing
	 * @return string Image HTML
	 */
	public function imageHtml(mixed $imageData, array $options = []): string
	{
		if (empty($imageData)) {
			return '';
		}

		// This is a simplified implementation
		// In a real implementation, this would use the TotalCMSTwigAdapter image method
		if (is_string($imageData)) {
			$src = htmlspecialchars($imageData, ENT_QUOTES, 'UTF-8');
			$alt = $options['alt'] ?? '';
		} elseif (is_array($imageData)) {
			$src = htmlspecialchars($imageData['src'] ?? $imageData['url'] ?? '', ENT_QUOTES, 'UTF-8');
			$alt = htmlspecialchars($imageData['alt'] ?? $imageData['title'] ?? '', ENT_QUOTES, 'UTF-8');
		} else {
			return '';
		}

		if (empty($src)) {
			return '';
		}

		return HTMLUtils::inlineElement('img', [
			'src' => $src,
			'alt' => $alt,
			'loading' => 'lazy',
			'draggable' => 'false',
			'oncontextmenu' => 'return false;'
		]);
	}
	/**
	 * Generate a CMS grid layout for displaying collections
	 *
	 * @param array<array<string,mixed>> $objects Array of objects to display
	 * @param string $classes CSS classes for the grid container
	 * @param string $itemTag HTML tag for grid items (default: 'div')
	 * @param string|null $template Template string for each item (uses auto-detection if null)
	 * @return string HTML grid markup
	 */
	public function render(array $objects, string $classes = '', string $itemTag = 'div', ?string $template = null): string
	{
		if (empty($objects)) {
			return '';
		}

		// Auto-detect template if not provided
		if ($template === null) {
			$template = $this->autoDetectTemplate($objects[0], $classes);
		}

		// Build grid container
		$containerClasses = trim("cms-grid $classes");
		$gridItems = [];

		// Process each object
		foreach ($objects as $object) {
			$itemContent = $this->processItemTemplate($template, $object);
			$gridItems[] = HTMLUtils::element($itemTag, $itemContent, ['class' => 'cms-grid-item']);
		}

		return HTMLUtils::element('div', implode('', $gridItems), ['class' => $containerClasses]);
	}

	/**
	 * Auto-detect appropriate template based on object structure and classes
	 *
	 * @param array<string,mixed> $sampleObject Sample object to analyze
	 * @param string $classes Grid classes to help determine template
	 * @return string Template string
	 */
	public function autoDetectTemplate(array $sampleObject, string $classes): string
	{
		// Check for specific grid types in classes
		if (str_contains($classes, 'blog')) {
			return $this->getBlogTemplate($sampleObject);
		}
		if (str_contains($classes, 'products')) {
			return $this->getProductTemplate($sampleObject);
		}
		if (str_contains($classes, 'gallery')) {
			return $this->getGalleryTemplate($sampleObject);
		}
		if (str_contains($classes, 'team')) {
			return $this->getTeamTemplate($sampleObject);
		}
		if (str_contains($classes, 'feed')) {
			return $this->getFeedTemplate($sampleObject);
		}

		// Default generic template
		return $this->getGenericTemplate($sampleObject);
	}

	/**
	 * Process template string with object data
	 *
	 * @param string $template Template string with placeholders
	 * @param array<string,mixed> $object Object data
	 * @return string Processed template
	 */
	public function processItemTemplate(string $template, array $object): string
	{
		// Enhanced template variable replacement with special handling for common grid elements
		$processed = $template;

		// Process special template variables first
		$processed = $this->processSpecialTemplateVariables($processed, $object);

		// Process regular object properties
		foreach ($object as $key => $value) {
			$placeholder = "{{ $key }}";
			$processed = str_replace($placeholder, is_string($value) ? htmlspecialchars($value, ENT_QUOTES, 'UTF-8') : '', $processed);
		}

		return $processed;
	}

	/**
	 * Process special template variables that require complex processing
	 *
	 * @param string $template Template string
	 * @param array<string,mixed> $object Object data
	 * @return string Processed template
	 */
	private function processSpecialTemplateVariables(string $template, array $object): string
	{
		// Process image_html placeholder
		if (str_contains($template, '{{ image_html }}')) {
			$imageHtml = '';
			if (isset($object['image']) && !empty($object['image'])) {
				if ($this->imageCallback !== null) {
					// Use the injected image callback for full CMS image processing
					$imageHtml = ($this->imageCallback)($object['id'] ?? '', [], ['collection' => $object['collection'] ?? 'image']);
				} else {
					// Fallback to basic image HTML generation
					$imageHtml = $this->imageHtml($object['image']);
				}
			}
			$template = str_replace('{{ image_html }}', $imageHtml, $template);
		}

		// Process tags_html placeholder
		if (str_contains($template, '{{ tags_html }}')) {
			$tagsHtml = '';
			if (isset($object['tags']) && !empty($object['tags'])) {
				$tagsHtml = $this->tags($object['tags']);
			}
			$template = str_replace('{{ tags_html }}', $tagsHtml, $template);
		}

		// Process created_formatted placeholder
		if (str_contains($template, '{{ created_formatted }}')) {
			$createdFormatted = '';
			if (isset($object['created']) && !empty($object['created'])) {
				$createdFormatted = $this->date($object['created'], 'relative');
			}
			$template = str_replace('{{ created_formatted }}', $createdFormatted, $template);
		}

		// Process date_formatted placeholder
		if (str_contains($template, '{{ date_formatted }}')) {
			$dateFormatted = '';
			if (isset($object['date']) && !empty($object['date'])) {
				$dateFormatted = $this->date($object['date'], 'relative');
			}
			$template = str_replace('{{ date_formatted }}', $dateFormatted, $template);
		}

		// Process price_formatted placeholder
		if (str_contains($template, '{{ price_formatted }}')) {
			$priceFormatted = '';
			if (isset($object['price']) && !empty($object['price'])) {
				$priceFormatted = $this->price($object['price']);
			}
			$template = str_replace('{{ price_formatted }}', $priceFormatted, $template);
		}

		return $template;
	}

	/**
	 * Get blog template
	 *
	 * @param array<string,mixed> $object Sample object
	 * @return string Template string
	 */
	private function getBlogTemplate(array $object): string
	{
		$template = '';

		if (isset($object['image'])) {
			$template .= '<div class="cms-image">{{ image_html }}</div>';
		}

		$template .= '<h3 class="cms-title">{{ title }}</h3>';

		if (isset($object['created'])) {
			$template .= '<div class="cms-meta">{{ created_formatted }}</div>';
		}

		if (isset($object['excerpt'])) {
			$template .= '<div class="cms-excerpt">{{ excerpt }}</div>';
		}

		if (isset($object['tags'])) {
			$template .= '<div class="cms-tags">{{ tags_html }}</div>';
		}

		return $template;
	}

	/**
	 * Get product template
	 *
	 * @param array<string,mixed> $object Sample object
	 * @return string Template string
	 */
	private function getProductTemplate(array $object): string
	{
		$template = '';

		if (isset($object['image'])) {
			$template .= '<div class="cms-image">{{ image_html }}</div>';
		}

		$template .= '<h3 class="cms-title">{{ title }}</h3>';

		if (isset($object['price'])) {
			$template .= '<div class="cms-price">{{ price_formatted }}</div>';
		}

		return $template;
	}

	/**
	 * Get gallery template
	 *
	 * @param array<string,mixed> $object Sample object
	 * @return string Template string
	 */
	private function getGalleryTemplate(array $object): string
	{
		$template = '';

		if (isset($object['image'])) {
			$template .= '<div class="cms-image">{{ image_html }}</div>';
		}

		if (isset($object['title'])) {
			$template .= '<div class="cms-content"><h4>{{ title }}</h4></div>';
		}

		return $template;
	}

	/**
	 * Get team template
	 *
	 * @param array<string,mixed> $object Sample object
	 * @return string Template string
	 */
	private function getTeamTemplate(array $object): string
	{
		$template = '';

		if (isset($object['image'])) {
			$template .= '<div class="cms-image">{{ image_html }}</div>';
		}

		$template .= '<h3 class="cms-title">{{ title }}</h3>';

		if (isset($object['role'])) {
			$template .= '<div class="cms-role">{{ role }}</div>';
		}

		return $template;
	}

	/**
	 * Get feed template
	 *
	 * @param array<string,mixed> $object Sample object
	 * @return string Template string
	 */
	private function getFeedTemplate(array $object): string
	{
		$template = '';

		if (isset($object['date'])) {
			$template .= '<div class="cms-date">{{ date_formatted }}</div>';
		}

		$template .= '<h3 class="cms-title">{{ title }}</h3>';

		if (isset($object['excerpt'])) {
			$template .= '<div class="cms-excerpt">{{ excerpt }}</div>';
		}

		if (isset($object['source'])) {
			$template .= '<div class="cms-source">{{ source }}</div>';
		}

		return $template;
	}

	/**
	 * Get generic template
	 *
	 * @param array<string,mixed> $object Sample object
	 * @return string Template string
	 */
	private function getGenericTemplate(array $object): string
	{
		$template = '';

		if (isset($object['image'])) {
			$template .= '<div class="cms-image">{{ image_html }}</div>';
		}

		$template .= '<h3 class="cms-title">{{ title }}</h3>';

		if (isset($object['excerpt']) || isset($object['description'])) {
			$template .= '<div class="cms-content">{{ excerpt }}{{ description }}</div>';
		}

		return $template;
	}
}