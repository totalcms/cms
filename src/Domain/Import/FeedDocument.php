<?php

declare(strict_types=1);

namespace TotalCMS\Domain\Import;

/**
 * A parsed feed: its channel metadata and its entries.
 */
final readonly class FeedDocument
{
	/**
	 * @param list<FeedEntry> $entries
	 */
	public function __construct(
		public string $title,
		public string $description,
		public string $link,
		public array $entries,
	) {
	}

	/**
	 * @return array{title: string, description: string, link: string, count: int}
	 */
	public function summary(): array
	{
		return [
			'title'       => $this->title,
			'description' => $this->description,
			'link'        => $this->link,
			'count'       => count($this->entries),
		];
	}
}
