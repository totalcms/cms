<?php

declare(strict_types=1);

namespace TotalCMS\Domain\Mcp\Tool\Admin;

use Mcp\Exception\ToolCallException;
use TotalCMS\Domain\Property\Service\MediaMetaPolicy;

/**
 * An agent's edit to an image, file, gallery or depot field, laid over the
 * stored value so only descriptive metadata changes ({@see MediaMetaPolicy}).
 *
 * Agents cannot upload, replace, add or remove files. They edit what is
 * already there:
 *
 *   - image / file: an object of keys, e.g. `{"alt": "Team photo"}`;
 *   - gallery: a list of images matched to stored ones by `name`; a list
 *     naming every stored image once also sets their order;
 *   - depot: a list of files matched by `path` (`folder/name.pdf`, or just
 *     the name at the top level).
 *
 * Every refusal is a ToolCallException that names the field and what the
 * agent may send instead.
 */
final readonly class MediaMetaEdit
{
	public function __construct(
		private string $toolName,
		private string $collection,
	) {
	}

	/**
	 * The field's new stored value.
	 *
	 * @return array<string,mixed>|list<array<string,mixed>>
	 */
	public function apply(string $property, string $type, mixed $stored, mixed $edit): array
	{
		$stored = is_array($stored) ? $stored : [];
		if (!is_array($edit)) {
			$this->refuse($property, $type, 'expects ' . self::shape($type));
		}

		return match ($type) {
			'gallery' => $this->editList($property, $type, array_values($stored), $edit),
			'depot'   => $this->editDepot($property, $stored, $edit),
			default   => $this->editOne($property, $type, $stored, $edit),
		};
	}

	/**
	 * How to describe the field to an agent: the edit's shape and its keys.
	 */
	public static function hint(string $type): string
	{
		return sprintf('%s; editable keys: %s', self::shape($type), implode(', ', MediaMetaPolicy::editableKeys($type)));
	}

	/**
	 * @param array<string,mixed> $stored
	 * @param array<mixed>        $edit
	 *
	 * @return array<string,mixed>
	 */
	private function editOne(string $property, string $type, array $stored, array $edit): array
	{
		if ((string)($stored['name'] ?? '') === '') {
			$this->refuse($property, $type, 'has no uploaded file to describe yet; upload one in the admin first');
		}
		if (array_is_list($edit) && $edit !== []) {
			$this->refuse($property, $type, 'expects ' . self::shape($type));
		}

		return $this->mergeItem($property, $type, $stored, $edit);
	}

	/**
	 * @param list<mixed>  $stored
	 * @param array<mixed> $edit
	 *
	 * @return list<array<string,mixed>>
	 */
	private function editList(string $property, string $type, array $stored, array $edit): array
	{
		$names = array_map(static fn (mixed $item): string => is_array($item) ? (string)($item['name'] ?? '') : '', $stored);
		$edits = $this->editsBy($property, $type, $edit, 'name');
		foreach ($edits as $name => $item) {
			$index = array_search($name, $names, true);
			if ($index === false) {
				$this->refuse($property, $type, sprintf('has no image named "%s"; images here: %s', $name, implode(', ', $names) ?: '(none)'));
			}
			$stored[$index] = $this->mergeItem($property, $type, (array)$stored[$index], $item);
		}

		// Naming every stored image exactly once is a reorder: the payload's
		// order becomes the stored order. Nothing can be added or dropped that
		// way, so a partial list keeps the stored order as before.
		if (count($edit) === count($names) && count($edits) === count($names)) {
			$byName = array_combine($names, $stored);
			$stored = array_map(static fn (string $name): mixed => $byName[$name], array_map(strval(...), array_keys($edits)));
		}

		return array_values(array_filter($stored, is_array(...)));
	}

	/**
	 * @param array<string,mixed> $stored
	 * @param array<mixed>        $edit
	 *
	 * @return array<string,mixed>
	 */
	private function editDepot(string $property, array $stored, array $edit): array
	{
		$edits = $this->editsBy($property, 'depot', $edit, 'path');
		$found = [];
		$files = $this->walkDepot($property, is_array($stored['files'] ?? null) ? $stored['files'] : [], '', $edits, $found);

		$missing = array_diff(array_keys($edits), $found);
		if ($missing !== []) {
			$this->refuse($property, 'depot', sprintf('has no file at "%s"; use get_object to see the files and their folders', reset($missing)));
		}

		$stored['files'] = $files;

		return $stored;
	}

	/**
	 * The depot's files with each edit applied to the file at its path.
	 *
	 * @param array<mixed>                       $files
	 * @param array<string,array<string,mixed>> $edits path => edit
	 * @param list<string>                       $found the paths an edit landed on
	 *
	 * @return list<mixed>
	 */
	private function walkDepot(string $property, array $files, string $prefix, array $edits, array &$found): array
	{
		foreach ($files as $index => $file) {
			if (!is_array($file)) {
				continue;
			}
			$path = ltrim($prefix . '/' . ($file['name'] ?? ''), '/');
			if (($file['mime'] ?? '') === 'folder') {
				$files[$index]['files'] = $this->walkDepot($property, is_array($file['files'] ?? null) ? $file['files'] : [], $path, $edits, $found);
			} elseif (isset($edits[$path])) {
				$files[$index] = $this->mergeItem($property, 'depot', $file, $edits[$path]);
				$found[]       = $path;
			}
		}

		return array_values($files);
	}

	/**
	 * The list's edits keyed by $key (`name` or `path`). A depot path may
	 * come as `name` for a top-level file.
	 *
	 * @param array<mixed> $edit
	 *
	 * @return array<string,array<string,mixed>>
	 */
	private function editsBy(string $property, string $type, array $edit, string $key): array
	{
		if (!array_is_list($edit)) {
			$this->refuse($property, $type, 'expects ' . self::shape($type));
		}

		$byKey = [];
		foreach ($edit as $item) {
			$id = is_array($item) ? trim((string)($item[$key] ?? $item['name'] ?? ''), '/') : '';
			if ($id === '') {
				$this->refuse($property, $type, sprintf('needs "%s" on every item, to say which one to edit', $key));
			}
			unset($item['path']);
			$byKey[$id] = $item;
		}

		return $byKey;
	}

	/**
	 * @param array<string,mixed> $stored
	 * @param array<mixed>        $edit
	 *
	 * @return array<string,mixed>
	 */
	private function mergeItem(string $property, string $type, array $stored, array $edit): array
	{
		$refused = MediaMetaPolicy::refusedKeys($type, $stored, $edit);
		if ($refused !== []) {
			$this->refuse($property, $type, sprintf(
				'cannot change [%s] over MCP: only descriptive metadata is editable (%s). Files are uploaded, replaced and protected in the admin',
				implode(', ', $refused),
				implode(', ', MediaMetaPolicy::editableKeys($type)),
			));
		}

		return MediaMetaPolicy::merge($type, $stored, $edit);
	}

	private static function shape(string $type): string
	{
		return match ($type) {
			'gallery' => 'a list of images, each with the "name" of a stored image; name every image once to set their order',
			'depot'   => 'a list of files, each with the "path" of a stored file (folder/name)',
			default   => 'an object of metadata keys',
		};
	}

	private function refuse(string $property, string $type, string $reason): never
	{
		throw new ToolCallException(sprintf('%s: %s field "%s" in "%s" %s.', $this->toolName, $type, $property, $this->collection, $reason));
	}
}
