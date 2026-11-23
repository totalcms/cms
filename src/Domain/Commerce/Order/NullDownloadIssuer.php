<?php

declare(strict_types=1);

namespace TotalCMS\Domain\Commerce\Order;

/** Digital delivery is not built yet: a paid order's lines stay as they are. */
final readonly class NullDownloadIssuer implements DownloadIssuer
{
	public function issue(OrderData $order): OrderData
	{
		return $order;
	}
}
