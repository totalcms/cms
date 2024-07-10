<?php

namespace TotalCMS\Utils;

/**
 * HTML Utilities.
 */
class HTMLUtils
{
	/** @param array<string,?string> $attributes */
	public static function element(string $tag, string $content, array $attributes = []): string
	{
		$element  = "<$tag";
		$element .= self::buildHTMLAttributes($attributes);
		$element .= ">$content</$tag>";

		return $element;
	}

	/** @param array<string,?string> $attributes */
	public static function inlineElement(string $tag, array $attributes = []): string
	{
		$element  = "<$tag";
		$element .= self::buildHTMLAttributes($attributes);
		$element .= '/>';

		return $element;
	}

	/** @param array<string,?string> $attributes */
	public static function buildHTMLAttributes(array $attributes = []): string
	{
		$element = '';

		foreach ($attributes as $attr => $value) {
			if (empty($value)) {
				$element .= " $attr";
				continue;
			}
			$element .= " $attr=\"" . htmlspecialchars($value, ENT_QUOTES, 'UTF-8') . '"';
		}

		return $element;
	}

	public static function details(string $title, string $content, string $class = ''): string
	{
		$summary = self::element('summary', $title);
		$details = self::element('details', $summary . $content, ['class' => "cms-accordion $class"]);

		return $details;
	}

	public static function dialog(string $content, string $class = ''): string
	{
		return self::element('dialog', $content, ['class' => "cms-modal $class"]);
	}

	public static function iframe(string $url, string $class = ''): string
	{
		return self::element('iframe', '', [
			'style'       => 'width:100%;height:100%',
			'data-src'    => $url,
			'frameborder' => '0',
			'class'       => "cms-iframe $class",
		]);
	}

	public static function scroller(string $content): string
	{
		return self::element('section', $content, ['class' => "scroller"]);
	}

	/** @param array<string,?string> $attributes */
	public static function button(string $content, array $attributes = []): string
	{
		$attributes = array_merge([
			'type' => 'button',
		], $attributes);

		$attributes['class'] = "button btn " . ($attributes['class'] ?? '');

		return self::element('button', $content, $attributes);
	}
}
