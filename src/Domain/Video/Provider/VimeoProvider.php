<?php

declare(strict_types=1);

namespace TotalCMS\Domain\Video\Provider;

use TotalCMS\Domain\Video\Data\VideoInfo;

final class VimeoProvider extends AbstractVideoProvider
{
	public function id(): string
	{
		return 'vimeo';
	}

	public function matches(string $url): bool
	{
		return $this->match($url) !== null;
	}

	public function parse(string $url): VideoInfo
	{
		[$id, $hash] = $this->match($url) ?? ['', null];

		$embed = "https://player.vimeo.com/video/{$id}";
		if ($hash !== null) {
			$embed .= "?h={$hash}";
		}

		return new VideoInfo('vimeo', $id, $embed, '', 'https://vimeo.com/api/oembed.json?url=' . rawurlencode($url));
	}

	/** @param array<string,mixed> $options */
	public function embedQuery(array $options): string
	{
		$params = [];

		if (!empty($options['autoplay'])) {
			$params['autoplay'] = 1;
		}
		if (!empty($options['muted'])) {
			$params['muted'] = 1;
		}
		if (!empty($options['loop'])) {
			$params['loop'] = 1;
		}
		if (!empty($options['vcolor'])) {
			$params['color'] = (string)$options['vcolor'];
		}

		return http_build_query($params);
	}

	/** @return array{0:string,1:?string}|null */
	private function match(string $url): ?array
	{
		$parts = parse_url($url);
		$host  = strtolower((string)($parts['host'] ?? ''));
		$path  = (string)($parts['path'] ?? '');

		if (in_array($host, ['vimeo.com', 'www.vimeo.com'], true)
			&& preg_match('#^/(\d+)(?:/([A-Za-z0-9]+))?/?$#', $path, $m) === 1
		) {
			return [$m[1], $m[2] ?? null];
		}

		if ($host === 'player.vimeo.com' && preg_match('#^/video/(\d+)/?$#', $path, $m) === 1) {
			return [$m[1], null];
		}

		return null;
	}
}
