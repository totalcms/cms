<?php

declare(strict_types=1);

namespace TotalCMS\Domain\Feed\Service;

use Laminas\Feed\Writer\Entry;
use Laminas\Feed\Writer\Extension\ITunes\Entry as ITunesEntry;
use Laminas\Feed\Writer\Extension\PodcastIndex\Entry as PodcastIndexEntry;
use Laminas\Feed\Writer\Feed;

/**
 * Map the `podcast` block of a feed's meta and of each item onto the Laminas
 * iTunes and Podcast Index writer extensions.
 *
 * Every key is validated here, before Laminas sees it, so the author gets
 * `cms.feed.rss: meta.podcast.category …` with a suggestion rather than a
 * library message. "Present means Apple-valid" is the rule: when the block
 * exists, Apple's five required channel tags are required too.
 *
 * Laminas facts that shape the rules below: an image URL must end in `jpg`
 * or `png` (no `.jpeg`, no query string — Apple is as strict); duration is
 * digits or [[HH:]MM:]SS; explicit accepts bool or yes/no/clean; categories
 * are `['Name', 'Parent' => ['Child']]`; soundbite times are numeric strings.
 */
final readonly class PodcastTags
{
	private const CHANNEL_KEYS = [
		'author', 'owner', 'image', 'category', 'explicit', 'type', 'subtitle', 'summary',
		'newFeedUrl', 'complete', 'block', 'guid', 'locked', 'funding',
	];

	private const CHANNEL_REQUIRED = ['author', 'owner', 'image', 'category', 'explicit'];

	private const ITEM_KEYS = [
		'duration', 'episode', 'season', 'episodeType', 'image', 'explicit', 'title', 'subtitle',
		'summary', 'block', 'transcript', 'chapters', 'people', 'soundbites',
	];

	/** @param \Closure(string): string $absolute */
	public function __construct(private \Closure $absolute)
	{
	}

	/**
	 * @param array<string,mixed> $podcast
	 * @param array<string,mixed> $meta   the full feed meta (for `self` and `description` defaults)
	 *
	 * @throws \DomainException
	 */
	public function applyToFeed(Feed $feed, array $podcast, array $meta): void
	{
		$this->rejectUnknownKeys($podcast, self::CHANNEL_KEYS, 'meta.podcast');

		foreach (self::CHANNEL_REQUIRED as $key) {
			if (!array_key_exists($key, $podcast) || $podcast[$key] === '' || $podcast[$key] === []) {
				throw $this->error("meta.podcast.{$key} is required when meta.podcast is set (Apple Podcasts requires it).");
			}
		}

		$self = (string)($meta['self'] ?? '');
		if ($self === '') {
			throw $this->error('meta.self is required for a podcast feed: apps re-fetch with it and podcast:guid derives from it.');
		}

		// Feed-level iTunes/PodcastIndex setters are __call proxies on the Laminas
		// Feed (it exposes no accessor for its extension objects); PHPStan learns
		// their signatures from phpstan-stubs/laminas-feed-writer-feed.stub.
		$feed->addItunesAuthor($this->string($podcast['author'], 'meta.podcast.author'));
		$feed->addItunesOwner($this->owner($podcast['owner']));
		$feed->setItunesImage($this->image($podcast['image'], 'meta.podcast.image'));
		$feed->setItunesCategories($this->categories($podcast['category']));
		$feed->setItunesExplicit($this->explicit($podcast['explicit'], 'meta.podcast.explicit'));
		$feed->setItunesType($this->oneOf($podcast['type'] ?? 'episodic', ['episodic', 'serial'], 'meta.podcast.type'));

		$summary = (string)($podcast['summary'] ?? $meta['description'] ?? '');
		if ($summary !== '') {
			$feed->setItunesSummary(mb_substr($summary, 0, 4000));
		}
		if (($podcast['subtitle'] ?? '') !== '') {
			$feed->setItunesSubtitle(mb_substr($this->string($podcast['subtitle'], 'meta.podcast.subtitle'), 0, 255));
		}
		if (($podcast['newFeedUrl'] ?? '') !== '') {
			$feed->setItunesNewFeedUrl(($this->absolute)($this->string($podcast['newFeedUrl'], 'meta.podcast.newFeedUrl')));
		}
		if (($podcast['complete'] ?? false) === true) {
			$feed->setItunesComplete(true);
		}
		if (($podcast['block'] ?? false) === true) {
			$feed->setItunesBlock('Yes');
		}

		$guid = (string)($podcast['guid'] ?? '');
		$feed->setPodcastIndexGuid(['value' => $guid !== '' ? $guid : PodcastGuid::fromFeedUrl(($this->absolute)($self))]);

		if (is_array($podcast['locked'] ?? null)) {
			$locked = $podcast['locked'];
			$feed->setPodcastIndexLocked([
				'value' => ($locked['value'] ?? false) ? 'yes' : 'no',
				'owner' => $this->string($locked['owner'] ?? '', 'meta.podcast.locked.owner'),
			]);
		}

		foreach ($this->listOfHashes($podcast['funding'] ?? null) as $funding) {
			$feed->addPodcastIndexFunding([
				'url'   => ($this->absolute)($this->string($funding['url'] ?? '', 'meta.podcast.funding.url')),
				'title' => $this->string($funding['title'] ?? '', 'meta.podcast.funding.title'),
			]);
		}
	}

	/**
	 * @param array<string,mixed> $podcast
	 * @param array<string,mixed> $item   the full item (for `summary` / `content` defaults)
	 *
	 * @throws \DomainException
	 */
	public function applyToEntry(Entry $entry, array $podcast, array $item): void
	{
		$this->rejectUnknownKeys($podcast, self::ITEM_KEYS, 'item.podcast');

		$itunes = $this->extension($entry->getExtension('ITunes'), ITunesEntry::class);
		$index  = $this->extension($entry->getExtension('PodcastIndex'), PodcastIndexEntry::class);

		if (array_key_exists('duration', $podcast)) {
			$itunes->setItunesDuration($this->duration($podcast['duration']));
		}
		if (array_key_exists('episode', $podcast)) {
			$itunes->setItunesEpisode($this->integer($podcast['episode'], 'item.podcast.episode'));
		}
		if (array_key_exists('season', $podcast)) {
			$itunes->setItunesSeason($this->integer($podcast['season'], 'item.podcast.season'));
		}
		$itunes->setItunesEpisodeType($this->oneOf($podcast['episodeType'] ?? 'full', ['full', 'trailer', 'bonus'], 'item.podcast.episodeType'));

		if (($podcast['image'] ?? '') !== '') {
			$itunes->setItunesImage($this->image($podcast['image'], 'item.podcast.image'));
		}
		if (array_key_exists('explicit', $podcast)) {
			$itunes->setItunesExplicit($this->explicit($podcast['explicit'], 'item.podcast.explicit'));
		}
		if (($podcast['title'] ?? '') !== '') {
			$itunes->setItunesTitle($this->string($podcast['title'], 'item.podcast.title'));
		}
		if (($podcast['subtitle'] ?? '') !== '') {
			$itunes->setItunesSubtitle(mb_substr($this->string($podcast['subtitle'], 'item.podcast.subtitle'), 0, 255));
		}

		$summary = (string)($podcast['summary'] ?? $item['summary'] ?? '');
		if ($summary === '') {
			$summary = trim(html_entity_decode(strip_tags((string)($item['content'] ?? ''))));
		}
		if ($summary !== '') {
			$itunes->setItunesSummary(mb_substr($summary, 0, 4000));
		}

		if (($podcast['block'] ?? false) === true) {
			$itunes->setItunesBlock('Yes');
		}

		if (is_array($podcast['transcript'] ?? null)) {
			$transcript = $podcast['transcript'];
			$value      = [
				'url'  => ($this->absolute)($this->string($transcript['url'] ?? '', 'item.podcast.transcript.url')),
				'type' => $this->string($transcript['type'] ?? '', 'item.podcast.transcript.type'),
			];
			foreach (['language', 'rel'] as $optional) {
				if (($transcript[$optional] ?? '') !== '') {
					$value[$optional] = (string)$transcript[$optional];
				}
			}
			$index->setPodcastIndexTranscript($value);
		}
		if (is_array($podcast['chapters'] ?? null)) {
			$index->setPodcastIndexChapters([
				'url'  => ($this->absolute)($this->string($podcast['chapters']['url'] ?? '', 'item.podcast.chapters.url')),
				'type' => $this->string($podcast['chapters']['type'] ?? '', 'item.podcast.chapters.type'),
			]);
		}
		foreach ($this->listOfHashes($podcast['people'] ?? null) as $person) {
			$value = ['name' => $this->string($person['name'] ?? '', 'item.podcast.people.name')];
			foreach (['role', 'group', 'img', 'href'] as $optional) {
				if (($person[$optional] ?? '') !== '') {
					$value[$optional] = (string)$person[$optional];
				}
			}
			$index->addPodcastIndexPerson($value);
		}
		foreach ($this->listOfHashes($podcast['soundbites'] ?? null) as $bite) {
			$soundbite = [
				'startTime' => $this->seconds($bite['startTime'] ?? null, 'item.podcast.soundbites.startTime'),
				'duration'  => $this->seconds($bite['duration'] ?? null, 'item.podcast.soundbites.duration'),
			];
			if (($bite['title'] ?? '') !== '') {
				$soundbite['title'] = (string)$bite['title'];
			}
			$index->addPodcastIndexSoundbite($soundbite);
		}
	}

	/**
	 * @template T of object
	 *
	 * @param class-string<T> $class
	 *
	 * @return T
	 */
	private function extension(mixed $extension, string $class): object
	{
		if (!$extension instanceof $class) {
			throw new \RuntimeException(sprintf('Laminas feed extension %s is not loaded; FeedWriter must register it before building the feed.', $class));
		}

		return $extension;
	}

	// ---- validators -------------------------------------------------------

	/**
	 * @param array<string,mixed> $block
	 * @param list<string>        $allowed
	 */
	private function rejectUnknownKeys(array $block, array $allowed, string $where): void
	{
		foreach (array_keys($block) as $key) {
			if (!in_array((string)$key, $allowed, true)) {
				throw $this->error(sprintf('%s.%s is not a known key. Known keys: %s.', $where, $key, implode(', ', $allowed)));
			}
		}
	}

	private function string(mixed $value, string $where): string
	{
		if (!is_scalar($value) || (string)$value === '') {
			throw $this->error("{$where} must be a non-empty string.");
		}

		return (string)$value;
	}

	/** @return array{name: string, email: string} */
	private function owner(mixed $owner): array
	{
		if (!is_array($owner) || ($owner['name'] ?? '') === '' || ($owner['email'] ?? '') === '') {
			throw $this->error('meta.podcast.owner must be {name, email}; Apple sends ownership notices to that address.');
		}

		return ['name' => (string)$owner['name'], 'email' => (string)$owner['email']];
	}

	private function image(mixed $value, string $where): string
	{
		$url   = ($this->absolute)($this->string($value, $where));
		$parts = parse_url($url);
		$path  = is_array($parts) ? strtolower((string)($parts['path'] ?? '')) : '';
		$plain = is_array($parts) && !isset($parts['query']) && !isset($parts['fragment']);
		// Laminas only checks the last three characters, which an ImageWorks
		// URL like `…/imageworks?w=3000&f=jpg` satisfies while Apple rejects it.
		if (!$plain || !(str_ends_with($path, '.jpg') || str_ends_with($path, '.png'))) {
			throw $this->error(
				"{$where} must be an absolute URL ending in .jpg or .png with no query string — Apple requires a 1400–3000px square JPEG or PNG. "
				. 'An ImageWorks URL with parameters is not accepted; point at the stored file or a resized copy with a plain path.',
			);
		}

		return $url;
	}

	/**
	 * @return array<int|string, string|list<string>> Laminas shape: ['Name', 'Parent' => ['Child']]
	 */
	private function categories(mixed $value): array
	{
		$inputs = is_array($value) ? array_values($value) : [$value];
		$out    = [];
		foreach ($inputs as $input) {
			$input     = is_scalar($input) ? (string)$input : '';
			$canonical = PodcastCategories::canonical($input);
			if ($canonical === null) {
				throw $this->error(sprintf(
					'meta.podcast.category "%s" is not an Apple Podcasts category. Did you mean: %s?',
					$input,
					implode(', ', PodcastCategories::suggest($input)),
				));
			}
			if (str_contains($canonical, ' > ')) {
				[$parent, $child] = explode(' > ', $canonical, 2);
				$existing         = $out[$parent] ?? [];
				$out[$parent]     = array_merge(is_array($existing) ? $existing : [], [$child]);
			} else {
				$out[] = $canonical;
			}
		}

		return $out;
	}

	private function explicit(mixed $value, string $where): bool
	{
		if (is_bool($value)) {
			return $value;
		}
		if (is_string($value) && in_array(strtolower($value), ['yes', 'true'], true)) {
			return true;
		}
		if (is_string($value) && in_array(strtolower($value), ['no', 'false', 'clean'], true)) {
			return false;
		}

		throw $this->error("{$where} must be true or false.");
	}

	/** @param list<string> $allowed */
	private function oneOf(mixed $value, array $allowed, string $where): string
	{
		$value = is_scalar($value) ? (string)$value : '';
		if (!in_array($value, $allowed, true)) {
			throw $this->error(sprintf('%s must be one of %s.', $where, implode(', ', $allowed)));
		}

		return $value;
	}

	/**
	 * Seconds as an int. `HH:MM:SS` / `MM:SS` strings are converted, so the
	 * feed always carries plain seconds — which every app accepts and which
	 * matches Laminas' declared parameter type.
	 */
	private function duration(mixed $value): int
	{
		if (is_int($value) || is_float($value)) {
			return (int)round($value);
		}
		$value = is_scalar($value) ? trim((string)$value) : '';
		if ($value !== '' && ctype_digit($value)) {
			return (int)$value;
		}
		if (preg_match('/^(\d+)(?::([0-5]\d))?:([0-5]\d)$/', $value, $m) === 1) {
			// [H:]M:S — with two groups it is M:S, with three it is H:M:S.
			$hours   = $m[2] === '' ? 0 : (int)$m[1];
			$minutes = $m[2] === '' ? (int)$m[1] : (int)$m[2];

			return $hours * 3600 + $minutes * 60 + (int)$m[3];
		}

		throw $this->error('item.podcast.duration must be seconds (a number) or HH:MM:SS.');
	}

	private function integer(mixed $value, string $where): int
	{
		if (!is_numeric($value) || (int)$value != $value) {
			throw $this->error("{$where} must be a whole number.");
		}

		return (int)$value;
	}

	/** Laminas wants soundbite times as numeric strings. */
	private function seconds(mixed $value, string $where): string
	{
		if (!is_numeric($value)) {
			throw $this->error("{$where} must be a number of seconds.");
		}

		return (string)(float)$value;
	}

	/**
	 * A single hash or a list of hashes.
	 *
	 * @return list<array<string,mixed>>
	 */
	private function listOfHashes(mixed $value): array
	{
		if (!is_array($value) || $value === []) {
			return [];
		}

		return array_is_list($value) ? array_values(array_filter($value, is_array(...))) : [$value];
	}

	private function error(string $message): \DomainException
	{
		return new \DomainException('cms.feed.rss: ' . $message);
	}
}
