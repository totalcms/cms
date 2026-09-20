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
	public const FORM_NAME         = 'toolname';
	public const FORM_DESCRIPTION  = 'tooldescription';
	public const FORM_AUTOSUBMIT   = 'toolautosubmit';
	public const PARAM_TITLE       = 'toolparamtitle';
	public const PARAM_DESCRIPTION = 'toolparamdescription';

	public const NAME_MAX_LENGTH        = 64;
	public const TITLE_MAX_LENGTH       = 64;
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
	 * A parameter's title from its schema property: the label, which is the
	 * name a person reading the form sees, shortened to 64 characters.
	 *
	 * @param array<string,mixed> $property
	 */
	public static function paramTitle(array $property): string
	{
		$label = $property['label'] ?? '';

		return is_string($label) ? mb_substr(self::description($label), 0, self::TITLE_MAX_LENGTH) : '';
	}

	/**
	 * A parameter's description from its schema property: the help text,
	 * else nothing. The label is the title, not the description — a schema
	 * with only a label says what the field is called, not what to put in
	 * it, and repeating it in both attributes tells an agent nothing twice.
	 *
	 * @param array<string,mixed> $property
	 */
	public static function paramDescription(array $property): string
	{
		$help = $property['help'] ?? '';

		return is_string($help) ? self::description($help) : '';
	}
}
