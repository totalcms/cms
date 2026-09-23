<?php

declare(strict_types=1);

namespace TotalCMS\Domain\Mcp\Tool\Service;

use TotalCMS\Support\Config;

/**
 * The operator's `mcp.toolPrefix`, as the `{prefix}_` every advertised tool
 * name starts with — or empty when none is set or it fails validation. Read
 * by the server factory when it names tools and by the tools validator when
 * it checks a collection's declared tool names, so the two can never disagree.
 *
 * `resources/schemas/settings/mcp.json` carries the same rule for the
 * settings form; it cannot reference this constant, so keep them aligned.
 */
final class McpToolPrefix
{
	public const PATTERN = '/^[a-z][a-z0-9_]{0,23}$/';

	public static function fromConfig(Config $config): string
	{
		$prefix = trim((string)($config->mcp['toolPrefix'] ?? ''));

		return $prefix !== '' && preg_match(self::PATTERN, $prefix) ? $prefix . '_' : '';
	}
}
