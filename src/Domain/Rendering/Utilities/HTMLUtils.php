<?php

namespace TotalCMS\Domain\Rendering\Utilities;

/**
 * HTML Utilities.
 */
class HTMLUtils
{
	/** @param array<string,mixed> $attributes */
	public static function element(string $tag, string $content, array $attributes = []): string
	{
		$element  = "<$tag";
		$element .= self::buildHTMLAttributes($attributes);
		$element .= ">$content</$tag>";

		return $element;
	}

	/** @param array<string,mixed> $attributes */
	public static function inlineElement(string $tag, array $attributes = []): string
	{
		$element  = "<$tag";
		$element .= self::buildHTMLAttributes($attributes);
		$element .= '/>';

		return $element;
	}

	/** @param array<string,mixed> $attributes */
	public static function buildHTMLAttributes(array $attributes = []): string
	{
		$element = '';

		foreach ($attributes as $attr => $value) {
			if ($value === null) {
				continue; // Skip null values
			}

			if ($value === false) {
				continue; // Skip false boolean values
			}

			if ($value === true || $value === '') {
				$element .= " $attr"; // Boolean attributes
				continue;
			}

			$element .= " $attr=\"" . htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8') . '"';
		}

		return $element;
	}

	/** @param array<string,mixed> $attributes */
	public static function details(string $title, string $content, string $class = '', array $attributes = []): string
	{
		$attributes['class'] = "cms-accordion $class";

		$summary = self::element('summary', $title);
		$content = self::element('div', $content, ['class' => 'content']);
		$details = self::element('details', $summary . $content, $attributes);

		return $details;
	}

	public static function dialog(string $content, string $class = ''): string
	{
		return self::element('dialog', $content, ['class' => "cms-modal $class"]);
	}

	public static function iframe(string $url, string $class = ''): string
	{
		return self::element('iframe', '', [
			'style'           => 'width:100%;height:100%',
			'data-src'        => $url,
			'frameborder'     => '0',
			'allowfullscreen' => '',
			'class'           => "cms-iframe $class",
		]);
	}

	public static function scroller(string $content): string
	{
		return self::element('section', $content, ['class' => 'scroller']);
	}

	/** @param array<string,mixed> $attributes */
	public static function button(string $content, array $attributes = []): string
	{
		$attributes = array_merge([
			'type' => 'button',
		], $attributes);

		$attributes['class'] = 'button btn ' . ($attributes['class'] ?? '');

		return self::element('button', $content, $attributes);
	}

	public static function add(string $title): string
	{
		$attributes = [
			'title' => $title,
			'class' => 'cms-add',
		];

		return self::button('', $attributes);
	}

	/** @param array<string,mixed> $attributes */
	public static function option(string $label, string $eval = '', array $attributes = []): string
	{
		$attributes = array_merge([
			'value' => $label,
		], $attributes);

		if ($eval === $attributes['value']) {
			$attributes['selected'] = '';
		}

		return self::element('option', $label, $attributes);
	}

	// -------------------------
	// CSS and Attribute Utilities
	// -------------------------

	/** @param string ...$classes */
	public static function mergeClasses(string ...$classes): string
	{
		return implode(' ', array_filter(array_map('trim', $classes)));
	}

	/**
	 * @param array<string,mixed> $data
	 * @return array<string,mixed>
	 */
	public static function dataAttributes(array $data): array
	{
		$attributes = [];
		foreach ($data as $key => $value) {
			$attributes["data-$key"] = $value;
		}
		return $attributes;
	}

	/**
	 * @param array<string,mixed> $aria
	 * @return array<string,mixed>
	 */
	public static function ariaAttributes(array $aria): array
	{
		$attributes = [];
		foreach ($aria as $key => $value) {
			$attributes["aria-$key"] = $value;
		}
		return $attributes;
	}

	// -------------------------
	// Semantic HTML5 Elements
	// -------------------------

	/** @param array<string,mixed> $attributes */
	public static function article(string $content, array $attributes = []): string
	{
		return self::element('article', $content, $attributes);
	}

	/** @param array<string,mixed> $attributes */
	public static function time(string $content, string $datetime = '', array $attributes = []): string
	{
		if (!empty($datetime)) {
			$attributes['datetime'] = $datetime;
		}
		return self::element('time', $content, $attributes);
	}

	/** @param array<string,mixed> $attributes */
	public static function figure(string $content, array $attributes = []): string
	{
		return self::element('figure', $content, $attributes);
	}

	/** @param array<string,mixed> $attributes */
	public static function figcaption(string $content, array $attributes = []): string
	{
		return self::element('figcaption', $content, $attributes);
	}

	/** @param array<string,mixed> $attributes */
	public static function header(string $content, array $attributes = []): string
	{
		return self::element('header', $content, $attributes);
	}

	/** @param array<string,mixed> $attributes */
	public static function footer(string $content, array $attributes = []): string
	{
		return self::element('footer', $content, $attributes);
	}

	// -------------------------
	// Grid-Specific Builders
	// -------------------------

	/** @param array<string,mixed> $attributes */
	public static function card(string $content, array $attributes = []): string
	{
		$attributes['class'] = self::mergeClasses($attributes['class'] ?? '', 'cms-card');
		return self::article($content, $attributes);
	}

	/**
	 * @param array<string> $tags
	 * @param array<string,mixed> $attributes
	 */
	public static function tagList(array $tags, ?string $linkBase = null, array $attributes = []): string
	{
		if (empty($tags)) {
			return '';
		}

		$tagElements = [];
		foreach ($tags as $tag) {
			if (empty($tag)) {
				continue;
			}

			$tagContent = htmlspecialchars($tag, ENT_QUOTES, 'UTF-8');

			if (!empty($linkBase)) {
				$tagUrl = rtrim($linkBase, '/') . '/' . urlencode($tag);
				$tagElements[] = self::element('a', $tagContent, [
					'href' => $tagUrl,
					'class' => 'tag'
				]);
				continue;
			}

			$tagElements[] = self::element('span', $tagContent, ['class' => 'tag']);
		}

		$attributes['class'] = self::mergeClasses($attributes['class'] ?? '', 'cms-tags');
		return self::element('div', implode('', $tagElements), $attributes);
	}

	/** @param array<string,mixed> $attributes */
	public static function metaData(string $content, string $type = 'default', array $attributes = []): string
	{
		$attributes['class'] = self::mergeClasses($attributes['class'] ?? '', 'cms-meta', "cms-meta-$type");
		return self::element('span', $content, $attributes);
	}

	/**
	 * @param array<string,mixed> $options
	 */
	public static function imageWithCaption(string $imageSrc, string $caption = '', array $options = []): string
	{
		$imageAttrs = [
			'src' => $imageSrc,
			'alt' => $options['alt'] ?? '',
			'loading' => $options['loading'] ?? 'lazy',
			'draggable' => 'false',
			'oncontextmenu' => 'return false;'
		];

		$image = self::inlineElement('img', $imageAttrs);

		if (!empty($caption)) {
			$figcaption = self::figcaption($caption);
			return self::figure($image . $figcaption, $options['figureAttrs'] ?? []);
		}

		return self::figure($image, $options['figureAttrs'] ?? []);
	}

	// -------------------------
	// Enhanced Link Building
	// -------------------------

	/** @param array<string,mixed> $attributes */
	public static function link(string $content, string $href, array $attributes = []): string
	{
		$attributes['href'] = $href;
		return self::element('a', $content, $attributes);
	}

	/**
	 * @param array<array<string,mixed>|string> $links
	 * @param array<string,mixed> $attributes
	 */
	public static function linkList(array $links, array $attributes = []): string
	{
		if (empty($links)) {
			return '';
		}

		$linkElements = [];
		foreach ($links as $link) {
			$linkHtml = is_array($link)
				? self::link($link['text'], $link['href'], $link['attributes'] ?? [])
				: $link; // Already HTML
			$linkElements[] = self::element('li', $linkHtml);
		}

		$attributes['class'] = self::mergeClasses($attributes['class'] ?? '', 'cms-link-list');
		return self::element('ul', implode('', $linkElements), $attributes);
	}
}
