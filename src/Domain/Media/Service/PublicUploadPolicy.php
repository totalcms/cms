<?php

declare(strict_types=1);

namespace TotalCMS\Domain\Media\Service;

use TotalCMS\Domain\Collection\Service\OwnerPropertyResolver;
use TotalCMS\Domain\Media\Data\PublicUploadGrant;
use TotalCMS\Domain\Media\Exception\UploadRejectedException;
use TotalCMS\Domain\Property\Service\PropertyMetaResolver;
use TotalCMS\Domain\Security\Upload\FileUploadValidator;
use TotalCMS\Infrastructure\Filesystem\PathUtils;
use TotalCMS\Support\QuietCall;

/**
 * Decides what an anonymous visitor may upload to a depot: reads the grant
 * from the property's `publicUpload` setting and checks files against it.
 */
readonly class PublicUploadPolicy
{
	private const DEFAULT_FILE_MB    = 25;
	private const DEFAULT_TOTAL_MB   = 500;
	private const MAX_COMMENT_CHARS  = 1000;
	private const MAX_TAG_CHARS      = 60;
	private const MAX_DOWNLOAD_CHARS = 200;
	private const MAX_FILENAME_BYTES = 255;
	/** A visitor's name, leaving room for the 17-byte stored prefix. `.partN` on a chunk fits in the same room: parts carry no prefix. */
	private const MAX_VISITOR_NAME_BYTES = self::MAX_FILENAME_BYTES - 17;
	private const BYTES_PER_MB           = 1024 * 1024;

	/** Blocked on top of FileUploadValidator's dangerous list: pages, and the PHP handlers it misses. */
	private const BLOCKED_EXTENSIONS = [
		'html', 'htm', 'xhtml', 'xht', 'mhtml', 'svg', 'svgz', 'xml', 'shtml', 'shtm',
		'phar', 'pht', 'phtm', 'phps', 'phpt', 'php2', 'php6', 'php7', 'php8',
	];

	private const BLOCKED_TYPES = [
		'text/html',
		'image/svg+xml',
		'application/xhtml+xml',
		'text/x-php',
		'application/x-php',
		'application/x-httpd-php',
	];

	public function __construct(
		private PropertyMetaResolver $metaResolver,
		private FileUploadValidator $validator,
		private OwnerPropertyResolver $owners,
	) {
	}

	/**
	 * The grant on this depot, or null. A collection with an owner gets none:
	 * a visitor owns no object in it, so the store would refuse them with a 403
	 * only after the file had been written.
	 */
	public function grantFor(string $collection, string $property): ?PublicUploadGrant
	{
		$meta = $this->metaResolver->resolve($collection, $property);
		if (($meta['field'] ?? null) !== 'depot' || $this->owners->resolve($collection) !== '') {
			return null;
		}

		$settings = $meta['settings'] ?? null;
		$config   = is_array($settings) ? ($settings['publicUpload'] ?? null) : null;
		if (!is_array($config)) {
			return null;
		}

		$extensions = $this->cleanExtensions($config['extensions'] ?? null);
		if ($extensions === []) {
			return null;
		}

		$folder = is_string($config['folder'] ?? null) ? PathUtils::sanitizeSubpath($config['folder']) : '';

		return new PublicUploadGrant(
			$extensions,
			$this->megabytes($config['maxFileMb'] ?? null, self::DEFAULT_FILE_MB),
			$this->megabytes($config['maxTotalMb'] ?? null, self::DEFAULT_TOTAL_MB),
			$folder === '' ? null : $folder,
			($config['comments'] ?? false) === true,
		);
	}

	/**
	 * Whether a visitor's filename may be stored. Every dot-separated part of
	 * the name is checked, so `shell.php.pdf` is refused: some servers run a
	 * file by any extension in its name, not only the last.
	 *
	 * The name is judged twice, as sent and as PathUtils::safeFilename() will
	 * store it: cleaning trims and transliterates, so `shell.php_.pdf` and a
	 * full-width `shell.ｐｈｐ.pdf` both land on disk as `shell.php.pdf`.
	 *
	 * Length comes first. Transliteration takes about quadratic time, and PHP
	 * takes a name of any length from a multipart header, so a long name must
	 * never reach it.
	 */
	public function extensionAllowed(PublicUploadGrant $grant, string $filename): bool
	{
		if (strlen($filename) > self::MAX_FILENAME_BYTES || preg_match('/[\x00-\x1F\x7F]/', $filename) === 1) {
			return false;
		}

		return $this->nameAllowed($grant, $filename) && $this->nameAllowed($grant, PathUtils::safeFilename($filename));
	}

	/**
	 * Whether a visitor's filename would not fit on disk once stored, cleaned
	 * and prefixed. The raw length comes first, for the reason given on
	 * {@see self::extensionAllowed()}.
	 */
	public function nameTooLong(string $filename): bool
	{
		return strlen($filename) > self::MAX_VISITOR_NAME_BYTES
			|| strlen(PathUtils::safeFilename($filename)) > self::MAX_VISITOR_NAME_BYTES;
	}

	/**
	 * The visitor's filename, fit to keep as a file's `download` name: nothing
	 * that could break out of a header or pass one name off as another
	 * (U+202E shows `fdp.exe` as `exe.pdf`), at most 200 characters, and the
	 * extension survives the cut.
	 */
	public function downloadName(string $filename): string
	{
		$name = (string)preg_replace('/["\\\\\p{Cc}\p{Cf}]/u', '', mb_scrub($filename, 'UTF-8'));
		if (mb_strlen($name, 'UTF-8') <= self::MAX_DOWNLOAD_CHARS) {
			return $name;
		}

		$extension = pathinfo($name, PATHINFO_EXTENSION);
		$suffix    = $extension === '' ? '' : '.' . $extension;

		return mb_substr($name, 0, self::MAX_DOWNLOAD_CHARS - mb_strlen($suffix, 'UTF-8'), 'UTF-8') . $suffix;
	}

	private function nameAllowed(PublicUploadGrant $grant, string $filename): bool
	{
		$name      = strtolower(basename($filename));
		$extension = pathinfo($name, PATHINFO_EXTENSION);
		if ($extension === '') {
			return false;
		}

		foreach (explode('.', $name) as $part) {
			if (!$this->extensionSafe($part)) {
				return false;
			}
		}

		return in_array($extension, $grant->extensions, true);
	}

	/**
	 * Check an assembled file. `$depotBytes` is the depot's size before it.
	 * A file that cannot be measured or typed is refused, never waved through.
	 *
	 * @throws UploadRejectedException
	 */
	public function assertAssembled(PublicUploadGrant $grant, string $path, int $depotBytes): void
	{
		$size = QuietCall::run(fn (): int|false => filesize($path));
		if ($size === false) {
			throw new UploadRejectedException(['The file could not be read.']);
		}
		if ($size > $grant->maxFileBytes) {
			throw new UploadRejectedException(['The file is larger than the allowed size.']);
		}
		if ($depotBytes + $size > $grant->maxTotalBytes) {
			throw new UploadRejectedException(['There is no room left for this file.']);
		}

		$type = QuietCall::run(fn (): string|false => (new \finfo(FILEINFO_MIME_TYPE))->file($path));
		if ($type === false) {
			throw new UploadRejectedException(['The file could not be read.']);
		}
		if (in_array($type, self::BLOCKED_TYPES, true)) {
			throw new UploadRejectedException(['This type of file is not allowed.']);
		}
	}

	/**
	 * Plain text, cut to length. strip_tags() reads any `<` as the start of a
	 * tag and drops the rest of the text, so `I <3 this` would lose everything
	 * after the `I`: a `<` that cannot open a tag is kept out of its sight.
	 * Stripping can then leave a kept `<` against what followed the tag it
	 * removed (`<<b>img`), so any `<` that now opens a tag goes as well.
	 */
	public function cleanComment(string $comment): string
	{
		return mb_substr($this->plainText($comment), 0, self::MAX_COMMENT_CHARS, 'UTF-8');
	}

	/** One visitor-chosen tag: plain text on one line, cut to length. */
	public function cleanTag(string $tag): string
	{
		$line = trim((string)preg_replace('/\s+/u', ' ', $this->plainText($tag)));

		return mb_substr($line, 0, self::MAX_TAG_CHARS, 'UTF-8');
	}

	private function plainText(string $text): string
	{
		$mark = bin2hex(random_bytes(8));
		$kept = strip_tags((string)preg_replace('/<(?![a-zA-Z\/!?])/', $mark, $text));
		$text = trim((string)preg_replace('/<+(?=[a-zA-Z\/!?])/', '', str_replace($mark, '<', $kept)));
		if (!mb_check_encoding($text, 'UTF-8')) {
			$text = mb_convert_encoding($text, 'UTF-8', 'UTF-8');
		}

		return $text;
	}

	public function prefixedName(string $filename): string
	{
		return bin2hex(random_bytes(8)) . '-' . $filename;
	}

	/**
	 * Total size of every file in a transformed depot, folders included.
	 *
	 * @param array<string,mixed> $transformedDepot DepotData::transform()
	 */
	public function depotBytes(array $transformedDepot): int
	{
		$files = $transformedDepot['files'] ?? [];
		if (!is_array($files)) {
			return 0;
		}

		$total = 0;
		foreach ($files as $file) {
			if (!is_array($file)) {
				continue;
			}
			$total += ($file['mime'] ?? null) === 'folder' ? $this->depotBytes($file) : (int)($file['size'] ?? 0);
		}

		return $total;
	}

	private function extensionSafe(string $extension): bool
	{
		if (in_array($extension, self::BLOCKED_EXTENSIONS, true)) {
			return false;
		}

		return $this->validator->validateFilename('x.' . $extension)['valid'] === true;
	}

	/** @return list<string> */
	private function cleanExtensions(mixed $raw): array
	{
		if (!is_array($raw)) {
			return [];
		}

		$clean = [];
		foreach ($raw as $entry) {
			if (!is_string($entry)) {
				continue;
			}
			$extension = strtolower(ltrim(trim($entry), '.'));
			if ($extension !== '' && $this->extensionSafe($extension)) {
				$clean[] = $extension;
			}
		}

		return array_values(array_unique($clean));
	}

	private function megabytes(mixed $value, int $default): int
	{
		$valid = (is_int($value) || is_float($value)) && is_finite((float)$value) && $value > 0;

		return (int)ceil(($valid ? $value : $default) * self::BYTES_PER_MB);
	}
}
