<?php

declare(strict_types=1);

namespace TotalCMS\Domain\Video\Service;

use Psr\Log\LoggerInterface;
use TotalCMS\Domain\Video\Data\VideoInfo;
use TotalCMS\Support\HttpClientInterface;

/**
 * Fetches metadata (thumbnail, title, aspect ratio) for a video from its oEmbed endpoint.
 *
 * Uses the oEmbed protocol to retrieve video metadata including thumbnail URL,
 * title, and dimensions. Gracefully handles failures and returns safe defaults.
 */
final readonly class VideoMetadataFetcher
{
	public function __construct(
		private HttpClientInterface $http,
		private LoggerInterface $logger,
	) {
	}

	/**
	 * Fetch video metadata from the oEmbed endpoint.
	 *
	 * @return array{thumbnail:string,title:string,aspectRatio:string}
	 */
	public function fetch(VideoInfo $info): array
	{
		// No oEmbed endpoint — return info's values without making a request
		if ($info->oembedEndpoint === null) {
			return [
				'thumbnail'   => $info->thumbnail,
				'title'       => '',
				'aspectRatio' => $info->aspectRatio,
			];
		}

		try {
			$response = $this->http->request(
				'GET',
				$info->oembedEndpoint,
				[
					'timeout' => 3,
					// GuzzleHttpClient expects a list of "Name: value" strings,
					// not an associative array — an associative 'Accept' key
					// here is silently dropped (foreach walks values only).
					'headers' => ['Accept: application/json'],
					// oEmbed responses are small JSON documents; cap the
					// download so a misbehaving/malicious endpoint can't tie
					// up a save with an oversized response.
					'max_bytes' => 65536,
				],
			);

			if (!$response->isSuccess()) {
				$this->logFailure('HTTP ' . $response->statusCode, $info);

				return $this->fallbackResult($info);
			}

			$data = $response->json();
			if (!is_array($data)) {
				$this->logFailure('Response body is not JSON object', $info);

				return $this->fallbackResult($info);
			}

			/** @var array<mixed> $data */
			if (array_is_list($data)) {
				$this->logFailure('Response body is not JSON object', $info);

				return $this->fallbackResult($info);
			}

			// A thumbnail the provider derived from the URL itself beats the one
			// oEmbed offers: Publitio, for one, returns a 300×200 centre crop where
			// the provider can build the full-width poster. Providers with no
			// derived thumbnail take oEmbed's as before.
			$thumbnail   = $info->thumbnail !== '' ? $info->thumbnail : $this->extractThumbnail($data);
			$title       = $this->extractTitle($data);
			$aspectRatio = $this->extractAspectRatio($data, $info);

			return [
				'thumbnail'   => $thumbnail,
				'title'       => $title,
				'aspectRatio' => $aspectRatio,
			];
		} catch (\Throwable $e) {
			$this->logFailure($e->getMessage(), $info);

			return $this->fallbackResult($info);
		}
	}

	/**
	 * Extract and validate thumbnail URL from oEmbed response.
	 *
	 * Only accepts http:// or https:// URLs; rejects data URIs, javascript:, etc.
	 *
	 * @param array<string,mixed> $data
	 */
	private function extractThumbnail(array $data): string
	{
		$url = $data['thumbnail_url'] ?? null;
		if (!is_string($url)) {
			return '';
		}

		if (str_starts_with($url, 'https://') || str_starts_with($url, 'http://')) {
			return $url;
		}

		return '';
	}

	/**
	 * Extract and normalize title from oEmbed response.
	 *
	 * Trims whitespace and limits to 200 characters using mb_substr to preserve
	 * multi-byte UTF-8 codepoints.
	 *
	 * @param array<string,mixed> $data
	 */
	private function extractTitle(array $data): string
	{
		$title = $data['title'] ?? null;
		if (!is_string($title)) {
			return '';
		}

		$trimmed = trim($title);

		return mb_substr($trimmed, 0, 200);
	}

	/**
	 * Extract aspect ratio from oEmbed response or fall back to default.
	 *
	 * If width and height are positive integers, computes w:h reduced by GCD.
	 * Otherwise returns the info's default ratio.
	 *
	 * @param array<string,mixed> $data
	 */
	private function extractAspectRatio(array $data, VideoInfo $info): string
	{
		$width  = $this->extractPositiveInt($data['width'] ?? null);
		$height = $this->extractPositiveInt($data['height'] ?? null);

		if ($width === null || $height === null) {
			return $info->aspectRatio;
		}

		$gcd = $this->gcd($width, $height);
		$w   = (int)($width / $gcd);
		$h   = (int)($height / $gcd);

		return "{$w}:{$h}";
	}

	/**
	 * Accept a positive int, or a numeric string an oEmbed provider sent as
	 * `"width": "1280"` instead of a JSON number — either way, only a value
	 * that reduces to a positive integer counts.
	 */
	private function extractPositiveInt(mixed $value): ?int
	{
		if (is_int($value)) {
			return $value > 0 ? $value : null;
		}

		if (is_string($value) && is_numeric($value)) {
			$intValue = (int)$value;

			return $intValue > 0 ? $intValue : null;
		}

		return null;
	}

	/**
	 * Compute the greatest common divisor using Euclid's algorithm.
	 */
	private function gcd(int $a, int $b): int
	{
		while ($b !== 0) {
			$temp = $b;
			$b    = $a % $b;
			$a    = $temp;
		}

		return $a;
	}

	/**
	 * Return fallback result with info's thumbnail, empty title, and info's ratio.
	 *
	 * @return array{thumbnail:string,title:string,aspectRatio:string}
	 */
	private function fallbackResult(VideoInfo $info): array
	{
		return [
			'thumbnail'   => $info->thumbnail,
			'title'       => '',
			'aspectRatio' => $info->aspectRatio,
		];
	}

	/**
	 * Log a fetch failure with context.
	 */
	private function logFailure(string $reason, VideoInfo $info): void
	{
		$this->logger->info('Video metadata fetch failed', [
			'provider' => $info->provider,
			'videoId'  => $info->videoId,
			'endpoint' => $info->oembedEndpoint,
			'reason'   => $reason,
		]);
	}
}
