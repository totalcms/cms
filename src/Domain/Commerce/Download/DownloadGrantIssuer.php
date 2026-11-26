<?php

declare(strict_types=1);

namespace TotalCMS\Domain\Commerce\Download;

use TotalCMS\Domain\Commerce\Order\DownloadIssuer;
use TotalCMS\Domain\Commerce\Order\OrderData;
use TotalCMS\Domain\Commerce\Order\OrderStateMachine;

/**
 * Delivers a paid order's downloads.
 *
 * A digital line whose product has a download (a file, a file in a bucket,
 * a link) is delivered the moment the order is paid: the buyer's links are
 * made from the order whenever it is shown ({@see DownloadLinks}), so there
 * is nothing to store but that the line is done. A digital line with no
 * download is left as it is: something else delivers that one (a license
 * key, a person).
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
			if (!$this->downloads->find($line['productId']) instanceof Deliverable) {
				continue;
			}

			$order = $order->withLine((string)$lineId, ['fulfillmentStatus' => OrderStateMachine::FULFILLED, 'fulfillmentNote' => 'Delivered as a download']);
		}

		return $order;
	}
}
