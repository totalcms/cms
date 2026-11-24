<?php

declare(strict_types=1);

namespace TotalCMS\Domain\Commerce\Download;

use Psr\Log\LoggerInterface;
use TotalCMS\Domain\Commerce\Contract\DownloadSource;
use TotalCMS\Factory\LogChannel;
use TotalCMS\Factory\LoggerFactory;

/**
 * The places a product's download can be kept, in the order they are asked:
 * the product's own file, then what extensions have added, then its link.
 * The first with something for a product is the one that delivers it.
 */
final class DownloadSources
{
	/** @var list<DownloadSource> */
	private array $added = [];

	private readonly LoggerInterface $logger;

	public function __construct(
		private readonly FileDownloadSource $file,
		private readonly UrlDownloadSource $url,
		LoggerFactory $loggerFactory,
	) {
		$this->logger = $loggerFactory->channelLogger(LogChannel::Commerce);
	}

	public function add(DownloadSource $source): void
	{
		$this->added[] = $source;
	}

	/**
	 * The source that has this product's download, and what it has. A
	 * source that fails is passed over: one broken extension must not stop
	 * a file the site holds itself from being delivered.
	 *
	 * @param array<string,mixed> $product the stored product
	 *
	 * @return array{0: DownloadSource, 1: Deliverable}|null
	 */
	public function for(string $collection, array $product): ?array
	{
		foreach ([$this->file, ...$this->added, $this->url] as $source) {
			try {
				$found = $source->find($collection, $product);
			} catch (\Throwable $e) {
				$this->logger->error('A download source failed and was passed over', ['source' => $source::class, 'exception' => $e]);
				continue;
			}
			if ($found instanceof Deliverable && $found->name !== '') {
				return [$source, $found];
			}
		}

		return null;
	}
}
