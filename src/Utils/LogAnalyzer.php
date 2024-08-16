<?php

namespace TotalCMS\Utils;

use TotalCMS\Support\Config;

class LogAnalyzer
{
	/** @var array<string,string> */
	public array $logfiles = [];
	public string $logdir;

	public function __construct(
		private Config $config,
	) {
		$this->logdir   = $this->config->logger['path'];
		$this->logfiles = $this->logfiles();
	}

	/** @return array<string,string> */
	private function logfiles(): array
	{
		$files = [];
		$dir   = new \DirectoryIterator($this->logdir);

		foreach ($dir as $file) {
			if ($file->isFile() && $file->getExtension() === 'log') {
				$files[$file->getFilename()] = $file->getPathname();
			}
		}

		return $files;
	}

	/** @return array<string> */
	public function logfile(string $logfile): array
	{
		if (!array_key_exists($logfile, $this->logfiles)) {
			return [];
		}

		return file($this->logfiles[$logfile]) ?: [];
	}

	/** @return array<string,array<string,mixed>> */
	public function analyze(string $logfile): array
	{
		$lines = $this->logfile($logfile);

		$logs      = [];
		$lastError = '';

		foreach ($lines as $line) {
			if (str_starts_with($line, '[')) {
				$error     = $this->parseError($line);
				$lastError = $error;

				if (isset($logs[$error]['count'])) {
					$logs[$error]['count']++;
					continue;
				}

				$logs[$error] = [
					'count'     => 1,
					'backtrace' => [],
				];
			} elseif (str_starts_with($line, 'Backtrace')) {
				continue;
			} elseif (str_starts_with($line, '#')) {
				$logs[$lastError]['backtrace'][] = $line;
			}
		}

		return $logs;
	}

	private function parseError(string $line): string
	{
		// split it after the [date] field in the line and remove it
		$parts = explode(']', $line);
		unset($parts[0]);
		$error = implode(']', $parts);

		return strip_tags(trim($error));
	}
}
