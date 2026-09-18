<?php

declare(strict_types=1);

namespace TotalCMS\Bundled\WebMcp;

/**
 * Every WebMCP-specific string in one place.
 *
 * The declarative API is still explainer-only and its attribute names may
 * move; when they do, this is the file that changes and the extension takes
 * a version bump. The string builders enforce the rule the spec's security
 * section makes non-negotiable: a tool name, description or parameter
 * description is built from schema metadata and the operator's own words,
 * never from object or user content, and never carries markup.
 */
final class WebMcpAttributes
{
	public const FORM_NAME        = 'toolname';
	public const FORM_DESCRIPTION = 'tooldescription';
	public const FORM_AUTOSUBMIT  = 'toolautosubmit';
	public const PARAM_DESCRIPTION = 'toolparamdescription';

	public const NAME_MAX_LENGTH        = 64;
	public const DESCRIPTION_MAX_LENGTH = 200;

	/**
	 * A tool name an agent can call: lower-case, `[a-z0-9_]`, at most 64
	 * characters, never empty.
	 */
	public static function toolName(string $name): string
	{
		$name = strtolower(trim($name));
		$name = (string)preg_replace('/[^a-z0-9_]+/', '_', $name);
		$name = trim((string)preg_replace('/_+/', '_', $name), '_');
		$name = substr($name, 0, self::NAME_MAX_LENGTH);

		return $name === '' ? 'form' : $name;
	}

	/**
	 * A description an agent reads: markup stripped, whitespace collapsed,
	 * at most 200 characters.
	 */
	public static function description(string $text): string
	{
		$text = html_entity_decode(strip_tags($text), ENT_QUOTES | ENT_HTML5, 'UTF-8');
		$text = trim((string)preg_replace('/\s+/u', ' ', $text));

		return mb_substr($text, 0, self::DESCRIPTION_MAX_LENGTH);
	}

	/**
	 * A parameter's description from its schema property: the help text,
	 * else the label, else nothing.
	 *
	 * @param array<string,mixed> $property
	 */
	public static function paramDescription(array $property): string
	{
		foreach (['help', 'label'] as $key) {
			$text = $property[$key] ?? '';
			if (is_string($text) && self::description($text) !== '') {
				return self::description($text);
			}
		}

		return '';
	}
}
