<?php

declare(strict_types=1);

namespace TotalCMS\Domain\Mcp\Tool\Compat;

/**
 * Encodes T3's two-part object identity — (collection, id) — into the single
 * opaque string ChatGPT's `fetch` accepts, and back.
 *
 * Format: "{collection}:{id}". Collection ids and object slugs are
 * [a-z0-9_-]-shaped and never contain a colon, so the split is unambiguous;
 * we still split on the FIRST colon as the defined contract.
 */
final readonly class CompositeObjectId
{
	// Static utility — not instantiable.
	private function __construct()
	{
	}

	public static function encode(string $collection, string $id): string
	{
		return $collection . ':' . $id;
	}

	/**
	 * @return array{0: string, 1: string}|null  [collection, id], or null if malformed
	 */
	public static function split(string $composite): ?array
	{
		$pos = strpos($composite, ':');
		if ($pos === false) {
			return null;
		}

		$collection = substr($composite, 0, $pos);
		$id         = substr($composite, $pos + 1);

		if ($collection === '' || $id === '') {
			return null;
		}

		return [$collection, $id];
	}
}
