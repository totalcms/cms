<?php

declare(strict_types=1);

namespace TotalCMS\Domain\Commerce\Download;

use TotalCMS\Domain\Commerce\Order\OrderData;
use TotalCMS\Domain\Commerce\Order\OrderStateMachine;
use TotalCMS\Domain\Commerce\Service\CommerceSettings;
use TotalCMS\Domain\Security\SignedToken;
use TotalCMS\Support\Config;

/**
 * The links a buyer downloads what they bought with.
 *
 * A link is signed and runs out (Settings → Commerce → Download Link
 * Lifetime). It is made when it is shown, not kept: every time the order's
 * own page is opened the links on it are fresh, so a buyer whose receipt is
 * a week old is never stuck. What does run out for good is the number of
 * downloads a line allows.
 */
final readonly class DownloadLinks
{
	public const PURPOSE = 'commerce-download';

	/** The payment statuses in which what was bought is still the buyer's. */
	private const PAID = ['paid', 'partially_refunded'];

	public function __construct(
		private ProductDownload $downloads,
		private SignedToken $tokens,
		private CommerceSettings $settings,
		private Config $config,
	) {
	}

	/**
	 * A link for each delivered line of the order that has a file to give.
	 *
	 * @return list<array{lineId: string, title: string, fileName: string, size: int, remaining: int, url: string}> `url` is '' when
	 *                                                                                                             the line's downloads are used up
	 */
	public function for(OrderData $order): array
	{
		if (!$this->entitled($order)) {
			return [];
		}

		$links = [];
		foreach ($order->lines() as $lineId => $line) {
			$file = $this->deliverableFor($line);
			if ($file === null) {
				continue;
			}

			$remaining = $this->remaining($line);
			$links[]   = [
				'lineId'    => (string)$lineId,
				'title'     => is_string($line['title'] ?? null) ? $line['title'] : '',
				'fileName'  => $file->name,
				'size'      => $file->size,
				'remaining' => $remaining,
				'url'       => $remaining > 0 ? $this->url($order, (string)$lineId) : '',
			];
		}

		return $links;
	}

	/**
	 * The order as its buyer may see it, with the links to what they bought.
	 *
	 * @return array<string,mixed>
	 */
	public function forBuyer(OrderData $order): array
	{
		return ['downloads' => $this->for($order)] + $order->forBuyer();
	}

	/** Whether what the order bought is the buyer's to download. */
	public function entitled(OrderData $order): bool
	{
		return in_array($order->paymentStatus(), self::PAID, true);
	}

	/**
	 * How many more times a line may be downloaded.
	 *
	 * @param array<string,mixed> $line
	 */
	public function remaining(array $line): int
	{
		return max(0, $this->settings->downloadMaxCount() - self::used($line));
	}

	/**
	 * How many times a line has been downloaded: `downloads.count` on the
	 * line. The count is the line's, not a file's, so a file the store
	 * replaced does not start it again.
	 *
	 * @param array<string,mixed> $line
	 */
	public static function used(array $line): int
	{
		$counts = is_array($line['downloads'] ?? null) ? $line['downloads'] : [];

		return (int)array_sum(array_filter($counts, is_int(...)));
	}

	/**
	 * What a line gives, when it is a delivered download.
	 *
	 * @param array<string,mixed> $line
	 */
	public function deliverableFor(array $line): ?Deliverable
	{
		$delivered = ($line['fulfillmentStatus'] ?? '') === OrderStateMachine::FULFILLED;
		if (($line['type'] ?? '') !== 'digital' || !$delivered || !is_string($line['productId'] ?? null)) {
			return null;
		}

		return $this->downloads->find($line['productId']);
	}

	private function url(OrderData $order, string $lineId): string
	{
		$token = $this->tokens->sign(self::PURPOSE, ['o' => $order->id(), 'l' => $lineId], time() + $this->settings->downloadTtlHours() * 3600);

		return rtrim($this->config->url, '/') . rtrim($this->config->api, '/') . '/commerce/download/' . $token;
	}
}
