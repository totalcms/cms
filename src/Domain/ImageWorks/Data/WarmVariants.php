<?php

declare(strict_types=1);

namespace TotalCMS\Domain\ImageWorks\Data;

use InvalidArgumentException;
use TotalCMS\Domain\ImageWorks\Service\PictureCandidates;
use TotalCMS\Support\Config;

/**
 * What `imageworks:warm` was asked to make, as the route-shaped param sets
 * for any one image: the same `w`/`fm`/`p` keys the ImageWorks route builds
 * from a URL, so the warmer and a real request land on the same cache file.
 */
final readonly class WarmVariants
{
	/**
	 * @param list<int> $widths
	 * @param list<string> $formats the grid's formats, `native` still unresolved; [] with $picture means picture()'s own
	 * @param list<array<string,string|int>> $bases one per --params; a grid combines each with every cell
	 * @param list<string> $presets
	 * @param list<array<string,string|int>> $urls params copied from real URLs, `fm` already set
	 * @param list<string> $pictureFormats the `imageworks.picture` formats, used when $picture and no explicit $formats
	 * @param list<int> $pictureWidths the `imageworks.picture` widths, used when $picture and no explicit $widths
	 */
	private function __construct(
		private array $widths,
		private array $formats,
		private bool $picture,
		private array $bases,
		private array $presets,
		private array $urls,
		private array $pictureFormats,
		private array $pictureWidths,
	) {
	}

	/**
	 * @param list<int> $widths
	 * @param list<string> $formats
	 * @param list<string> $params query strings, e.g. `w=800&fit=crop`
	 * @param list<string> $presets
	 * @param list<string> $urls
	 *
	 * @throws InvalidArgumentException with a message fit to show the operator
	 */
	public static function fromOptions(array $widths, array $formats, bool $picture, array $params, array $presets, array $urls, ?Config $config): self
	{
		$grid = $widths !== [] || $picture;
		if (!$grid && $params === [] && $presets === [] && $urls === []) {
			if ($formats !== []) {
				throw new InvalidArgumentException('--formats needs --widths or --picture.');
			}
			throw new InvalidArgumentException('Give at least one of --widths, --picture, --params, --preset or --url. Add --dry-run to see how many files that would make.');
		}
		if ($formats !== [] && !$grid) {
			throw new InvalidArgumentException('--formats needs --widths or --picture.');
		}

		$formats = self::checkedFormats($formats);

		$bases = [];
		foreach ($params as $query) {
			$parsed = self::parse($query);
			if ($grid && isset($parsed['fm'])) {
				throw new InvalidArgumentException("--params \"{$query}\" cannot contain fm when a grid is used; the grid sets the format.");
			}
			$bases[] = $parsed;
		}

		$settings = PictureCandidates::settings($config);

		return new self(
			$widths,
			$formats,
			$picture,
			$bases,
			$presets,
			array_map(self::fromUrl(...), $urls),
			$settings['formats'],
			$settings['widths'],
		);
	}

	public function isEmpty(): bool
	{
		return !$this->picture && $this->widths === [] && $this->bases === [] && $this->presets === [] && $this->urls === [];
	}

	/**
	 * --widths without --picture makes only the widths asked for. A width wider
	 * than the image becomes the image's own width, as the route clamps it, and
	 * a base `w` drops the widths above it. Unlike picture(), the full-size
	 * width is never added on its own.
	 *
	 * @param list<int> $widths
	 * @param array<string,mixed> $image
	 * @param array<string,string|int> $base
	 * @return list<int>
	 */
	private static function exactWidths(array $widths, array $image, array $base): array
	{
		$ceiling = isset($base['w']) ? (int)$base['w'] : 0;
		$source  = (int)($image['width'] ?? 0);
		$out     = [];
		foreach ($widths as $w) {
			if ($w <= 0 || ($ceiling > 0 && $w > $ceiling)) {
				continue;
			}
			$out[] = $source > 0 ? min($w, $source) : $w;
		}
		$out = array_values(array_unique($out));
		sort($out);

		return $out;
	}

	/**
	 * Order: grid sets, exact params, presets, urls. Duplicates removed.
	 *
	 * @param array<string,mixed> $image
	 *
	 * @return list<array<string,string|int>>
	 */
	public function forImage(array $image): array
	{
		$sets     = [];
		$grid     = $this->widths !== [] || $this->picture;
		$native   = PictureCandidates::nativeFormat($image);

		if ($grid) {
			$widths  = $this->widths !== [] ? $this->widths : $this->pictureWidths;
			$formats = $this->gridFormats($image);
			foreach ($this->bases === [] ? [[]] : $this->bases as $base) {
				$cell = $base;
				unset($cell['w']);
				$cellWidths = $this->picture ? PictureCandidates::widths($widths, $image, $base) : self::exactWidths($widths, $image, $base);
				foreach ($cellWidths as $w) {
					// The route sets `fm` from the extension only without a preset, so a
					// preset base reaches it as {p, w, ...}; the formats collapse to one.
					if (isset($base['p'])) {
						$sets[] = $cell + ['w' => $w];
						continue;
					}
					foreach ($formats as $format) {
						$sets[] = $cell + ['w' => $w, 'fm' => $format];
					}
				}
			}
		} else {
			foreach ($this->bases as $base) {
				$sets[] = isset($base['fm']) || isset($base['p']) ? $base : $base + ['fm' => $native];
			}
		}

		foreach ($this->presets as $preset) {
			$sets[] = ['p' => $preset];
		}
		foreach ($this->urls as $url) {
			$sets[] = $url;
		}

		return self::unique($sets);
	}

	/**
	 * `--picture` alone serves what picture() does; anything explicit is taken
	 * literally, with `native` standing for the image's own format.
	 *
	 * @param array<string,mixed> $image
	 *
	 * @return list<string>
	 */
	private function gridFormats(array $image): array
	{
		if ($this->formats === [] && $this->picture) {
			return PictureCandidates::formats($this->pictureFormats, $image);
		}

		$native = PictureCandidates::nativeFormat($image);
		$given  = $this->formats === [] ? ['native'] : $this->formats;

		return array_values(array_unique(array_map(static fn (string $f): string => $f === 'native' ? $native : $f, $given)));
	}

	/**
	 * @param list<string> $formats
	 *
	 * @return list<string>
	 */
	private static function checkedFormats(array $formats): array
	{
		$out = [];
		foreach ($formats as $format) {
			$format = strtolower(trim($format));
			if ($format !== 'native' && !isset(PictureCandidates::MIME[$format])) {
				$valid = implode(', ', [...array_keys(PictureCandidates::MIME), 'native']);
				throw new InvalidArgumentException("Unknown format \"{$format}\". Use one of: {$valid}.");
			}
			$out[] = $format;
		}

		return array_values(array_unique($out));
	}

	/** @return array<string,string> */
	private static function parse(string $query): array
	{
		$parsed = [];
		parse_str(ltrim($query, '?'), $parsed);

		$out = [];
		foreach ($parsed as $key => $value) {
			$out[(string)$key] = is_scalar($value) ? (string)$value : '';
		}

		return $out;
	}

	/**
	 * What the route does with a URL: its query, plus `fm` from the file
	 * extension unless a preset `p` is given. A gallery URL ending in
	 * `/first`, `/last` or `/featured` has no extension, and its query is
	 * passed on as it is. Collection and id are ignored.
	 *
	 * @return array<string,string|int>
	 */
	private static function fromUrl(string $url): array
	{
		$parts = parse_url($url);
		$path  = is_array($parts) ? ($parts['path'] ?? '') : '';
		if (!is_array($parts) || !str_contains($path, '/imageworks/')) {
			throw new InvalidArgumentException("--url \"{$url}\" is not an ImageWorks URL; its path must contain /imageworks/.");
		}

		$params = self::parse($parts['query'] ?? '');
		if (isset($params['p']) || preg_match('#/(first|last|featured)$#', $path) === 1) {
			return $params;
		}

		$ext = strtolower(pathinfo($path, PATHINFO_EXTENSION));
		if (!isset(PictureCandidates::MIME[$ext])) {
			throw new InvalidArgumentException("--url \"{$url}\" does not end in an image format (" . implode(', ', array_keys(PictureCandidates::MIME)) . ').');
		}

		// The route overwrites any query `fm` with the extension.
		unset($params['fm']);

		return $params + ['fm' => $ext];
	}

	/**
	 * @param list<array<string,string|int>> $sets
	 *
	 * @return list<array<string,string|int>>
	 */
	private static function unique(array $sets): array
	{
		$seen = [];
		$out  = [];
		foreach ($sets as $set) {
			$normal = array_map(strval(...), $set);
			ksort($normal);
			$key = json_encode($normal);
			if (isset($seen[$key])) {
				continue;
			}
			$seen[$key] = true;
			$out[]      = $set;
		}

		return $out;
	}
}
