<?php

namespace TotalCMS\Domain\Rendering\Utilities;

use TotalCMS\Domain\Video\Provider\VimeoProvider;
use TotalCMS\Domain\Video\Provider\YouTubeProvider;
use TotalCMS\Domain\Video\Service\VideoUrlResolver;

class EmbedBuilder
{
	/** @param array<string,mixed> $options */
	public static function embed(string $url, array $options = []): string
	{
		$url = htmlspecialchars_decode($url);

		// A URL with an unsafe scheme (javascript:, data:, ftp:, …) is never
		// safe to embed — checked up front, before the mp3 shortcut and
		// before the resolver, so nothing downstream can build an
		// <audio>/<iframe> around it. A schemeless relative URL
		// (`/embeds/thing.html`) or protocol-relative one
		// (`//cdn.example.com/page`) is NOT unsafe and falls through as before.
		if (VideoUrlResolver::hasUnsafeScheme($url)) {
			return '';
		}

		if (str_ends_with($url, 'mp3')) {
			return self::audio($url, $options);
		}

		$info = self::resolver()->resolve($url);

		return match ($info->provider) {
			'youtube' => self::youtube($url, $options),
			'vimeo'   => self::vimeo($url, $options),
			'file'    => self::video($url, $options),
			'unknown' => self::iframe($url),
			// Livid, Bunny, Cloudflare, Loom, Wistia: a plain iframe on the
			// resolved embed URL. embedQuery() defaults to '' until a later
			// task adds per-provider playback options.
			default => HTMLUtils::iframe($info->embedUrl, 'cms-video-embed'),
		};
	}

	private static function resolver(): VideoUrlResolver
	{
		return new VideoUrlResolver(VideoUrlResolver::defaultProviders());
	}

	/** @param array<string,mixed> $attrs */
	public static function video(string $url, array $attrs = []): string
	{
		$attrs = array_merge([
			'src' => $url,
		], $attrs);

		return HTMLUtils::element('video', '', $attrs);
	}

	/** @param array<string,mixed> $attrs */
	public static function audio(string $url, array $attrs = []): string
	{
		$attrs = array_merge([
			'src' => $url,
		], $attrs);

		return HTMLUtils::element('audio', '', $attrs);
	}

	/** @param array<string,mixed> $options */
	public static function vimeo(string $url, array $options = []): string
	{
		$url     = htmlspecialchars_decode($url);
		$options = array_merge([
			'autoplay' => 0,
			'loop'     => 0,
			'vcolor'   => '33aaff',
		], $options);

		$provider = new VimeoProvider();

		if (!$provider->matches($url)) {
			return self::link($url);
		}

		$info    = $provider->parse($url);
		$videoId = $info->videoId;

		// The unlisted hash (if any) rides on the resolved embed URL's own
		// query string, so pull it back off rather than re-parsing $url.
		parse_str((string)parse_url($info->embedUrl, PHP_URL_QUERY), $embedParams);
		$unlisted = is_string($embedParams['h'] ?? null) ? $embedParams['h'] : null;

		$params = array_filter([
			'h'        => $unlisted,
			'autoplay' => $options['autoplay'],
			'color'    => $options['vcolor'],
			'loop'     => $options['loop'],
			'api'      => 1,
			'badge'    => 0,
			'byline'   => 0,
			'portrait' => 0,
			'title'    => 0,
		]);
		$query = http_build_query($params);

		return HTMLUtils::iframe("//player.vimeo.com/video/$videoId?$query", 'cms-video-embed');
	}

	/**
	 * @SuppressWarnings("PHPMD.Superglobals")
	 *
	 * @param array<string,mixed> $options
	 * */
	public static function youtube(string $url, array $options = []): string
	{
		$url     = htmlspecialchars_decode($url);
		$options = array_merge([
			'autoplay' => 0,
			'loop'     => 0,
			'ycolor'   => 'red',
			'ytheme'   => 'dark',
			'private'  => true,
		], $options);

		$provider = new YouTubeProvider();

		if (!$provider->matches($url)) {
			return self::link($url);
		}

		$videoId = $provider->parse($url)->videoId;

		parse_str((string)parse_url($url, PHP_URL_QUERY), $queryParams);

		$query = [
			'autoplay'    => $options['autoplay'],
			'loop'        => $options['loop'],
			'color'       => $options['ycolor'],
			'theme'       => $options['ytheme'],
			'origin'      => $_SERVER['HTTP_HOST'] ?? $_SERVER['SERVER_NAME'] ?? 'localhost',
			'enablejsapi' => 1,
			'rel'         => 0,
			'showinfo'    => 0,
		];

		if (str_starts_with($videoId, 'list:')) {
			// A playlist with no specific video (youtube.com/playlist?list=...):
			// embed the "videoseries" pseudo-video with the real playlist id.
			$listId  = substr($videoId, strlen('list:'));
			$videoId = 'videoseries';
			$query   = ['list' => $listId] + $query;
		} elseif (isset($queryParams['list']) && is_string($queryParams['list']) && $queryParams['list'] !== '') {
			// watch?v=X&list=Y: keep embedding video X, but pass along the
			// URL's real playlist id — never the video id.
			$query['list'] = $queryParams['list'];
		}
		$httpQuery = http_build_query($query);

		$domain = $options['private'] === true ? 'www.youtube-nocookie.com' : 'www.youtube.com';

		return HTMLUtils::iframe("//$domain/embed/$videoId?$httpQuery", 'cms-video-embed');
	}

	public static function iframe(string $url): string
	{
		return HTMLUtils::iframe($url);
	}

	public static function link(string $url): string
	{
		return HTMLUtils::link($url, $url);
	}
}
