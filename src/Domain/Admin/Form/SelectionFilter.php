<?php

declare(strict_types=1);

namespace TotalCMS\Domain\Admin\Form;

/**
 * Read the ticked values of a multicheckbox (named `<key>`, submitted as `<key>[]`)
 * from request query or body params. Shared so any action that drives a
 * multicheckbox selection parses it the same way.
 */
final class SelectionFilter
{
	/**
	 * @param array<string,mixed> $params
	 *
	 * @return list<string>
	 */
	public static function ticked(array $params, string $key): array
	{
		return array_values(array_filter(
			array_map(strval(...), (array)($params[$key] ?? [])),
			static fn (string $id): bool => $id !== '',
		));
	}
}
