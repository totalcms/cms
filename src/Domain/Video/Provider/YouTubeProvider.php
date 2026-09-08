<?php

declare(strict_types=1);

namespace TotalCMS\Domain\Video\Provider;

use TotalCMS\Domain\Video\Data\VideoInfo;

final class YouTubeProvider extends AbstractVideoProvider
{
	private const ID = '[A-Za-z0-9_-]{6,}';

	public function id(): string
	{
		return 'youtube';
	}

	public function matches(string $url): bool
	{
		return $this->videoId($url) !== null || $this->playlistId($url) !== null;
	}

	public function parse(string $url): VideoInfo
	{
		$listId = $this->playlistId($url);

		if ($listId !== null) {
			return new VideoInfo('youtube', "list:{$listId}", "https://www.youtube-nocookie.com/embed/videoseries?list={$listId}", '', null);
		}

		$id = (string)$this->videoId($url);

		return new VideoInfo('youtube', $id, "https://www.youtube-nocookie.com/embed/{$id}", "https://img.youtube.com/vi/{$id}/hqdefault.jpg", null);
	}

	/**
	 * Query string for autoplay/muted/loop. YouTube's iframe API requires a
	 * `playlist` equal to the video's own id for a single video to loop.
	 *
	 * @param array<string,mixed> $options
	 */
	public function embedQuery(array $options): string
	{
		$params = [];

		if (!empty($options['autoplay'])) {
			$params['autoplay'] = 1;
		}
		if (!empty($options['muted'])) {
			$params['mute'] = 1;
		}
		if (!empty($options['loop'])) {
			$params['loop']     = 1;
			$params['playlist'] = (string)($options['videoId'] ?? '');
		}

		return http_build_query($params);
	}

	private function videoId(string $url): ?string
	{
		$parts = parse_url($url);
		$host  = strtolower((string)($parts['host'] ?? ''));
		$path  = (string)($parts['path'] ?? '');
		parse_str((string)($parts['query'] ?? ''), $query);

		if (in_array($host, ['youtu.be', 'www.youtu.be'], true) && preg_match('#^/(' . self::ID . ')#', $path, $m) === 1) {
			return $m[1];
		}
		if (preg_match('/(^|\.)youtube(-nocookie)?\.com$/', $host) !== 1) {
			return null;
		}
		if (isset($query['v']) && is_string($query['v']) && preg_match('/^' . self::ID . '$/', $query['v']) === 1) {
			return $query['v'];
		}
		if (preg_match('#^/(?:shorts|embed|live)/(' . self::ID . ')#', $path, $m) === 1) {
			return $m[1];
		}

		return null;
	}

	/**
	 * `youtube.com/playlist?list={id}` — a playlist with no specific video.
	 * `watch?v=X&list=Y` is NOT this form: videoId() above already resolves
	 * that to video X, taking priority.
	 */
	private function playlistId(string $url): ?string
	{
		$parts = parse_url($url);
		$host  = strtolower((string)($parts['host'] ?? ''));
		$path  = (string)($parts['path'] ?? '');
		parse_str((string)($parts['query'] ?? ''), $query);

		if (preg_match('/(^|\.)youtube(-nocookie)?\.com$/', $host) !== 1) {
			return null;
		}
		if ($path !== '/playlist') {
			return null;
		}
		if (isset($query['list']) && is_string($query['list']) && $query['list'] !== '') {
			return $query['list'];
		}

		return null;
	}
}
