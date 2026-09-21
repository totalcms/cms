<?php

declare(strict_types=1);

namespace TotalCMS\Domain\Seo\IndexNow;

use Psr\Log\LoggerInterface;
use TotalCMS\Domain\Seo\Service\SeoSettingsLoader;
use TotalCMS\Factory\LogChannel;
use TotalCMS\Factory\LoggerFactory;
use TotalCMS\Support\HttpClientInterface;

/**
 * One IndexNow submission: a list of absolute URLs on this site, posted to
 * the shared endpoint. Submitting to any one participating engine reaches
 * them all, so there is exactly one endpoint and no per-engine
 * configuration.
 *
 * The engines verify the submission by fetching {@see keyLocation()} and
 * checking it contains the key — IndexNowKeyAction serves that file. The
 * protocol treats 200 and 202 as accepted; 422 means a URL or the key was
 * rejected and retrying will not help, so that is logged and swallowed;
 * anything else (429, 5xx, transport) is thrown so the job queue retries.
 */
readonly class IndexNowSubmitter
{
	public const ENDPOINT = 'https://api.indexnow.org/IndexNow';

	/** The protocol's hard limit per submission. */
	public const MAX_URLS = 10000;

	private LoggerInterface $logger;

	public function __construct(
		private HttpClientInterface $http,
		private SeoSettingsLoader $seoSettings,
		LoggerFactory $loggerFactory,
	) {
		$this->logger = $loggerFactory->channelLogger(LogChannel::IndexNow);
	}

	/**
	 * Enabled in Site SEO with a key present. Without a key nothing can be
	 * verified, so nothing is sent — the key autogenerates the first time the
	 * Site SEO form is saved with the toggle on.
	 */
	public function isConfigured(): bool
	{
		$settings = $this->seoSettings->load();

		return $settings->indexNowEnabled && $settings->indexNowKey !== '';
	}

	/** Where the engines will look for the key file. */
	public function keyLocation(): string
	{
		$settings = $this->seoSettings->load();

		return $settings->baseUrl . '/' . $settings->indexNowKey . '.txt';
	}

	/**
	 * True when the engines accepted the submission; false when there was
	 * nothing to send, or it was rejected outright.
	 *
	 * @param list<string> $urls absolute URLs on this site
	 *
	 * @throws \RuntimeException on a retryable failure (rate limit, server error, transport)
	 */
	public function submit(array $urls): bool
	{
		if (!$this->isConfigured()) {
			return false;
		}

		$settings = $this->seoSettings->load();
		$host     = (string)parse_url($settings->baseUrl, PHP_URL_HOST);

		// Only this site's URLs are ours to submit; the endpoint rejects the
		// whole batch when one is off-host, so drop strays here. Hosts are
		// case-insensitive, so the scheme and host are lowercased before the
		// dedupe — two spellings of one address are one URL to an engine.
		$urls = array_values(array_unique(array_map(
			static fn (string $url): string => (string)preg_replace_callback(
				'#^(https?://[^/]+)#i',
				static fn (array $m): string => strtolower($m[1]),
				$url,
			),
			array_filter($urls, static fn (string $url): bool => $host !== '' && strcasecmp((string)parse_url($url, PHP_URL_HOST), $host) === 0),
		)));

		if ($urls === []) {
			return false;
		}

		$urls = array_slice($urls, 0, self::MAX_URLS);

		$payload = json_encode([
			'host'        => $host,
			'key'         => $settings->indexNowKey,
			'keyLocation' => $this->keyLocation(),
			'urlList'     => $urls,
		], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);

		$response = $this->http->request('POST', self::ENDPOINT, [
			'headers' => ['Content-Type' => 'application/json; charset=utf-8'],
			'body'    => $payload,
			'timeout' => 10,
		]);

		if ($response->statusCode === 200 || $response->statusCode === 202) {
			$this->logger->info('IndexNow accepted submission', ['urls' => count($urls)]);

			return true;
		}

		if ($response->statusCode === 422) {
			// The key file could not be verified, or a URL is malformed —
			// resending the same thing will fail the same way.
			$this->logger->warning('IndexNow rejected submission; check that the key file is reachable', [
				'keyLocation' => $this->keyLocation(),
				'urls'        => $urls,
				'body'        => $response->body,
			]);

			return false;
		}

		throw new \RuntimeException(sprintf('IndexNow returned HTTP %d', $response->statusCode));
	}
}
