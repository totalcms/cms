<?php

declare(strict_types=1);

namespace TotalCMS\Domain\Builder\Util;

/**
 * Build a nested folder tree from a flat list of slash-delimited paths.
 *
 * Used by the Builder admin sidebar to render templates organized by folder
 * (e.g. "blog/post" appears as a "blog" folder containing "post"). Folders
 * sort before files; both alphabetical.
 *
 * Output node shape:
 *   ['type' => 'folder', 'name' => string, 'children' => Node[]]
 *   ['type' => 'file',   'name' => string, 'id' => string, 'path' => string]
 *
 * `id` is the relative template id (e.g. "blog/post"); `path` includes the
 * configured prefix (e.g. "pages/blog/post").
 */
final class NestedFileTree
{
	/**
	 * @param list<string> $paths      E.g. ["about", "blog/post", "blog/index"]
	 * @param string       $pathPrefix Prepended to each file's `path` (typically a category like "pages")
	 *
	 * @return list<array<string,mixed>>
	 */
	public static function build(array $paths, string $pathPrefix = ''): array
	{
		return self::recurse($paths, $pathPrefix, '');
	}

	/**
	 * @param list<string> $paths
	 *
	 * @return list<array<string,mixed>>
	 */
	private static function recurse(array $paths, string $pathPrefix, string $currentDir): array
	{
		/** @var array<string,list<string>> $folderPaths */
		$folderPaths = [];
		/** @var list<string> $fileNames */
		$fileNames = [];

		foreach ($paths as $path) {
			if (!str_contains($path, '/')) {
				$fileNames[] = $path;

				continue;
			}
			[$first, $rest]        = explode('/', $path, 2);
			$folderPaths[$first][] = $rest;
		}

		ksort($folderPaths);
		sort($fileNames);

		$result = [];

		foreach ($folderPaths as $name => $subPaths) {
			$childDir = $currentDir === '' ? $name : $currentDir . '/' . $name;
			$result[] = [
				'type'     => 'folder',
				'name'     => $name,
				'children' => self::recurse($subPaths, $pathPrefix, $childDir),
			];
		}

		foreach ($fileNames as $name) {
			$id       = $currentDir === '' ? $name : $currentDir . '/' . $name;
			$path     = $pathPrefix === '' ? $id : $pathPrefix . '/' . $id;
			$result[] = [
				'type' => 'file',
				'name' => $name,
				'id'   => $id,
				'path' => $path,
			];
		}

		return $result;
	}
}
