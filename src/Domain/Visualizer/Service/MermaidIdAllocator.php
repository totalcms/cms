<?php

declare(strict_types=1);

namespace TotalCMS\Domain\Visualizer\Service;

/**
 * Stable Mermaid identifiers for arbitrary keys: `[^A-Za-z0-9_]` becomes
 * `_`, a leading digit gets an `n_` prefix, and a clash with an id already
 * handed out is numbered. The same key always maps to the same id within
 * one diagram.
 */
final class MermaidIdAllocator
{
	/** @var array<string,string> */
	private array $ids = [];

	/** @var array<string,true> */
	private array $taken = [];

	public function idFor(string $key): string
	{
		if (isset($this->ids[$key])) {
			return $this->ids[$key];
		}

		$base = (string)preg_replace('/[^A-Za-z0-9_]/', '_', $key);
		if ($base === '' || ctype_digit($base[0])) {
			$base = 'n_' . $base;
		}

		$id = $base;
		$i  = 2;
		while (isset($this->taken[$id])) {
			$id = $base . '_' . $i++;
		}

		$this->taken[$id] = true;
		$this->ids[$key]  = $id;

		return $id;
	}
}
