<?php

declare(strict_types=1);

namespace TotalCMS\Domain\Extension\Service;

use TotalCMS\Domain\Extension\Data\ExtensionManifest;
use TotalCMS\Domain\Extension\Exception\EntrypointLoadException;
use TotalCMS\Domain\Extension\ExtensionInterface;

/**
 * Turns a manifest's entrypoint file into its ExtensionInterface class:
 * require the extension's own Composer autoloader when it ships one, require
 * the entrypoint, and read the class name out of the file's tokens rather
 * than guessing it from the path.
 */
final class ExtensionEntrypointLoader
{
	/**
	 * @throws EntrypointLoadException When the entrypoint is missing, declares no class, or the class is not an extension
	 *
	 * @return class-string<ExtensionInterface>
	 */
	public function load(ExtensionManifest $manifest, string $extPath): string
	{
		$autoloadFile = $extPath . '/vendor/autoload.php';
		if (is_file($autoloadFile)) {
			require_once $autoloadFile;
		}

		$entrypointFile = $extPath . '/' . $manifest->entrypoint;
		if (!is_file($entrypointFile)) {
			throw new EntrypointLoadException("Entrypoint not found: {$manifest->entrypoint}");
		}

		require_once $entrypointFile;

		$className = self::classNameIn($entrypointFile);
		if ($className === null || !class_exists($className)) {
			throw new EntrypointLoadException("Extension class not found in {$manifest->entrypoint}");
		}

		if (!is_subclass_of($className, ExtensionInterface::class)) {
			throw new EntrypointLoadException('Extension class does not implement ExtensionInterface');
		}

		return $className;
	}

	/**
	 * The fully-qualified name of the first class a PHP file declares, from
	 * its tokens. `Foo::class` constant fetches are skipped.
	 *
	 * This runs on every request for every enabled extension, and tokenizing
	 * costs about 40 ms per megabyte, so only the file up to its class
	 * declaration is tokenized. A declaration the regex cannot see (an
	 * attribute on the same line) or a false match inside a string or comment
	 * leaves that prefix without a class, and the whole file is tokenized as
	 * before.
	 */
	public static function classNameIn(string $filePath): ?string
	{
		if (!is_file($filePath)) {
			return null;
		}

		$contents = file_get_contents($filePath);
		if ($contents === false) {
			return null;
		}

		$prefix = self::declarationPrefix($contents);
		if ($prefix !== null) {
			$className = self::classNameInTokens(token_get_all($prefix));
			if ($className !== null) {
				return $className;
			}
		}

		return self::classNameInTokens(token_get_all($contents));
	}

	/**
	 * The file up to and including the first line that looks like a class
	 * declaration, or null when no line does.
	 */
	private static function declarationPrefix(string $contents): ?string
	{
		if (preg_match('/^[ \t]*(?:(?:final|abstract|readonly)\s+)*class\s+[A-Za-z_\x80-\xff][\w\x80-\xff]*/m', $contents, $match, PREG_OFFSET_CAPTURE) !== 1) {
			return null;
		}

		return substr($contents, 0, $match[0][1] + strlen($match[0][0]));
	}

	/** @param list<array{int,string,int}|string> $tokens */
	private static function classNameInTokens(array $tokens): ?string
	{
		$count     = count($tokens);
		$namespace = '';

		for ($i = 0; $i < $count; $i++) {
			$token = $tokens[$i];
			if (!is_array($token)) {
				continue;
			}

			if ($token[0] === T_NAMESPACE) {
				$namespace = self::namespaceAt($tokens, $i);

				continue;
			}

			if ($token[0] !== T_CLASS || self::isClassConstantFetch($tokens, $i)) {
				continue;
			}

			$class = self::nextStringAfter($tokens, $i);
			if ($class !== null) {
				return $namespace !== '' ? $namespace . '\\' . $class : $class;
			}
		}

		return null;
	}

	/**
	 * The namespace name declared at token $i: every name token up to the
	 * closing `;` or `{`.
	 *
	 * @param list<array{int,string,int}|string> $tokens
	 */
	private static function namespaceAt(array $tokens, int $i): string
	{
		$count = count($tokens);
		$name  = '';

		for ($j = $i + 1; $j < $count; $j++) {
			$next = $tokens[$j];
			if (is_string($next) && ($next === ';' || $next === '{')) {
				break;
			}
			if (is_array($next) && in_array($next[0], [T_STRING, T_NS_SEPARATOR, T_NAME_QUALIFIED, T_NAME_FULLY_QUALIFIED, T_NAME_RELATIVE], true)) {
				$name .= $next[1];
			}
		}

		return trim($name);
	}

	/**
	 * Whether the T_CLASS at token $i is the `class` of a `Foo::class` fetch:
	 * the previous non-whitespace, non-comment token is `::`.
	 *
	 * @param list<array{int,string,int}|string> $tokens
	 */
	private static function isClassConstantFetch(array $tokens, int $i): bool
	{
		$prev = $i - 1;
		while ($prev >= 0 && is_array($tokens[$prev]) && in_array($tokens[$prev][0], [T_WHITESPACE, T_COMMENT, T_DOC_COMMENT], true)) {
			$prev--;
		}

		return $prev >= 0 && is_array($tokens[$prev]) && $tokens[$prev][0] === T_DOUBLE_COLON;
	}

	/** @param list<array{int,string,int}|string> $tokens */
	private static function nextStringAfter(array $tokens, int $i): ?string
	{
		$count = count($tokens);

		for ($j = $i + 1; $j < $count; $j++) {
			$next = $tokens[$j];
			if (is_array($next) && $next[0] === T_STRING) {
				return $next[1];
			}
		}

		return null;
	}
}
