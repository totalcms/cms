<?php

namespace TotalCMS\Infrastructure\Diagnostics;

use TotalCMS\Support\Config;

class LogAnalyzer
{
	/** @var array<string,string> */
	private array $logfiles = [];
	public string $logdir;

	public function __construct(Config $config)
	{
		$this->logdir = $config->logger['path'];
	}

	/** @return array<string,string> */
	public function logfiles(): array
	{
		if ($this->logfiles !== []) {
			return $this->logfiles;
		}

		$files = [];

		if (is_dir($this->logdir)) {
			$dir = new \DirectoryIterator($this->logdir);

			foreach ($dir as $file) {
				if ($file->isFile() && $file->getExtension() === 'log') {
					$files[$file->getFilename()] = $file->getPathname();
				}
			}
		}

		krsort($files);
		$this->logfiles = $files;

		return $files;
	}

	/** @return array<string> */
	public function logfile(string $logfile, int $maxLines = 10000): array
	{
		if (!array_key_exists($logfile, $this->logfiles())) {
			return [];
		}

		$filePath = $this->logfiles[$logfile];
		
		// Check file size first - if it's very large, read from the end
		$fileSize = filesize($filePath);
		if ($fileSize === false) {
			return [];
		}

		// If file is smaller than 10MB, use the original method
		if ($fileSize < 10 * 1024 * 1024) {
			return file($filePath) ?: [];
		}

		// For large files, read from the end to get recent entries
		return $this->readLastLines($filePath, $maxLines);
	}

	/**
	 * Memory-efficient method to read last N lines from a file
	 * 
	 * @return array<string>
	 */
	private function readLastLines(string $filePath, int $maxLines): array
	{
		$handle = fopen($filePath, 'r');
		if (!$handle) {
			return [];
		}

		$lines = [];
		$buffer = '';
		$chunkSize = 8192; // 8KB chunks
		
		// Start from the end of the file
		fseek($handle, 0, SEEK_END);
		$fileSize = ftell($handle);
		$position = $fileSize;

		while ($position > 0 && count($lines) < $maxLines) {
			// Move back by chunk size or to beginning of file
			$chunkStart = max(0, $position - $chunkSize);
			$position = $chunkStart;
			
			fseek($handle, $chunkStart);
			$chunk = fread($handle, $chunkSize);
			
			if ($chunk === false) {
				break;
			}

			// Prepend to buffer
			$buffer = $chunk . $buffer;
			
			// Split into lines
			$chunkLines = explode("\n", $buffer);
			
			// Keep the first line as it might be incomplete (unless we're at file start)
			$buffer = $chunkStart > 0 ? array_shift($chunkLines) : '';
			
			// Add lines to the beginning of our array (since we're reading backwards)
			$lines = array_merge($chunkLines, $lines);
			
			// Remove empty lines and limit to maxLines
			$lines = array_filter($lines, fn(string $line): bool => trim($line) !== '');
			$lines = array_slice($lines, -$maxLines);
		}

		fclose($handle);
		
		// Return most recent lines first
		return array_reverse(array_slice($lines, -$maxLines));
	}

	public function defaultLogfile(): string
	{
		$default = sprintf('totalcms-%s.log', date('Y-m-d'));

		if (array_key_exists($default, $this->logfiles())) {
			return $default;
		}

		return array_key_first($this->logfiles()) ?? '';
	}

	/** @return array<string,array<string,mixed>> */
	public function analyze(string $logfile): array
	{
		$lines = $this->logfile($logfile);

		$logs      = [];
		$lastError = '';

		foreach ($lines as $line) {
			if (str_contains($line, '.INFO:') || str_contains($line, '.DEBUG:')) {
				continue;
			} elseif (str_starts_with($line, '[')) {
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
