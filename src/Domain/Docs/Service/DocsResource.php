<?php

declare(strict_types=1);

namespace TotalCMS\Domain\Docs\Service;

/** What a docs URL points at: a page, a static file, or nothing. */
final readonly class DocsResource
{
	public const MISSING  = 'missing';
	public const JSON     = 'json';
	public const IMAGE    = 'image';
	public const MARKDOWN = 'markdown';
	public const HTML     = 'html';

	public function __construct(
		public string $kind,
		public string $page,
		public string $path = '',
		public string $mime = '',
	) {
	}

	public function is(string $kind): bool
	{
		return $this->kind === $kind;
	}
}
