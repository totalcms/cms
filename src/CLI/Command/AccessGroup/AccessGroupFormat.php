<?php

declare(strict_types=1);

namespace TotalCMS\CLI\Command\AccessGroup;

/**
 * How the access group commands show a permission section in a terminal.
 */
final class AccessGroupFormat
{
	/**
	 * A targeted section in one line: its operations, then where they apply,
	 * e.g. "read, update on blog, news" or "read on all".
	 *
	 * @param array<mixed> $section
	 */
	public static function reach(array $section): string
	{
		$operations = array_filter((array)($section['operations'] ?? []), is_string(...));
		if ($operations === []) {
			return 'none';
		}

		$allowed = array_filter((array)($section['allowed'] ?? []), is_string(...));
		$where   = ($section['all'] ?? false) === true ? 'all' : ($allowed === [] ? 'nothing' : implode(', ', $allowed));

		return implode(', ', $operations) . ' on ' . $where;
	}
}
