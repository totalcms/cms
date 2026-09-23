<?php

declare(strict_types=1);

namespace TotalCMS\Domain\Import;

use Laminas\Feed\Reader\Entry\AbstractEntry;
use Laminas\Feed\Reader\Entry\EntryInterface;
use Laminas\Feed\Reader\Reader;

/**
 * Parse a fetched feed — RSS, Atom or JSON Feed — into a {@see FeedDocument}
 * of {@see FeedEntry} values. Format detection and every format-specific
 * extraction (authors, dates, images, content vs summary) end here, so the
 * importer never sees which format it was.
 */
final class FeedReader
{
	public function parse(string $raw): FeedDocument
	{
		$json = $this->decodeJsonFeed($raw);

		return $json !== null ? $this->fromJson($json) : $this->fromXml($raw);
	}

	/**
	 * @return array<string,mixed>|null the decoded document when $raw is a JSON Feed
	 */
	private function decodeJsonFeed(string $raw): ?array
	{
		$trimmed = ltrim($raw);
		if (!str_starts_with($trimmed, '{')) {
			return null;
		}

		$decoded = json_decode($trimmed, true);
		if (!is_array($decoded) || !isset($decoded['version']) || !str_contains((string)$decoded['version'], 'jsonfeed')) {
			return null;
		}

		return $decoded;
	}

	// ─── RSS / Atom ─────────────────────────────────────────────

	private function fromXml(string $xml): FeedDocument
	{
		$feed    = Reader::importString($xml);
		$entries = [];

		foreach ($feed as $entry) {
			$entries[] = $this->xmlEntry($entry);
		}

		return new FeedDocument(
			(string)($feed->getTitle() ?? ''),
			(string)($feed->getDescription() ?? ''),
			(string)($feed->getLink() ?? ''),
			$entries,
		);
	}

	private function xmlEntry(EntryInterface $entry): FeedEntry
	{
		$author = $entry->getAuthor();
		$date   = $entry->getDateModified() ?? $entry->getDateCreated();

		return new FeedEntry(
			title: (string)$entry->getTitle(),
			link: (string)$entry->getLink(),
			author: is_array($author) && isset($author['name']) ? (string)$author['name'] : '',
			categories: array_values(array_map(strval(...), $entry->getCategories()->getValues())),
			date: $date !== null ? $date->format('c') : '',
			content: (string)$entry->getContent(),
			summary: (string)$entry->getDescription(),
			imageUrl: $this->xmlImageUrl($entry),
		);
	}

	/**
	 * An image for the entry: an image enclosure, else `media:content`,
	 * else `media:thumbnail`.
	 */
	private function xmlImageUrl(EntryInterface $entry): ?string
	{
		$enclosure = $entry->getEnclosure();
		if ($enclosure !== null && isset($enclosure->url)) {
			$type = (string)($enclosure->type ?? '');
			if ($type === '' || str_starts_with($type, 'image/')) {
				return (string)$enclosure->url;
			}
		}

		if (!$entry instanceof AbstractEntry) {
			return null;
		}

		$element  = $entry->getElement();
		$document = $element->ownerDocument;
		if ($document === null) {
			return null;
		}

		$xpath = new \DOMXPath($document);
		$xpath->registerNamespace('media', 'http://search.yahoo.com/mrss/');

		$media = $xpath->query('.//media:content[@url]', $element);
		if ($media !== false && $media->length > 0) {
			$node = $media->item(0);
			if ($node instanceof \DOMElement) {
				$medium = $node->getAttribute('medium');
				if ($medium === 'image' || $medium === '' || str_starts_with($node->getAttribute('type'), 'image/')) {
					return $node->getAttribute('url');
				}
			}
		}

		$thumbs = $xpath->query('.//media:thumbnail[@url]', $element);
		if ($thumbs !== false && $thumbs->length > 0) {
			$node = $thumbs->item(0);
			if ($node instanceof \DOMElement) {
				return $node->getAttribute('url');
			}
		}

		return null;
	}

	// ─── JSON Feed ──────────────────────────────────────────────

	/**
	 * @param array<string,mixed> $data
	 */
	private function fromJson(array $data): FeedDocument
	{
		$entries = [];
		foreach ((array)($data['items'] ?? []) as $item) {
			if (is_array($item)) {
				$entries[] = $this->jsonEntry($item, $data);
			}
		}

		return new FeedDocument(
			(string)($data['title'] ?? ''),
			(string)($data['description'] ?? ''),
			(string)($data['home_page_url'] ?? ''),
			$entries,
		);
	}

	/**
	 * @param array<string,mixed> $item
	 * @param array<string,mixed> $feed
	 */
	private function jsonEntry(array $item, array $feed): FeedEntry
	{
		$content = (string)($item['content_html'] ?? '');
		if ($content === '') {
			$content = (string)($item['content_text'] ?? '');
		}

		$tags = isset($item['tags']) && is_array($item['tags']) ? array_values(array_map(strval(...), $item['tags'])) : [];

		$image = null;
		foreach (['image', 'banner_image'] as $key) {
			if (isset($item[$key]) && is_string($item[$key]) && $item[$key] !== '') {
				$image = $item[$key];
				break;
			}
		}

		return new FeedEntry(
			title: (string)($item['title'] ?? ''),
			link: (string)($item['url'] ?? $item['external_url'] ?? ''),
			author: $this->jsonAuthor($item, $feed),
			categories: $tags,
			date: (string)($item['date_published'] ?? $item['date_modified'] ?? ''),
			content: $content,
			summary: (string)($item['summary'] ?? ''),
			imageUrl: $image,
		);
	}

	/**
	 * Item-level `authors` (1.1) or `author` (1.0), then the same at feed level.
	 *
	 * @param array<string,mixed> $item
	 * @param array<string,mixed> $feed
	 */
	private function jsonAuthor(array $item, array $feed): string
	{
		foreach ([$item, $feed] as $scope) {
			if (isset($scope['authors']) && is_array($scope['authors'])) {
				$first = $scope['authors'][0] ?? null;
				if (is_array($first) && isset($first['name'])) {
					return (string)$first['name'];
				}
			}
			if (isset($scope['author']) && is_array($scope['author']) && isset($scope['author']['name'])) {
				return (string)$scope['author']['name'];
			}
		}

		return '';
	}
}
