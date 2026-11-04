<?php

declare(strict_types=1);

namespace TotalCMS\Domain\Builder\Service;

use Closure;
use TotalCMS\Domain\Cache\CacheManager;
use TotalCMS\Support\OperationResult;

/**
 * Runs `npm install` and the build for a project's frontend, so the first page
 * load after `tcms builder:frontend` is styled.
 *
 * A machine without a supported Node is not a failure: the files are in place
 * and the site works without them until someone builds. That comes back as a
 * success with `built: false` and a note. A build that fails is a failure.
 *
 * Vite 8 needs Node ^20.19.0 || >=22.12.0. Node 21 is not supported.
 */
class BuilderFrontendBuild
{
	private const INSTALL = 'npm install --no-audit --no-fund --silent';
	private const BUILD   = 'npm run build --silent';

	public function __construct(
		private readonly CacheManager $cacheManager,
	) {
	}

	/** @param (Closure(string):void)|null $onStep */
	public function run(string $root, ?Closure $onStep = null): OperationResult
	{
		$version = $this->nodeVersion($root);
		if ($version === null) {
			return OperationResult::success('', [
				'built' => false,
				'note'  => "Node isn't installed here, so nothing was built. The site works, without this CSS, until you build. "
					. 'Build on any machine with Node and upload public/assets/.',
			]);
		}

		if (!self::isSupported($version)) {
			return OperationResult::success('', [
				'built' => false,
				'note'  => "Vite needs Node 20.19+ or 22.12+, and this is {$version}. Nothing was built.",
			]);
		}

		foreach ([self::INSTALL, self::BUILD] as $command) {
			if ($onStep instanceof Closure) {
				$onStep($command);
			}

			[$code, $output] = $this->execute($command, $root);
			if ($code !== 0) {
				return OperationResult::failure(
					"`{$command}` failed. The files are in place, so fix this and run `npm run build`:\n"
						. implode("\n", array_slice($output, -12)),
					null,
					['built' => false],
				);
			}
		}

		// Rendered pages hold the old asset URLs until the cache goes.
		$this->cacheManager->clearAllCaches();

		return OperationResult::success('', ['built' => true]);
	}

	public static function isSupported(string $version): bool
	{
		if (preg_match('/^v(\d+)\.(\d+)/', $version, $match) !== 1) {
			return false;
		}

		[$major, $minor] = [(int)$match[1], (int)$match[2]];

		return ($major === 20 && $minor >= 19) || ($major === 22 && $minor >= 12) || $major > 22;
	}

	/**
	 * Run a shell command in the project. Hosts often disable exec(), which
	 * reads the same as having no Node.
	 *
	 * @return array{int,list<string>} Exit code and output lines
	 */
	protected function execute(string $command, string $directory): array
	{
		if (!function_exists('exec')) {
			return [127, []];
		}

		$output = [];
		$code   = 1;
		exec('cd ' . escapeshellarg($directory) . " && {$command} 2>&1", $output, $code);

		return [$code, $output];
	}

	private function nodeVersion(string $root): ?string
	{
		[$code, $output] = $this->execute('node -v', $root);
		$version         = trim($output[0] ?? '');

		return $code === 0 && str_starts_with($version, 'v') ? $version : null;
	}
}
