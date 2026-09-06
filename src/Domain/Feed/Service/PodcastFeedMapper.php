<?php

declare(strict_types=1);

namespace TotalCMS\Domain\Feed\Service;

use TotalCMS\Support\Config;

/**
 * Turn a `podcast` show object and `podcast-episode` index rows into the
 * meta + items arrays `FeedWriter` accepts, with the `podcast` blocks filled.
 *
 * Pure: no fetching, no I/O. The adapter fetches; this maps. URLs are built
 * relative to the site (`{api}/stream/...`, `{api}/imageworks/...`) and
 * FeedWriter makes them absolute, so nothing here depends on the request.
 *
 * Artwork uses the extension-suffixed ImageWorks route on purpose:
 * `MediaTwigAdapter::imagePath()` always appends `?cache=…`, and Apple (like
 * the writer's own check) wants a plain `.jpg`/`.png` URL.
 */
final readonly class PodcastFeedMapper
{
	/**
	 * Episode fields the mapper reads. The podcast-episode schema's `index`
	 * must carry all of them, because `cms.collection.objects()` returns
	 * index rows — a test pins that.
	 *
	 * @var list<string>
	 */
	public const EPISODE_FIELDS = [
		'id', 'title', 'date', 'draft', 'audio', 'audioUrl', 'audioLength', 'duration', 'episode', 'season',
		'episodeType', 'summary', 'art', 'explicit', 'transcript', 'transcriptUrl', 'chapters', 'chaptersUrl',
	];

	public function __construct(private Config $config)
	{
	}

	/**
	 * @param array<string,mixed>            $show      the show object (singleton)
	 * @param list<array<string,mixed>>      $episodes  episode index rows, any order
	 * @param array<string,mixed>            $options   self, link, language, copyright, now
	 * @param callable(array<string,mixed>): string $episodeLink
	 *
	 * @return array{meta: array<string,mixed>, items: list<array<string,mixed>>}
	 */
	public function map(string $showCollection, array $show, string $episodesCollection, array $episodes, array $options, callable $episodeLink): array
	{
		$now = $this->timestamp($options['now'] ?? null) ?? time();

		return [
			'meta'  => $this->meta($showCollection, $show, $options),
			'items' => $this->items($episodesCollection, $episodes, $show, $episodeLink, $now),
		];
	}

	/**
	 * @param array<string,mixed> $show
	 * @param array<string,mixed> $options
	 *
	 * @return array<string,mixed>
	 */
	private function meta(string $showCollection, array $show, array $options): array
	{
		$podcast = [
			'author'   => (string)($show['author'] ?? ''),
			'owner'    => ['name' => (string)($show['author'] ?? ''), 'email' => (string)($show['ownerEmail'] ?? '')],
			'image'    => $this->artwork($showCollection, (string)($show['id'] ?? $showCollection), 'cover', $show['cover'] ?? null),
			'category' => array_values(array_filter((array)($show['categories'] ?? []), is_string(...))),
			'explicit' => (bool)($show['explicit'] ?? false),
			'type'     => (string)($show['type'] ?? 'episodic'),
		];

		if (($show['fundingUrl'] ?? '') !== '') {
			$podcast['funding'] = [
				'url'   => (string)$show['fundingUrl'],
				'title' => (string)(($show['fundingTitle'] ?? '') !== '' ? $show['fundingTitle'] : 'Support the show'),
			];
		}
		if (($show['locked'] ?? false) === true) {
			$podcast['locked'] = ['owner' => (string)($show['ownerEmail'] ?? ''), 'value' => true];
		}
		foreach (['newFeedUrl', 'guid'] as $key) {
			if (($show[$key] ?? '') !== '') {
				$podcast[$key] = (string)$show[$key];
			}
		}

		$meta = [
			'title'       => (string)($show['title'] ?? ''),
			'link'        => (string)($options['link'] ?? '/'),
			// The show record owns its feed URL: apps re-fetch with it and the
			// Podcast Index guid derives from it, so it must not drift with a
			// template edit. An explicit option still wins for odd setups.
			'self'        => (string)($options['self'] ?? (($show['feedUrl'] ?? '') !== '' ? $show['feedUrl'] : '/podcast.xml')),
			'description' => $this->text((string)($show['description'] ?? '')),
			'podcast'     => $podcast,
		];
		foreach (['language', 'copyright'] as $key) {
			if (($options[$key] ?? '') !== '') {
				$meta[$key] = (string)$options[$key];
			}
		}

		return $meta;
	}

	/**
	 * @param list<array<string,mixed>> $episodes
	 * @param array<string,mixed>       $show
	 * @param callable(array<string,mixed>): string $episodeLink
	 *
	 * @return list<array<string,mixed>>
	 */
	private function items(string $episodesCollection, array $episodes, array $show, callable $episodeLink, int $now): array
	{
		$published = [];
		foreach ($episodes as $episode) {
			$stamp = $this->timestamp($episode['date'] ?? null);

			// Not an episode without audio (uploaded or linked); drafts and
			// future dates wait.
			if ($this->enclosure($episodesCollection, $episode) === null) {
				continue;
			}
			if (($episode['draft'] ?? false) === true || ($stamp !== null && $stamp > $now)) {
				continue;
			}

			$published[] = [$stamp ?? 0, $episode];
		}

		usort($published, static fn (array $a, array $b): int => $b[0] <=> $a[0]);

		return array_map(fn (array $pair): array => $this->item($episodesCollection, $pair[1], $show, $episodeLink), $published);
	}

	/**
	 * @param array<string,mixed> $episode
	 * @param array<string,mixed> $show
	 * @param callable(array<string,mixed>): string $episodeLink
	 *
	 * @return array<string,mixed>
	 */
	private function item(string $episodesCollection, array $episode, array $show, callable $episodeLink): array
	{
		$id = (string)($episode['id'] ?? '');

		$podcast = [
			'episodeType' => (string)(($episode['episodeType'] ?? '') !== '' ? $episode['episodeType'] : 'full'),
		];
		foreach (['duration', 'episode', 'season'] as $key) {
			if (is_numeric($episode[$key] ?? null) && (float)$episode[$key] > 0) {
				$podcast[$key] = $key === 'duration' ? (float)$episode[$key] : (int)$episode[$key];
			}
		}
		$art = $this->artwork($episodesCollection, $id, 'art', $episode['art'] ?? null);
		if ($art !== '') {
			$podcast['image'] = $art;
		}
		$explicit = (string)($episode['explicit'] ?? 'inherit');
		if ($explicit === 'yes' || $explicit === 'no') {
			$podcast['explicit'] = $explicit === 'yes';
		}
		$transcript = $this->attachment($episodesCollection, $id, 'transcript', $episode);
		if ($transcript !== null) {
			$podcast['transcript'] = ['url' => $transcript['url'], 'type' => $this->transcriptType($transcript['url'], $transcript['mime'])];
		}
		$chapters = $this->attachment($episodesCollection, $id, 'chapters', $episode);
		if ($chapters !== null) {
			$podcast['chapters'] = ['url' => $chapters['url'], 'type' => 'application/json+chapters'];
		}

		$item = [
			'id'      => $id,
			'title'   => (string)($episode['title'] ?? ''),
			'link'    => $episodeLink($episode),
			'date'    => $episode['date'] ?? null,
			'content' => (string)($episode['content'] ?? ''),
			'media'   => $this->enclosure($episodesCollection, $episode),
			'podcast' => $podcast,
		];
		$summary = $this->text((string)($episode['summary'] ?? ''));
		if ($summary !== '') {
			$item['summary'] = $summary;
		}
		if (($show['author'] ?? '') !== '') {
			$item['author'] = (string)$show['author'];
		}

		return $item;
	}

	/**
	 * Plain `.jpg`/`.png` ImageWorks URL for a stored image, or '' when none.
	 * Non-jpg/png originals are served converted to jpg by the format suffix.
	 */
	private function artwork(string $collection, string $id, string $property, mixed $image): string
	{
		if (!is_array($image) || ($image['name'] ?? '') === '' || $id === '') {
			return '';
		}
		$ext = strtolower(pathinfo((string)$image['name'], PATHINFO_EXTENSION));
		$ext = $ext === 'png' ? 'png' : 'jpg';

		return "{$this->config->api}/imageworks/{$collection}/{$id}/{$property}.{$ext}";
	}

	/**
	 * The enclosure: an uploaded file (served — and counted — by the stream
	 * route) wins; otherwise a URL to audio hosted elsewhere, with the size
	 * the author supplied. Null when the episode has neither.
	 *
	 * @param array<string,mixed> $episode
	 *
	 * @return array{url: string, type: string, length: int}|null
	 */
	private function enclosure(string $episodesCollection, array $episode): ?array
	{
		$audio = $episode['audio'] ?? null;
		if (is_array($audio) && ($audio['name'] ?? '') !== '') {
			return [
				'url'    => "{$this->config->api}/stream/{$episodesCollection}/" . (string)($episode['id'] ?? '') . '/audio',
				'type'   => (string)($audio['mime'] ?? ''),
				'length' => (int)($audio['size'] ?? 0),
			];
		}

		$url = (string)($episode['audioUrl'] ?? '');
		if ($url === '') {
			return null;
		}

		return [
			'url'    => $url,
			'type'   => MediaMimeGuesser::guess($url),
			'length' => (int)($episode['audioLength'] ?? 0),
		];
	}

	/**
	 * A companion file (transcript, chapters): the uploaded file via the
	 * download route wins; otherwise the `{property}Url` link. Null for neither.
	 *
	 * @param array<string,mixed> $episode
	 *
	 * @return array{url: string, mime: string}|null
	 */
	private function attachment(string $episodesCollection, string $id, string $property, array $episode): ?array
	{
		$file = $episode[$property] ?? null;
		if (is_array($file) && ($file['name'] ?? '') !== '') {
			return [
				'url'  => "{$this->config->api}/download/{$episodesCollection}/{$id}/{$property}",
				'mime' => (string)($file['mime'] ?? ''),
			];
		}

		$url = (string)($episode[$property . 'Url'] ?? '');

		return $url === '' ? null : ['url' => $url, 'mime' => ''];
	}

	private function transcriptType(string $url, string $mime = ''): string
	{
		if (in_array($mime, ['application/x-subrip', 'application/srt'], true)) {
			return 'application/srt';
		}
		if ($mime !== '' && $mime !== 'application/octet-stream') {
			return $mime;
		}

		$path = (string)(parse_url($url, PHP_URL_PATH) ?? '');

		return match (strtolower(pathinfo($path, PATHINFO_EXTENSION))) {
			'srt'  => 'application/srt',
			'vtt'  => 'text/vtt',
			'json' => 'application/json',
			'html' => 'text/html',
			default => 'text/plain',
		};
	}

	private function text(string $html): string
	{
		return trim(html_entity_decode(strip_tags($html), ENT_QUOTES | ENT_HTML5, 'UTF-8'));
	}

	/** Accepts an ISO string, a timestamp, or a DateTimeInterface. */
	private function timestamp(mixed $date): ?int
	{
		if ($date instanceof \DateTimeInterface) {
			return $date->getTimestamp();
		}
		if (is_int($date)) {
			return $date;
		}
		if (is_string($date) && $date !== '') {
			return strtotime($date) ?: null;
		}

		return null;
	}
}
