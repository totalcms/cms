<?php

declare(strict_types=1);

namespace TotalCMS\Domain\Import;

use Psr\Log\LoggerInterface;
use TotalCMS\Factory\LogChannel;
use TotalCMS\Factory\LoggerFactory;
use TotalCMS\Infrastructure\Filesystem\FileUtils;
use TotalCMS\Support\Config;
use TotalCMS\Support\QuietCall;
use TotalCMS\Support\RemoteFileDownloader;

/**
 * Download the media an importer only knows by URL, at the moment the object
 * is written rather than when the import is queued.
 *
 * An importer puts `property => URL` under {@see self::PAYLOAD_KEY} in the
 * object data it queues; ObjectImporter hands that map here and saves the
 * downloaded files like any other local media. The WordPress importer used to
 * download every featured image inside the upload request — 273 images held
 * one export's request open for close to a minute, past a typical
 * max_execution_time — so the request now only queues and each job fetches
 * its own image.
 *
 * Each file lands in its own temp folder under its URL's filename, so the
 * stored image keeps a meaningful name and ObjectImporter's sibling alt-text
 * lookup never sees another download's files.
 */
final readonly class RemoteMediaFetcher
{
	public const PAYLOAD_KEY = '_remoteMedia';

	private const TIMEOUT = 15;

	private LoggerInterface $logger;

	public function __construct(
		private RemoteFileDownloader $downloader,
		private Config $config,
		LoggerFactory $loggerFactory,
	) {
		$this->logger = $loggerFactory->channelLogger(LogChannel::JobRunner);
	}

	/**
	 * Replace each URL in the payload's remote-media map with the path of its
	 * downloaded file. A download that fails is logged and left out, so the
	 * object still imports without that media.
	 *
	 * @param array<string,mixed> $objectData
	 *
	 * @return array{0: array<string,mixed>, 1: list<string>} the object data without the map, and the downloaded paths to {@see self::cleanup()}
	 */
	public function fetch(array $objectData): array
	{
		$remote = $objectData[self::PAYLOAD_KEY] ?? [];
		unset($objectData[self::PAYLOAD_KEY]);

		$paths = [];
		foreach (is_array($remote) ? $remote : [] as $property => $url) {
			if (!is_string($property) || !is_string($url) || preg_match('#^https?://#i', $url) !== 1) {
				continue;
			}

			$path = $this->download($url);
			if ($path !== null) {
				$objectData[$property] = $path;
				$paths[]               = $path;
			}
		}

		return [$objectData, $paths];
	}

	/** @param list<string> $paths */
	public function cleanup(array $paths): void
	{
		foreach ($paths as $path) {
			QuietCall::run(static fn (): bool => is_file($path) && unlink($path));
			QuietCall::run(static fn (): bool => rmdir(dirname($path)));
		}
	}

	private function download(string $url): ?string
	{
		$dir = $this->config->tmpdir . '/remote-media-' . bin2hex(random_bytes(8));

		try {
			if (!FileUtils::ensureDirectory($dir, 0700)) {
				throw new \RuntimeException('Could not create temp folder ' . $dir);
			}

			$tempFile = $this->downloader->downloadPublic($url, ['timeout' => self::TIMEOUT, 'prefix' => 'remote-media']);
			$path     = $dir . '/' . RemoteFileDownloader::filenameFor($url);
			if (!QuietCall::run(static fn (): bool => rename($tempFile, $path))) {
				throw new \RuntimeException('Could not move the download into ' . $dir);
			}

			return $path;
		} catch (\RuntimeException $e) {
			$this->logger->warning(sprintf('Could not download import media %s: %s', $url, $e->getMessage()));
			QuietCall::run(static fn (): bool => rmdir($dir));

			return null;
		}
	}
}
