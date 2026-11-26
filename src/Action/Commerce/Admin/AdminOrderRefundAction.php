<?php

declare(strict_types=1);

namespace TotalCMS\Action\Commerce\Admin;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use TotalCMS\Domain\Auth\Service\ActorContext;
use TotalCMS\Domain\Commerce\Exception\CommerceException;
use TotalCMS\Domain\Commerce\Order\OrderRepository;
use TotalCMS\Domain\Commerce\Service\MoneyConverter;
use TotalCMS\Domain\Commerce\Service\MoneyFilter;
use TotalCMS\Domain\Commerce\Service\RefundService;
use TotalCMS\Domain\Money\Money;

/**
 * POST /admin/commerce/orders/{id}/refund: ask the payment provider to
 * give money back. Super admins only.
 *
 * Body: `amount` as a person writes it in the order's currency ("10.00"),
 * `reason` (optional, kept with the order), `restock` (ticked when the items
 * are coming back).
 *
 * The order does not change here. It shows the refund when the provider's
 * webhook confirms it.
 */
final readonly class AdminOrderRefundAction
{
	public function __construct(
		private OrderActionResponder $responder,
		private RefundService $refunds,
		private OrderRepository $orders,
		private ActorContext $actors,
		private MoneyConverter $converter,
		private MoneyFilter $money,
	) {
	}

	/** @param array<string,string> $args */
	public function __invoke(ServerRequestInterface $request, ResponseInterface $response, array $args): ResponseInterface
	{
		$body    = (array)$request->getParsedBody();
		$typed   = is_string($body['amount'] ?? null) ? trim($body['amount']) : '';
		$reason  = is_string($body['reason'] ?? null) ? $body['reason'] : '';
		$restock = filter_var($body['restock'] ?? false, FILTER_VALIDATE_BOOLEAN);

		return $this->responder->run($request, $response, $args['id'] ?? '', function (string $id) use ($typed, $reason, $restock): string {
			$order  = $this->orders->find($id) ?? throw CommerceException::orderNotFound();
			$amount = $this->amount($typed, $order->currency());
			$result = $this->refunds->request($id, $amount, $reason, $restock, $this->actors->current());
			if (!$result->accepted) {
				throw CommerceException::refundRefused();
			}

			return 'A refund of ' . ($this->money)($amount, $order->currency()) . ' was requested. This order shows it once the payment provider confirms.';
		});
	}

	/**
	 * What staff typed, in minor units. Only a plain amount with no more
	 * decimals than the currency has: "10", "10.5", "10.50". Anything else
	 * is refused, not rounded: this is money leaving the store.
	 *
	 * @throws CommerceException
	 */
	private function amount(string $typed, string $currency): int
	{
		$places  = Money::exponent($currency);
		$pattern = $places > 0 ? '/^\d{1,12}(\.\d{1,' . $places . '})?\z/' : '/^\d{1,12}\z/';
		if (preg_match($pattern, $typed) !== 1) {
			throw CommerceException::refundAmountInvalid();
		}

		return $this->converter->fromDecimal($typed, $currency)->amount();
	}
}
