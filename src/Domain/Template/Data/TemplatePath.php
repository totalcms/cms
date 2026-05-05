<?php

declare(strict_types=1);

namespace TotalCMS\Domain\Template\Data;

final class TemplatePath
{
	/**
	 * Split a template path into folder and template name on the last slash.
	 * A path with no slash is treated as a bare template name with no folder.
	 *
	 * @return array{0: string|null, 1: string}
	 */
	public static function parse(string $path): array
	{
		$lastSlash = strrpos($path, '/');

		if ($lastSlash === false) {
			return [null, $path];
		}

		return [
			substr($path, 0, $lastSlash),
			substr($path, $lastSlash + 1),
		];
	}
}
