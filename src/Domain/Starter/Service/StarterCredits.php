<?php

declare(strict_types=1);

namespace TotalCMS\Domain\Starter\Service;

/**
 * The starter's CREDITS.md, read from the images it ships: every image or
 * gallery image with a `credit` is listed under its source, and the ones
 * without are returned so the export can name them for the author.
 *
 * Only JSON objects are read; a collection stored as Markdown keeps its
 * images in YAML frontmatter, which this does not parse.
 */
final class StarterCredits
{
	/** Marks a CREDITS.md this class wrote, so a re-export may replace it. */
	public const MARKER = '<!-- Written by tcms starter:export from the image credits. Edit the credits in the admin and export again. -->';

	/** @var array<string,list<string>> source => markdown lines */
	private array $bySource = [];

	/** @var list<string> "collection/id: name" of images without a credit */
	private array $uncredited = [];

	/**
	 * Read every object under the exported data folder.
	 */
	public function collect(string $datadir): self
	{
		foreach (glob($datadir . '/*/*.json') ?: [] as $file) {
			if (str_starts_with(basename($file), '.') || str_contains($file, '/.system/')) {
				continue;
			}

			$object = json_decode((string)file_get_contents($file), true);
			if (is_array($object)) {
				$this->walk($object, basename(dirname($file)) . '/' . basename($file, '.json'));
			}
		}

		return $this;
	}

	public function count(): int
	{
		return array_sum(array_map(count(...), $this->bySource));
	}

	/** @return list<string> */
	public function uncredited(): array
	{
		return $this->uncredited;
	}

	public function markdown(): string
	{
		$out = "# Credits\n\n" . self::MARKER . "\n";
		if ($this->bySource === []) {
			return $out . "\nNo image in this starter has a credit yet. Add one in each image's Credit section.\n";
		}

		ksort($this->bySource, SORT_NATURAL | SORT_FLAG_CASE);
		foreach ($this->bySource as $source => $lines) {
			$out .= "\n## {$source}\n\n" . implode("\n", array_unique($lines)) . "\n";
		}

		return $out;
	}

	/**
	 * An image is any map with a name and an image MIME type, at any depth,
	 * so card, deck and gallery images are found alongside top-level ones.
	 * A file field's upload can be an image too, such as the Site SEO record's
	 * SVG favicon, but a file has no Credit section to fill in, so it is
	 * neither listed nor named as missing one.
	 *
	 * @param array<mixed> $value
	 */
	private function walk(array $value, string $where): void
	{
		if (is_string($value['name'] ?? null) && str_starts_with((string)($value['mime'] ?? ''), 'image/')) {
			if (!$this->isFileUpload($value)) {
				$this->add($value, $where);
			}

			return;
		}

		foreach ($value as $child) {
			if (is_array($child)) {
				$this->walk($child, $where);
			}
		}
	}

	/**
	 * A file or depot field's upload: it carries a download name and an
	 * extension, which image and gallery fields never store.
	 *
	 * @param array<mixed> $value
	 */
	private function isFileUpload(array $value): bool
	{
		return !array_key_exists('credit', $value)
			&& (array_key_exists('download', $value) || array_key_exists('ext', $value));
	}

	/** @param array<mixed> $image */
	private function add(array $image, string $where): void
	{
		$credit = is_array($image['credit'] ?? null) ? $image['credit'] : [];
		$author = trim((string)($credit['author'] ?? ''));
		$source = trim((string)($credit['source'] ?? ''));
		if ($author === '' && $source === '') {
			$this->uncredited[] = "{$where}: {$image['name']}";

			return;
		}

		$line = '- ' . $this->link((string)$image['name'], (string)($credit['sourceUrl'] ?? ''));
		if ($author !== '') {
			$line .= ' by ' . $this->link($author, (string)($credit['authorUrl'] ?? ''));
		}
		if (trim((string)($credit['license'] ?? '')) !== '') {
			$line .= ', ' . trim((string)$credit['license']);
		}

		$this->bySource[$source !== '' ? $source : 'Other'][] = $line;
	}

	private function link(string $text, string $url): string
	{
		$text = str_replace(['[', ']'], ['\[', '\]'], $text);

		return preg_match('#^https?://#i', $url) === 1 ? "[{$text}]({$url})" : $text;
	}
}
