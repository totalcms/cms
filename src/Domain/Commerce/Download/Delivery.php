<?php

declare(strict_types=1);

namespace TotalCMS\Domain\Commerce\Download;

/**
 * One download, being handed over: the bytes of a file kept on the site, or
 * the address of one kept somewhere else.
 */
final readonly class Delivery
{
	/** @param resource|null $stream */
	private function __construct(
		public string $name,
		public string $mime,
		public mixed $stream,
		public string $url,
	) {
	}

	/** @param resource $stream the file's bytes */
	public static function stream(string $name, string $mime, mixed $stream): self
	{
		return new self($name, $mime !== '' ? $mime : 'application/octet-stream', $stream, '');
	}

	/** Send the buyer on to $url, where the file is. */
	public static function redirect(string $name, string $url): self
	{
		return new self($name, '', null, $url);
	}

	public function isRedirect(): bool
	{
		return $this->url !== '';
	}
}
