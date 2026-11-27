<?php

declare(strict_types=1);

namespace TotalCMS\Domain\Commerce\Download;

use TotalCMS\Domain\Commerce\Order\DownloadIssuer;
use TotalCMS\Domain\Commerce\Order\OrderData;
use TotalCMS\Domain\Commerce\Order\OrderStateMachine;

/**
 * Delivers a paid order's downloads, and settles what has nothing to deliver.
 *
 * A digital line whose product has a download (a file, a file in a bucket,
 * a link) is delivered the moment the order is paid: the buyer's links are
 * made from the order whenever it is shown ({@see DownloadLinks}), so there
 * is nothing to store but that the line is done.
 *
 * A digital line whose product has no download has nothing to send: a
 * donation, a tip, a thank-you. Unless something else delivers it, which the
 * line itself says: it sold an access group (given when the group is), or
 * its product has Fulfillment Data (a listener or a person delivers it, and
 * marks it done). Those stay as they are.
 *
 * A download that could not be looked for is not "no download": the line
 * stays undelivered, and the order is flagged when nobody delivers it.
 */
final readonly class DownloadGrantIssuer implements DownloadIssuer
{
	private const DIGITAL = 'digital';

	public function __construct(private ProductDownload $downloads)
	{
	}

	public function issue(OrderData $order): OrderData
	{
		foreach ($order->lines() as $lineId => $line) {
			$delivered = ($line['fulfillmentStatus'] ?? '') === OrderStateMachine::FULFILLED;
			if (($line['type'] ?? '') !== self::DIGITAL || $delivered || !is_string($line['productId'] ?? null)) {
				continue;
			}

			[$download, $known] = $this->downloads->look($line['productId']);
			$change             = match (true) {
				$download instanceof Deliverable          => ['fulfillmentStatus' => OrderStateMachine::FULFILLED, 'fulfillmentNote' => 'Delivered as a download'],
				!$known, $this->deliveredElsewhere($line) => ['fulfillmentStatus' => OrderStateMachine::UNFULFILLED],
				default                                   => ['fulfillmentStatus' => OrderStateMachine::NOT_REQUIRED, 'fulfillmentNote' => ''],
			};

			$order = $order->withLine((string)$lineId, $change);
		}

		return $order;
	}

	/**
	 * Whether something other than a download delivers the line: the group
	 * it sold, or whatever its product's Fulfillment Data is there for.
	 *
	 * @param array<string,mixed> $line
	 */
	private function deliveredElsewhere(array $line): bool
	{
		$group = $line['accessGroup'] ?? '';
		$meta  = $line['meta'] ?? null;

		return (is_string($group) && $group !== '') || (is_array($meta) && $meta !== []) || (is_string($meta) && trim($meta) !== '');
	}
}
