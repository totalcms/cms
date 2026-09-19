<?php

declare(strict_types=1);

namespace TotalCMS\Domain\Video\Provider;

use TotalCMS\Domain\Video\Data\VideoInfo;

/**
 * Jet-Stream (jet-stream.com) — EU streaming platform with its own
 * "Privacy Player" page.
 *
 * Unlike every other provider the asset is not in the path: the player at
 * `player.jet-stream.com/` (legacy host `rrr.sz.xlcdn.com`) is driven
 * entirely by query parameters, of which `account` and `file` identify
 * the asset and the rest (`type`, `service`, `poster`, `sub`, `token`, …)
 * are carried through untouched. The iframe `src` the embed dialog hands
 * out is therefore kept as-is apart from forcing `output=player`. The
 * dialog's "URL" output is instead the load balancer's HLS playlist
 * (`takeoff.jetstre.am/?…&output=playlist.m3u8`), which cannot go in an
 * iframe; that form is accepted too and rewritten to the player URL.
 *
 * Playback options baked into a pasted URL (`autostart`, `repeat`, `mute`)
 * are stripped so the Twig options are the single source of truth, as
 * they are for YouTube and Vimeo. embedQuery() maps them back onto the
 * player's own parameter names, which were read out of the player bundle
 * (`autostart=1`, `repeat=1`, `mute=1`).
 *
 * Thumbnail: only when the URL carries `poster=` — the player resolves
 * that filename through the account's download service, and the same URL
 * (verified against the demo account) works as a plain <img> src. There
 * is no oEmbed endpoint, so no title and the default 16:9 ratio.
 */
final class JetStreamProvider extends AbstractVideoProvider
{
	private const PLAYER_HOSTS = ['player.jet-stream.com', 'rrr.sz.xlcdn.com', 'takeoff.jetstre.am'];

	/** Params that only make sense for the playlist form or that Twig owns. */
	private const DROPPED_PARAMS = ['output', 'protocol', 'autostart', 'repeat', 'mute'];

	public function id(): string
	{
		return 'jetstream';
	}

	public function matches(string $url): bool
	{
		return $this->params($url) !== null;
	}

	public function parse(string $url): VideoInfo
	{
		$params  = $this->params($url) ?? [];
		$account = (string)($params['account'] ?? '');
		$file    = (string)($params['file'] ?? '');

		foreach (self::DROPPED_PARAMS as $key) {
			unset($params[$key]);
		}
		$params['output'] = 'player';

		$embedUrl  = 'https://player.jet-stream.com/?' . http_build_query($params);
		$poster    = (string)($params['poster'] ?? '');
		$thumbnail = $poster === '' ? '' : 'https://takeoff.jetstre.am/?' . http_build_query([
			'account'  => $account,
			'file'     => $poster,
			'type'     => 'download',
			'service'  => 'apache',
			'protocol' => 'https',
			'output'   => 'download',
		]);

		return new VideoInfo('jetstream', "{$account}/{$file}", $embedUrl, $thumbnail, null);
	}

	/** @param array<string,mixed> $options */
	public function embedQuery(array $options): string
	{
		$params = [];

		if (!empty($options['autoplay'])) {
			$params['autostart'] = 1;
		}
		if (!empty($options['muted'])) {
			$params['mute'] = 1;
		}
		if (!empty($options['loop'])) {
			$params['repeat'] = 1;
		}

		return http_build_query($params);
	}

	/**
	 * The URL's query parameters, when the host is a Jet-Stream player or
	 * load balancer and both `account` and `file` are present.
	 *
	 * @return array<string,string>|null
	 */
	private function params(string $url): ?array
	{
		$parts = parse_url($url);
		$host  = strtolower((string)($parts['host'] ?? ''));

		if (!in_array($host, self::PLAYER_HOSTS, true)) {
			return null;
		}

		parse_str((string)($parts['query'] ?? ''), $params);

		if (!is_string($params['account'] ?? null) || $params['account'] === ''
			|| !is_string($params['file'] ?? null) || $params['file'] === '') {
			return null;
		}

		/** @var array<string,string> $params */
		return array_filter($params, 'is_string');
	}
}
