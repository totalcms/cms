<?php

declare(strict_types=1);

namespace TotalCMS\Domain\Mcp\Service;

/**
 * Keeps extensions written against mcp/sdk 0.9 loading on 0.10.
 *
 * 0.10 split the SDK into `Mcp\Schema`, `Mcp\Client` and `Mcp\Server`, so
 * `Mcp\Exception\ToolCallException` (which the extension docs told authors
 * to throw) became `Mcp\Server\Exception\ToolCallException`. An extension
 * still naming the old class would fail with "class not found" the first
 * time a tool reported an error. This autoloader answers an old name with
 * an alias of the new class, so `catch` and `instanceof` see one class.
 *
 * It only runs for a name nothing else could load, and only for the
 * namespaces 0.10 moved. Remove it once extensions have had a release or
 * two to move to the new names.
 */
final class LegacySdkNames
{
	/** Old namespace prefix => new prefixes, tried in order. */
	private const MOVES = [
		'Mcp\\Exception\\'  => ['Mcp\\Server\\Exception\\', 'Mcp\\Schema\\Exception\\', 'Mcp\\Client\\Exception\\'],
		'Mcp\\Capability\\' => ['Mcp\\Server\\Capability\\'],
		'Mcp\\Event\\'      => ['Mcp\\Server\\Event\\'],
	];

	/** Old names that do not follow a prefix move. */
	private const RENAMES = [
		'Mcp\\JsonRpc\\MessageFactory'       => 'Mcp\\Schema\\MessageFactory',
		// The interface every SDK exception implements, as the old one was.
		'Mcp\\Exception\\ExceptionInterface' => 'Mcp\\Schema\\Exception\\ExceptionInterface',
	];

	private static bool $registered = false;

	public static function register(): void
	{
		if (self::$registered) {
			return;
		}
		self::$registered = true;

		spl_autoload_register(static function (string $class): void {
			$target = self::newNameFor($class);
			if ($target !== null) {
				class_alias($target, $class);
			}
		});
	}

	/** The 0.10 class an old name now lives at, or null when it is not an old name. */
	public static function newNameFor(string $class): ?string
	{
		if (isset(self::RENAMES[$class])) {
			return self::RENAMES[$class];
		}

		foreach (self::MOVES as $old => $candidates) {
			if (!str_starts_with($class, $old)) {
				continue;
			}

			$rest = substr($class, strlen($old));
			foreach ($candidates as $new) {
				if (class_exists($new . $rest) || interface_exists($new . $rest)) {
					return $new . $rest;
				}
			}
		}

		return null;
	}
}
