<?php

declare(strict_types=1);

namespace TotalCMS\Domain\Starter\Data;

/**
 * What a starter export wrote, and what the author should look at.
 *
 * `secretHits` non-empty means the export failed its final check: a value
 * known to be secret on the running site turned up in an output file. The
 * output is left in place so the author can see where.
 */
final class StarterExportResult
{
	/** @var list<string> Collections whose objects were left out. */
	public array $emptiedCollections = [];

	/** @var list<string> Settings keys blanked, as `file: dotted.key`. */
	public array $scrubbedSettings = [];

	/** @var list<string> Top-level keys the site's own config/tcms.php set. */
	public array $configKeys = [];

	/** @var list<string> Output files, relative, that contain a known secret. */
	public array $secretHits = [];

	/** Images with a credit, listed in CREDITS.md. */
	public int $creditedImages = 0;

	/** @var list<string> Images without one, as `collection/id: name`. */
	public array $uncreditedImages = [];

	/** @var array<string,int> The largest output files, relative path => bytes. */
	public array $largestFiles = [];

	public int $files = 0;
	public int $bytes = 0;

	public function __construct(public readonly string $destination)
	{
	}

	public function passed(): bool
	{
		return $this->secretHits === [];
	}

	/** @return array<string,mixed> */
	public function toArray(): array
	{
		return [
			'destination'        => $this->destination,
			'passed'             => $this->passed(),
			'files'              => $this->files,
			'bytes'              => $this->bytes,
			'emptiedCollections' => $this->emptiedCollections,
			'scrubbedSettings'   => $this->scrubbedSettings,
			'configKeys'         => $this->configKeys,
			'secretHits'         => $this->secretHits,
			'largestFiles'       => $this->largestFiles,
			'creditedImages'     => $this->creditedImages,
			'uncreditedImages'   => $this->uncreditedImages,
		];
	}
}
