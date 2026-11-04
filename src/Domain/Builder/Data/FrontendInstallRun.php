<?php

declare(strict_types=1);

namespace TotalCMS\Domain\Builder\Data;

use TotalCMS\Infrastructure\Filesystem\FileUtils;

/**
 * One run of the frontend installer: writes files into the project and keeps
 * the record of what happened. In a dry run the record is kept and nothing is
 * written, so a dry run reports exactly what a real run would change.
 */
final class FrontendInstallRun
{
	/** @var list<string> */
	public array $written = [];
	/** @var list<string> */
	public array $skipped = [];
	/** @var list<string> */
	public array $failed = [];
	/** @var list<string> */
	public array $notes = [];
	/** @var array<string,string> */
	public array $gitignore = [];
	public ?string $layout  = null;
	public ?string $agents  = null;
	public bool $built      = false;

	public function __construct(
		public readonly string $root,
		public readonly bool $dryRun = false,
	) {
	}

	/** Write a file, by a path relative to the project root or an absolute one. */
	public function write(string $path, string $contents): void
	{
		$absolute = str_starts_with($path, '/') ? $path : $this->root . '/' . $path;
		$relative = $this->relative($absolute);

		if ($this->dryRun) {
			$this->written[] = $relative;

			return;
		}

		if (FileUtils::ensureDirectory(dirname($absolute)) && @file_put_contents($absolute, $contents) !== false) {
			$this->written[] = $relative;

			return;
		}

		$this->failed[] = $relative;
	}

	/** Show a path relative to the project root when it is inside it. */
	public function relative(string $path): string
	{
		return str_starts_with($path, $this->root . '/') ? substr($path, strlen($this->root) + 1) : $path;
	}

	/** @return array<string,mixed> */
	public function toArray(): array
	{
		return [
			'written'   => $this->written,
			'skipped'   => $this->skipped,
			'failed'    => $this->failed,
			'notes'     => $this->notes,
			'layout'    => $this->layout,
			'gitignore' => $this->gitignore,
			'agents'    => $this->agents,
			'built'     => $this->built,
		];
	}
}
