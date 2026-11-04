<?php

declare(strict_types=1);

namespace TotalCMS\Domain\Builder\Data;

/**
 * How `tcms builder:frontend` should run.
 *
 * - `dryRun` reports what would change and writes nothing.
 * - `build` runs npm once the files are in place.
 * - `onStep` hears about each npm command as it starts, because `npm install`
 *   can take a while. It receives the command as a string.
 */
final readonly class FrontendInstallOptions
{
	public function __construct(
		public bool $dryRun = false,
		public bool $build = true,
		public ?\Closure $onStep = null,
	) {
	}
}
