<?php

declare(strict_types=1);

namespace TotalCMS\Domain\Object\Service;

use Symfony\Component\Yaml\Exception\ParseException;
use Symfony\Component\Yaml\Yaml;
use TotalCMS\Domain\Collection\Data\CollectionData;

/**
 * Turns an object's array into the bytes of its file and back, for both
 * storage formats. Pure: it knows nothing about collections or disks, and it
 * has no state of its own — the frontmatter split is done here with a regex
 * rather than a third-party frontmatter library, so nothing is trimmed out
 * from under a multi-line YAML literal block's trailing newline.
 *
 * JSON is exactly what ObjectData::toJson() has always written. Markdown is
 * YAML frontmatter holding every property except the body, then the body —
 * the schema's `content` property when that property is string-typed —
 * verbatim with one trailing newline. Multi-line string values are dumped as
 * YAML literal blocks (`Yaml::DUMP_MULTI_LINE_LITERAL_BLOCK`) so they stay
 * readable on disk. Types come back from the schema through PropertyFactory,
 * not from the file, so YAML's `yes`/`1`/`"1"` ambiguities resolve the way
 * JSON's do today. Timestamps are parsed as strings (no PARSE_DATETIME),
 * which is what DateData expects.
 */
final class ObjectFileCodec
{
	public const BODY_PROPERTY = 'content';

	/** Field types whose value is a string a person would write as a body. */
	private const BODY_FIELDS = ['text', 'textarea', 'markdown', 'styledtext'];

	/**
	 * Splits a markdown object file into its frontmatter YAML and its body.
	 * The closing fence must be `---` alone on its own line (`^` in `/m` mode
	 * matches only right after a newline, or at the very start) — a
	 * `DUMP_MULTI_LINE_LITERAL_BLOCK` value indents every one of its lines,
	 * so an embedded `  ---` inside such a value can never satisfy `^` and is
	 * correctly treated as frontmatter content rather than the fence. Because
	 * `^` is a zero-width assertion, the newline immediately before a real
	 * closing fence stays inside group 1, so the captured YAML keeps exactly
	 * the trailing newline a literal block needs to chomp correctly.
	 * Non-greedy so the FIRST real fence line closes the block even when the
	 * body itself contains `---` lines. CRLF-tolerant on both fence lines.
	 */
	private const SPLIT_PATTERN = '/\A---\r?\n(.*?)^---\r?\n(.*)\z/ms';

	public function extension(string $format): string
	{
		return $format === CollectionData::FORMAT_MARKDOWN ? '.md' : '.json';
	}

	/**
	 * The property that becomes the body of a markdown file, or null when the
	 * schema has no string-typed `content`.
	 *
	 * @param array<string,array<string,mixed>> $schemaProperties
	 */
	public function bodyProperty(array $schemaProperties): ?string
	{
		$prop = $schemaProperties[self::BODY_PROPERTY] ?? null;
		if (!is_array($prop)) {
			return null;
		}
		$isString = ($prop['type'] ?? null) === 'string'
			|| in_array((string)($prop['field'] ?? ''), self::BODY_FIELDS, true);

		return $isString ? self::BODY_PROPERTY : null;
	}

	/** @param array<string,mixed> $data */
	public function encode(array $data, string $format, ?string $bodyProperty): string
	{
		if ($format !== CollectionData::FORMAT_MARKDOWN) {
			try {
				return json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
			} catch (\JsonException $e) {
				// Malformed UTF-8 (or any other unencodable value) makes plain
				// json_encode() return '' rather than throw; a caller that
				// writes that return value straight to disk truncates the
				// object file to zero bytes. Throwing here instead means the
				// save aborts before anything is written — the file on disk
				// keeps its last good contents, same as ObjectData::toJson()
				// used to guarantee.
				throw new \UnexpectedValueException('Object could not be encoded as JSON: ' . $e->getMessage(), 0, $e);
			}
		}

		$body = '';
		if ($bodyProperty !== null && array_key_exists($bodyProperty, $data) && is_string($data[$bodyProperty])) {
			$body = $data[$bodyProperty];
			unset($data[$bodyProperty]);
		}

		// Always emit the frontmatter block, even when empty, so a file is
		// recognisable as an object file and a body beginning with `---`
		// can never be mistaken for frontmatter.
		$yaml = $data === [] ? '' : rtrim(Yaml::dump($data, 4, 2, Yaml::DUMP_MULTI_LINE_LITERAL_BLOCK));

		return "---\n" . ($yaml === '' ? '' : $yaml . "\n") . "---\n\n" . rtrim($body, "\n") . "\n";
	}

	/**
	 * @throws \UnexpectedValueException when the contents cannot be parsed
	 *
	 * @return array<string,mixed>
	 */
	public function decode(string $contents, string $format, ?string $bodyProperty): array
	{
		if ($format !== CollectionData::FORMAT_MARKDOWN) {
			$decoded = json_decode($contents, true);
			if (!is_array($decoded)) {
				throw new \UnexpectedValueException('Object file is not valid JSON: ' . json_last_error_msg());
			}

			return $decoded;
		}

		$yamlText = '';
		$body     = $contents;

		if (preg_match(self::SPLIT_PATTERN, $contents, $matches) === 1) {
			$yamlText = $matches[1];
			$body     = $matches[2];
		}

		$data = [];
		if ($yamlText !== '') {
			// A closing fence always follows a real newline (see
			// SPLIT_PATTERN), so $yamlText normally already ends with one;
			// this only guards the case where it somehow doesn't.
			if (!str_ends_with($yamlText, "\n")) {
				$yamlText .= "\n";
			}

			try {
				$parsed = Yaml::parse($yamlText);
			} catch (ParseException $firstError) {
				// A hand-edited or externally-converted file may carry CRLF
				// line endings the parser doesn't like as-is; retry once
				// against LF-normalized text before giving up.
				$normalized = str_replace("\r\n", "\n", $yamlText);
				if ($normalized === $yamlText) {
					throw new \UnexpectedValueException('Object file has invalid YAML frontmatter: ' . $firstError->getMessage(), 0, $firstError);
				}
				try {
					$parsed = Yaml::parse($normalized);
				} catch (ParseException) {
					throw new \UnexpectedValueException('Object file has invalid YAML frontmatter: ' . $firstError->getMessage(), 0, $firstError);
				}
			}

			if (!is_array($parsed)) {
				throw new \UnexpectedValueException('Object file frontmatter is not a mapping');
			}
			$data = $parsed;
		}

		// The writer adds one blank line after the block and one trailing
		// newline; take exactly those back so the body round-trips. Uses
		// \r?\n (not bare \n) so a CRLF file doesn't leave a stray \r glued
		// to the body's first character.
		$body = preg_replace('/\A\r?\n/', '', $body) ?? $body;
		$body = preg_replace('/\r?\n\z/', '', $body) ?? $body;

		// Only assign the parsed body over the frontmatter's own value for
		// this property when there IS a body, or the frontmatter never had
		// the property to begin with. Otherwise a non-string `content` (kept
		// in the frontmatter by encode() because it isn't a body value) would
		// be clobbered here by the empty string between the fences.
		if ($bodyProperty !== null && ($body !== '' || !array_key_exists($bodyProperty, $data))) {
			$data[$bodyProperty] = $body;
		}

		return $data;
	}
}
