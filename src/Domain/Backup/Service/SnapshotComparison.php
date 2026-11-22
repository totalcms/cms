<?php

declare(strict_types=1);

namespace TotalCMS\Domain\Backup\Service;

use TotalCMS\Domain\Schema\Data\PropertyDefinition;

/**
 * What changed between two states of a record, field by field, in a shape a
 * page can print.
 *
 * Each change names the top-level property it belongs to (the unit one field
 * is restored by), a label built from the schema ("Team › alice › Photo"), a
 * kind, and the values for that kind:
 *
 * - `text`: a short value, before and after.
 * - `long`: a long value, or one with line breaks; both versions in full.
 * - `secret`: a password or a server-only field. Only that it changed: the
 *   values are never put in the result, so they cannot reach a browser.
 *   `password` says which of the two it is.
 * - `file`: an image or a file. The names and sizes, and `path`, the folder
 *   under the property the file sits in (a card child, a deck item's child,
 *   a video's poster), so the caller can ask whether it can be brought back.
 * - `files`: a gallery or a depot. The files `added` and `removed`, each
 *   with its folder.
 *
 * A card and a deck are compared child by child with their own schema. A deck
 * item that was added or removed shows as its children coming or going.
 *
 * It only reads arrays: no storage, no settings.
 */
final readonly class SnapshotComparison
{
	/** A value longer than this, or one with a line break, is shown whole instead of on one line. */
	private const LONG_AFTER = 120;

	/**
	 * @param \Closure(array<string,mixed>): array<string,array<string,mixed>> $children the child properties of a card or deck definition, from its schema
	 */
	public function __construct(private \Closure $children)
	{
	}

	/**
	 * @param array<string,mixed> $properties the schema's properties, in the schema's order
	 * @param array<string,mixed> $before
	 * @param array<string,mixed> $after
	 *
	 * @return list<array<string,mixed>>
	 */
	public function compare(array $properties, array $before, array $after): array
	{
		$changes = [];
		$names   = array_unique([...array_keys($properties), ...array_keys($before), ...array_keys($after)]);

		foreach ($names as $name) {
			$name       = (string)$name;
			$definition = is_array($properties[$name] ?? null) ? $properties[$name] : [];
			$label      = is_string($definition['label'] ?? null) && $definition['label'] !== '' ? $definition['label'] : $name;

			array_push($changes, ...$this->field($name, '', $label, $definition, $before[$name] ?? null, $after[$name] ?? null));
		}

		return $changes;
	}

	/**
	 * The changes of one field: none, one, or several for a card or a deck.
	 *
	 * @param array<string,mixed> $definition
	 *
	 * @return list<array<string,mixed>>
	 */
	private function field(string $property, string $path, string $label, array $definition, mixed $before, mixed $after): array
	{
		if ($before == $after) {
			return [];
		}

		$type = $definition === [] ? '' : PropertyDefinition::fromArray($definition)->resolveType();
		$base = ['property' => $property, 'path' => '', 'label' => $label];

		if ($type === 'password' || ($definition['serverOnly'] ?? false) === true) {
			return [$base + ['kind' => 'secret', 'before' => '', 'after' => '', 'password' => $type === 'password']];
		}
		if (($definition['settings']['onUpdate'] ?? false) === true) {
			// Stamps itself on every save: every snapshot would list it.
			return [];
		}

		return match ($type) {
			'image', 'file' => [$this->file($base, $path, $before, $after)],
			'gallery'       => [$base + ['kind' => 'files'] + $this->listed($this->pictures($before), $this->pictures($after))],
			'depot'         => [$base + ['kind' => 'files'] + $this->listed($this->depotFiles($before), $this->depotFiles($after))],
			'card'          => $this->children($property, $path, $label, $definition, $this->record($before), $this->record($after)),
			'deck'          => $this->deck($property, $label, $definition, $this->record($before), $this->record($after)),
			'video'         => $this->video($property, $path, $label, $this->record($before), $this->record($after)),
			default         => [$this->value($base, $before, $after)],
		};
	}

	/**
	 * @param array<string,mixed> $base
	 *
	 * @return array<string,mixed>
	 */
	private function value(array $base, mixed $before, mixed $after): array
	{
		$old  = $this->display($before);
		$new  = $this->display($after);
		$long = false;

		// By what the value is, not by its field: a one-line note in a textarea
		// reads better on one line.
		foreach ([$old, $new] as $text) {
			$long = $long || strlen($text) > self::LONG_AFTER || str_contains($text, "\n");
		}

		return $base + ['kind' => $long ? 'long' : 'text', 'before' => $old, 'after' => $new];
	}

	/**
	 * @param array<string,mixed> $base
	 *
	 * @return array<string,mixed>
	 */
	private function file(array $base, string $path, mixed $before, mixed $after): array
	{
		[$oldName, $oldSize] = $this->named($before);
		[$newName, $newSize] = $this->named($after);

		// The same file with other details (alt text, a focal point) moved no file.
		if ($oldName === $newName && $oldSize === $newSize) {
			return $base + ['kind' => 'text', 'before' => $oldName, 'after' => $newName . ' (details changed)'];
		}

		return ['path' => $path] + $base + ['kind' => 'file', 'before' => $oldName, 'beforeSize' => $oldSize, 'after' => $newName, 'afterSize' => $newSize];
	}

	/**
	 * @param array<string,array{path:string,name:string,size:int|null}> $before keyed by folder and name
	 * @param array<string,array{path:string,name:string,size:int|null}> $after
	 *
	 * @return array{added:list<array{path:string,name:string,size:int|null}>, removed:list<array{path:string,name:string,size:int|null}>}
	 */
	private function listed(array $before, array $after): array
	{
		return [
			'added'   => array_values(array_diff_key($after, $before)),
			'removed' => array_values(array_diff_key($before, $after)),
		];
	}

	/**
	 * The children of a card, or of one deck item, compared with their own schema.
	 *
	 * @param array<string,mixed> $definition
	 * @param array<string,mixed> $before
	 * @param array<string,mixed> $after
	 *
	 * @return list<array<string,mixed>>
	 */
	private function children(string $property, string $path, string $label, array $definition, array $before, array $after): array
	{
		$schema  = ($this->children)($definition);
		$changes = [];

		foreach (array_unique([...array_keys($schema), ...array_keys($before), ...array_keys($after)]) as $child) {
			$child = (string)$child;
			if ($child === 'id') {
				continue;
			}

			$childDefinition = is_array($schema[$child] ?? null) ? $schema[$child] : [];
			$childLabel      = is_string($childDefinition['label'] ?? null) && $childDefinition['label'] !== '' ? $childDefinition['label'] : $child;

			array_push($changes, ...$this->field($property, ltrim($path . '/' . $child, '/'), $label . ' › ' . $childLabel, $childDefinition, $before[$child] ?? null, $after[$child] ?? null));
		}

		return $changes;
	}

	/**
	 * @param array<string,mixed> $definition
	 * @param array<string,mixed> $before
	 * @param array<string,mixed> $after
	 *
	 * @return list<array<string,mixed>>
	 */
	private function deck(string $property, string $label, array $definition, array $before, array $after): array
	{
		$changes = [];
		foreach (array_unique([...array_keys($before), ...array_keys($after)]) as $item) {
			$item = (string)$item;
			array_push($changes, ...$this->children($property, $item, $label . ' › ' . $item, $definition, $this->record($before[$item] ?? null), $this->record($after[$item] ?? null)));
		}

		return $changes;
	}

	/**
	 * A video: its link as text, its poster as a file under it.
	 *
	 * @param array<string,mixed> $before
	 * @param array<string,mixed> $after
	 *
	 * @return list<array<string,mixed>>
	 */
	private function video(string $property, string $path, string $label, array $before, array $after): array
	{
		$changes = [];
		$base    = ['property' => $property, 'path' => '', 'label' => $label];

		if (($before['url'] ?? '') != ($after['url'] ?? '')) {
			$changes[] = $base + ['kind' => 'text', 'before' => $this->display($before['url'] ?? ''), 'after' => $this->display($after['url'] ?? '')];
		}
		if (($before['poster'] ?? null) != ($after['poster'] ?? null)) {
			$changes[] = $this->file(['label' => $label . ' › Poster'] + $base, ltrim($path . '/poster', '/'), $before['poster'] ?? null, $after['poster'] ?? null);
		}

		return $changes;
	}

	/** @return array{0:string, 1:int|null} a file record's name and size */
	private function named(mixed $file): array
	{
		$file = $this->record($file);
		$size = is_numeric($file['size'] ?? null) ? (int)$file['size'] : 0;

		return [is_string($file['name'] ?? null) ? $file['name'] : '', $size > 0 ? $size : null];
	}

	/** @return array<string,array{path:string,name:string,size:int|null}> */
	private function pictures(mixed $gallery): array
	{
		$pictures = [];
		foreach (is_array($gallery) ? $gallery : [] as $picture) {
			[$name, $size] = $this->named($picture);
			if ($name !== '') {
				$pictures['/' . $name] = ['path' => '', 'name' => $name, 'size' => $size];
			}
		}

		return $pictures;
	}

	/** @return array<string,array{path:string,name:string,size:int|null}> */
	private function depotFiles(mixed $depot, string $folder = ''): array
	{
		$entries = $folder === '' ? ($this->record($depot)['files'] ?? []) : $depot;
		$files   = [];

		foreach (is_array($entries) ? $entries : [] as $entry) {
			$entry = $this->record($entry);
			[$name, $size] = $this->named($entry);
			if ($name === '') {
				continue;
			}
			if (($entry['mime'] ?? '') === 'folder') {
				$files += $this->depotFiles($entry['files'] ?? [], ltrim($folder . '/' . $name, '/') ?: $name);

				continue;
			}
			$files[$folder . '/' . $name] = ['path' => $folder, 'name' => $name, 'size' => $size];
		}

		return $files;
	}

	/** @return array<string,mixed> */
	private function record(mixed $value): array
	{
		return is_array($value) ? $value : [];
	}

	/** A value on one line: yes or no, a number, a comma list, or compact JSON. */
	private function display(mixed $value): string
	{
		return match (true) {
			$value === null                                         => '',
			is_bool($value)                                         => $value ? 'Yes' : 'No',
			is_scalar($value)                                       => (string)$value,
			is_array($value) && array_is_list($value)
				&& array_filter($value, is_scalar(...)) === $value  => implode(', ', array_map(strval(...), $value)),
			default                                                 => (string)json_encode($value, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
		};
	}
}
