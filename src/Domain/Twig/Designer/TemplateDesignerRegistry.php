<?php

namespace TotalCMS\Domain\Twig\Designer;

/**
 * In-memory registry for template designer block data.
 * Stores extracted raw content from {% templatedesigner %} tags during preprocessing.
 */
class TemplateDesignerRegistry
{
	/** @var array<string,array{template: string, domain: string, token: string, content: string}> */
	private array $blocks = [];

	/**
	 * Register a designer block.
	 *
	 * @param array{template: string, domain: string, token: string, content: string} $data
	 */
	public function register(string $key, array $data): void
	{
		$this->blocks[$key] = $data;
	}

	/**
	 * Get a designer block by key.
	 *
	 * @return array{template: string, domain: string, token: string, content: string}|null
	 */
	public function get(string $key): ?array
	{
		return $this->blocks[$key] ?? null;
	}

	/**
	 * Get all registered blocks.
	 *
	 * @return array<string,array{template: string, domain: string, token: string, content: string}>
	 */
	public function all(): array
	{
		return $this->blocks;
	}

	public function clear(): void
	{
		$this->blocks = [];
	}
}
