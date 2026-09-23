<?php

declare(strict_types=1);

namespace TotalCMS\Domain\Import;

/**
 * One entry of a feed, whatever format it came from. RSS/Atom and JSON Feed
 * both reduce to this, so the importer has one pipeline instead of two.
 */
final readonly class FeedEntry
{
	/**
	 * @param list<string> $categories
	 * @param string       $date       ISO 8601, or '' when the feed gave none
	 * @param string       $content    the full body ('' when the feed only had a summary)
	 * @param string       $summary    the short form ('' when the feed only had a body)
	 */
	public function __construct(
		public string $title,
		public string $link,
		public string $author,
		public array $categories,
		public string $date,
		public string $content,
		public string $summary,
		public ?string $imageUrl,
	) {
	}

	/**
	 * The title an imported object gets: the entry's own, or `Untitled`.
	 */
	public function titleOrUntitled(): string
	{
		return $this->title !== '' ? $this->title : 'Untitled';
	}

	/**
	 * The fields an import maps onto the collection. A body with a summary
	 * keeps both; a summary alone becomes the body.
	 *
	 * @return array<string,mixed>
	 */
	public function toRecord(): array
	{
		$record = [
			'title'      => $this->titleOrUntitled(),
			'link'       => $this->link,
			'author'     => $this->author,
			'categories' => $this->categories,
			'date'       => $this->date,
		];

		if ($this->content !== '') {
			$record['content'] = $this->content;
			if ($this->summary !== '') {
				$record['summary'] = $this->summary;
			}
		} elseif ($this->summary !== '') {
			$record['content'] = $this->summary;
		}

		return $record;
	}

	/**
	 * The row the feed analyzer shows before an import.
	 *
	 * @return array<string,mixed>
	 */
	public function preview(): array
	{
		$text = $this->summary !== '' ? $this->summary : $this->content;

		return [
			'title'      => $this->title,
			'date'       => $this->date,
			'author'     => $this->author,
			'summary'    => $text !== '' ? mb_substr(strip_tags($text), 0, 200) : '',
			'categories' => $this->categories,
			'hasContent' => $this->content !== '' || $this->summary !== '',
			'hasImage'   => $this->imageUrl !== null,
			'link'       => $this->link,
		];
	}
}
