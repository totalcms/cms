<?php

declare(strict_types=1);

namespace TotalCMS\Domain\Security\Check;

use FilesystemIterator;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use TotalCMS\Domain\Builder\Service\BuilderTemplatePaths;
use TotalCMS\Domain\Security\Data\SecurityContext;
use TotalCMS\Domain\Security\Data\SecurityFinding;
use TotalCMS\Domain\Security\Data\Severity;

/**
 * Flags builder templates that print request data (query string, POST body,
 * session) through `|raw`, which turns visitor input into stored/reflected XSS.
 */
class RawRequestDataCheck implements SecurityCheck
{
	private const ID      = 'raw-request-data';
	private const DOCS    = 'operations/security#raw-request-data';
	private const PATTERN = '/\b(getData|postData|sessionData)\b[^}]*\|\s*raw\b/';

	public function __construct(private readonly BuilderTemplatePaths $paths)
	{
	}

	public function makesRequests(): bool
	{
		return false;
	}

	public function run(SecurityContext $context): array
	{
		$findings = [];
		foreach ($this->paths->readLayersLabeled() as $layer) {
			if ($layer['layer'] === BuilderTemplatePaths::LAYER_BUILTIN) {
				continue;
			}
			foreach ($this->scanLayer($layer['dir']) as $relative => $lines) {
				$hits = array_map(static fn (int $line): string => $relative . ':' . $line, $lines);

				$findings[] = SecurityFinding::found(
					self::ID,
					Severity::Warning,
					'A template prints request data with |raw',
					'Unescaped request data in: ' . implode(', ', $hits),
					'Remove `|raw` so Twig escapes the value, or sanitize it first. Visitors control this data.',
					self::DOCS,
				);
			}
		}

		return $findings !== [] ? $findings : [SecurityFinding::ok(self::ID, 'No templates print request data with |raw')];
	}

	/** @return array<string,list<int>> relative path => matching line numbers */
	private function scanLayer(string $dir): array
	{
		$base  = rtrim($dir, '/');
		$found = [];
		$iter  = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($base, FilesystemIterator::SKIP_DOTS));
		foreach ($iter as $file) {
			if (!$file->isFile() || $file->getExtension() !== 'twig') {
				continue;
			}
			$lines = [];
			foreach (file($file->getPathname(), FILE_IGNORE_NEW_LINES) ?: [] as $i => $text) {
				if (preg_match(self::PATTERN, $text) === 1) {
					$lines[] = $i + 1;
				}
			}
			if ($lines !== []) {
				$found[ltrim(substr($file->getPathname(), strlen($base)), '/')] = $lines;
			}
		}
		ksort($found);

		return $found;
	}
}
