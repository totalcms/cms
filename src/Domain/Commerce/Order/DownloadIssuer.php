<?php

declare(strict_types=1);

namespace TotalCMS\Domain\Commerce\Order;

/**
 * Hands over what a paid order's digital lines bought.
 */
interface DownloadIssuer
{
	/**
	 * Called with the order locked. Returns the order to save, with each
	 * line whose files are now on offer marked fulfilled.
	 */
	public function issue(OrderData $order): OrderData;
}
