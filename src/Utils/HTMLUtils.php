<?php

namespace TotalCMS\Utils;

/**
 * HTML Utilities.
 */
class HTMLUtils
{
	/** @param array<string,?string> $attributes */
	public static function createHTMLElement(string $tag, string $content, array $attributes = []): string
	{
		$element  = "<$tag";
		$element .= self::buildHTMLAttributes($attributes);
		$element .= ">$content</$tag>";

		return $element;
	}

	/** @param array<string,?string> $attributes */
	public static function createInlineHTMLElement(string $tag, array $attributes = []): string
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
}
