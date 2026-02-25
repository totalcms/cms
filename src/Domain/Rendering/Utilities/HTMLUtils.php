<?php

namespace TotalCMS\Domain\Rendering\Utilities;

use TotalCMS\Domain\Twig\Extension\TotalCMSTwigFilters;

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

		return $element . ">$content</$tag>";
	}

	/** @param array<string,mixed> $attributes */
	public static function inlineElement(string $tag, array $attributes = []): string
	{
		$element  = "<$tag";
		$element .= self::buildHTMLAttributes($attributes);

		return $element . '/>';
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

	// Useful for obfuscating email addresses
	public static function htmlencode(string $content): string
	{
		$encoded = '';
		foreach (str_split($content) as $char) {
			$encoded .= '&#' . ord($char) . ';';
		}

		return $encoded;
	}

	public static function mailtoLink(
		string $email,
		string $subject = '',
		string $body    = '',
		string $cc      = '',
		string $bcc     = '',
		string $title   = '',
	): string {
		$email = trim($email);

		// Encode the email parts
		$emailParts = explode('@', $email);
		if (count($emailParts) !== 2) {
			// Invalid email, return encoded version
			return self::element('span', self::htmlencode($email), ['class' => 'invalid-email']);
		}

		$user   = $emailParts[0];
		$domain = $emailParts[1];

		// Create data attributes for the email parts
		$dataAttrs = [
			'data-user'   => base64_encode($user),
			'data-domain' => base64_encode($domain),
		];

		// Add optional parameters if provided
		if ($subject !== '') {
			$dataAttrs['data-subject'] = base64_encode(trim($subject));
		}
		if ($body !== '') {
			$dataAttrs['data-body'] = base64_encode(trim($body));
		}
		if ($cc !== '') {
			$dataAttrs['data-cc'] = base64_encode(trim($cc));
		}
		if ($bcc !== '') {
			$dataAttrs['data-bcc'] = base64_encode(trim($bcc));
		}

		// Default title
		if ($title === '') {
			$title = $subject === '' ? 'Email' : htmlentities($subject);
		}

		// Create a span that will be converted to a link via JavaScript
		return self::element('span', self::htmlencode($email), array_merge([
			'class' => 'mailto-obfuscated',
			'title' => $title,
			'style' => 'cursor:pointer;text-decoration:underline;',
		], $dataAttrs));
	}

	/** @param array<string,mixed> $attributes */
	public static function details(string $title, string $content, string $class = '', array $attributes = []): string
	{
		$attributes['class'] = "cms-accordion $class";

		$summary = self::element('summary', $title);
		$content = self::element('div', $content, ['class' => 'content']);

		return self::element('details', $summary . $content, $attributes);
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
	public static function mergeClasses(string ...$classes): string
	{
		return implode(' ', array_filter(array_map(trim(...), $classes)));
	}

	/**
	 * @param array<string,mixed> $data
	 *
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
	 *
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

	/**
	 * Build HTMX attribute array for an action request.
	 *
	 * @param array<string,mixed> $options
	 *
	 * @return array<string,string>
	 */
	public static function htmxAttributes(string $url, string $method = 'get', array $options = []): array
	{
		$attrs = [
			'hx-' . strtolower($method) => $url,
			'hx-swap' => (string)($options['swap'] ?? 'none'),
		];

		if (!empty($options['confirm'])) {
			$attrs['hx-confirm'] = (string)$options['confirm'];
		}
		if (!empty($options['trigger'])) {
			$attrs['hx-trigger'] = (string)$options['trigger'];
		}
		if (!empty($options['target'])) {
			$attrs['hx-target'] = (string)$options['target'];
		}
		if (!empty($options['select'])) {
			$attrs['hx-select'] = (string)$options['select'];
		}
		if (isset($options['on']) && is_array($options['on'])) {
			foreach ($options['on'] as $event => $handler) {
				$attrs['hx-on:htmx:' . $event] = (string)$handler;
			}
		}

		return $attrs;
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
	public static function time(string $date, string $format = 'relative', array $attributes = []): string
	{
		// Use existing Chronos date filters
		$formatted = match ($format) {
			'relative' => TotalCMSTwigFilters::dateRelative($date),
			'short'    => TotalCMSTwigFilters::dateFormat($date, 'M j, Y'),
			'long'     => TotalCMSTwigFilters::dateFormat($date, 'F j, Y'),
			'iso'      => TotalCMSTwigFilters::dateFormat($date, 'c'),
			default    => TotalCMSTwigFilters::dateFormat($date, $format),
		};

		// Get ISO datetime for the datetime attribute
		$datetime = TotalCMSTwigFilters::dateFormat($date, 'c');

		if ($datetime !== '') {
			$attributes['datetime'] = $datetime;
		}

		return self::element('time', $formatted, $attributes);
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
		if ($tags === []) {
			return '';
		}

		$tagElements = [];
		foreach ($tags as $tag) {
			if (empty($tag)) {
				continue;
			}

			$tagContent = htmlspecialchars($tag, ENT_QUOTES, 'UTF-8');

			if ($linkBase !== null && $linkBase !== '') {
				$tagUrl        = rtrim($linkBase, '/') . '/' . urlencode($tag);
				$tagElements[] = self::element('a', $tagContent, [
					'href'  => $tagUrl,
					'class' => 'tag',
				]);
				continue;
			}

			$tagElements[] = self::element('span', $tagContent, ['class' => 'tag']);
		}

		$attributes['class'] = self::mergeClasses($attributes['class'] ?? '', 'cms-tags');

		return self::element('div', implode('', $tagElements), $attributes);
	}

	/** @param array<string,mixed> $attributes */
	public static function metaData(string $content, array $attributes = []): string
	{
		$attributes['class'] = self::mergeClasses($attributes['class'] ?? '', 'cms-meta');

		return self::element('span', $content, $attributes);
	}

	/**
	 * @param array<string,mixed> $options
	 */
	public static function imageWithCaption(string $imageSrc, string $caption = '', array $options = []): string
	{
		$imageAttrs = [
			'src'           => $imageSrc,
			'alt'           => $options['alt'] ?? '',
			'loading'       => $options['loading'] ?? 'lazy',
			'draggable'     => 'false',
			'oncontextmenu' => 'return false;',
		];

		$image = self::inlineElement('img', $imageAttrs);

		if ($caption !== '') {
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
		if ($links === []) {
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
