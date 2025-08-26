<?php

namespace TotalCMS\Domain\Rendering\Utilities;

class EmbedBuilder
{
	/** @param array<string,mixed> $options */
	public static function embed(string $url, array $options = []): string
	{
		if (str_contains($url, 'vimeo')) {
			return self::vimeo($url, $options);
		}
		if (str_contains($url, 'youtube')) {
			return self::youtube($url, $options);
		}
		if (str_ends_with($url, 'mp4')) {
			return self::video($url, $options);
		}
		if (str_ends_with($url, 'mp3')) {
			return self::audio($url, $options);
		}

		return self::iframe($url);
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
		$options = array_merge([
			'autoplay' => 0,
			'loop'     => 0,
			'vcolor'   => '33aaff',
		], $options);

		if (str_contains($url, 'vimeo')) {
			$path  = parse_url($url, PHP_URL_PATH);
			$parts = explode('/', (string)$path);

			if (count($parts) < 2) {
				return self::link($url);
			}

			$videoId  = $parts[1];
			$unlisted = $parts[2] ?? null;

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
			$query = http_build_query($params, '', '&amp;');

			return HTMLUtils::iframe("//player.vimeo.com/video/$videoId?$query", 'cms-video-embed');
		}

		return self::link($url);
	}

	/**
	 * @SuppressWarnings("PHPMD.Superglobals")
	 *
	 * @param array<string,mixed> $options
	 * */
	public static function youtube(string $url, array $options = []): string
	{
		$options = array_merge([
			'autoplay' => 0,
			'loop'     => 0,
			'ycolor'   => 'red',
			'ytheme'   => 'dark',
			'private'  => true,
		], $options);

		if (str_contains($url, 'youtube')) {
			$queryString = parse_url($url, PHP_URL_QUERY);
			parse_str((string)$queryString, $queryParams);
			$videoId = is_string($queryParams['v']) ? $queryParams['v'] : '';

			if (empty($videoId)) {
				return self::link($url);
			}

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

			if (strpos($url, 'list') !== false) {
				// playlist
				$query['listType'] = 'playlist';
				$query['list']     = $videoId;
				$videoId           = '';
			}
			$httpQuery = http_build_query($query, '', '&amp;');

			$domain = $options['private'] === true ? 'www.youtube-nocookie.com' : 'www.youtube.com';

			return HTMLUtils::iframe("//$domain/embed/$videoId?$httpQuery", 'cms-video-embed');
		}

		return self::link($url);
	}

	public static function iframe(string $url): string
	{
		return HTMLUtils::iframe($url);
	}

	public static function link(string $url): string
	{
		return HTMLUtils::element('a', $url, ['href' => $url]);
	}
}
