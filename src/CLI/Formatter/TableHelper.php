<?php

declare(strict_types=1);

namespace TotalCMS\CLI\Formatter;

use Symfony\Component\Console\Helper\Table;
use Symfony\Component\Console\Output\OutputInterface;

class TableHelper
{
	/**
	 * Render key-value pairs as a two-column table.
	 *
	 * @param array<string,mixed> $data
	 */
	public static function renderKeyValue(OutputInterface $output, array $data): void
	{
		// Find longest key for alignment
		$maxKeyLen = 0;
		foreach (array_keys($data) as $key) {
			$maxKeyLen = max($maxKeyLen, mb_strlen((string) $key));
		}

		foreach ($data as $key => $value) {
			$displayValue = is_array($value)
				? (string) json_encode($value, JSON_UNESCAPED_SLASHES)
				: (string) $value;
			$padding = str_repeat(' ', $maxKeyLen - mb_strlen((string) $key));
			$output->writeln("  <info>{$key}</info>{$padding}  {$displayValue}");
		}
	}

	/**
	 * Render a list of items as a table with headers.
	 *
	 * @param list<string> $headers
	 * @param list<list<string>> $rows
	 */
	public static function renderList(OutputInterface $output, array $headers, array $rows): void
	{
		$table = new Table($output);
		$table->setHeaders($headers);
		$table->setRows($rows);
		$table->render();
	}
}
