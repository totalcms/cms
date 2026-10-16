<?php

declare(strict_types=1);

namespace TotalCMS\Domain\Extension\Service\Install;

/**
 * Anything the installer, remover or packager refuses to do, with a short
 * machine-readable code the CLI and the JSON API pass through unchanged.
 */
final class InstallException extends \RuntimeException
{
	public const INVALID_ARCHIVE  = 'invalid_archive';
	public const INVALID_MANIFEST = 'invalid_manifest';
	public const INCOMPATIBLE     = 'incompatible';
	public const OWNED_ELSEWHERE  = 'owned_elsewhere';
	public const NOT_FOUND        = 'not_found';
	public const WRITE_FAILED     = 'write_failed';

	private function __construct(
		public readonly string $codeName,
		string $message,
	) {
		parent::__construct($message);
	}

	public static function invalidArchive(string $message): self
	{
		return new self(self::INVALID_ARCHIVE, $message);
	}

	public static function invalidManifest(string $message): self
	{
		return new self(self::INVALID_MANIFEST, $message);
	}

	public static function incompatible(string $message): self
	{
		return new self(self::INCOMPATIBLE, $message);
	}

	public static function ownedElsewhere(string $message): self
	{
		return new self(self::OWNED_ELSEWHERE, $message);
	}

	public static function notFound(string $message): self
	{
		return new self(self::NOT_FOUND, $message);
	}

	public static function writeFailed(string $message): self
	{
		return new self(self::WRITE_FAILED, $message);
	}
}
