<?php

declare(strict_types=1);

namespace TotalCMS\Action\Commerce\Admin;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use TotalCMS\Domain\Commerce\Exception\CommerceException;
use TotalCMS\Domain\Commerce\Order\OrderData;
use TotalCMS\Domain\Commerce\Order\OrderRepository;
use TotalCMS\Renderer\JsonRenderer;
use TotalCMS\Renderer\TwigRenderer;

/**
 * How every action on an order answers.
 *
 * The order page posts with HTMX and is answered with the order redrawn,
 * with a line saying what was done or why it was refused. Any other caller
 * gets JSON, a refusal in the store's one error shape.
 */
final readonly class OrderActionResponder
{
	public function __construct(
		private OrderRepository $orders,
		private OrderPageView $view,
		private TwigRenderer $twig,
		private JsonRenderer $json,
	) {
	}

	/**
	 * Do $action to the order and answer.
	 *
	 * @param \Closure(string): string $action given the order's id; returns what to tell staff was done
	 */
	public function run(ServerRequestInterface $request, ResponseInterface $response, string $orderId, \Closure $action): ResponseInterface
	{
		$htmx = $request->getHeaderLine('HX-Request') === 'true';

		try {
			// An id that is not an order's is not looked up at all.
			if (preg_match('/^[a-f0-9]{32}\z/', $orderId) !== 1) {
				throw CommerceException::orderNotFound();
			}
			$done = $action($orderId);
		} catch (CommerceException $e) {
			$order = $htmx && $e->errorCode !== 'order_not_found' ? $this->orders->find($orderId) : null;

			return $order instanceof OrderData
				? $this->page($response->withStatus($e->status), $order, ['error' => $e->getMessage()])
				: $this->json->json($response->withStatus($e->status), ['error' => ['code' => $e->errorCode, 'message' => $e->getMessage()]]);
		}

		$order = $this->orders->find($orderId);
		if ($htmx && $order instanceof OrderData) {
			return $this->page($response, $order, ['notice' => $done]);
		}

		return $this->json->json($response, ['ok' => true, 'message' => $done]);
	}

	/** @param array<string,string> $with */
	private function page(ResponseInterface $response, OrderData $order, array $with): ResponseInterface
	{
		return $this->twig->template($response, 'admin/commerce/partials/order-body.twig', $with + $this->view->of($order));
	}
}
