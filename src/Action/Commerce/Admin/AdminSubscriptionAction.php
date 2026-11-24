<?php

declare(strict_types=1);

namespace TotalCMS\Action\Commerce\Admin;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use TotalCMS\Domain\Commerce\Subscription\SubscriptionData;
use TotalCMS\Domain\Commerce\Subscription\SubscriptionRepository;
use TotalCMS\Renderer\TwigRenderer;

/**
 * GET /admin/commerce/subscriptions/{id}: one subscription, with what it
 * bills, who for, where it stands and every payment made for it.
 */
final readonly class AdminSubscriptionAction
{
	public function __construct(
		private TwigRenderer $twig,
		private SubscriptionRepository $subscriptions,
		private SubscriptionPageView $view,
	) {
	}

	/** @param array<string,string> $args */
	public function __invoke(ServerRequestInterface $request, ResponseInterface $response, array $args): ResponseInterface
	{
		$id           = $args['id'] ?? '';
		$url          = ['path' => $request->getUri()->getPath(), 'query' => $request->getUri()->getQuery(), 'page' => 'commerce'];
		$subscription = preg_match('/^[a-f0-9]{32}\z/', $id) === 1 ? $this->subscriptions->find($id) : null;
		if (!$subscription instanceof SubscriptionData) {
			return $this->twig->template($response->withStatus(404), 'admin/404.twig', ['url' => ['page' => '404'] + $url]);
		}

		return $this->twig->template($response, 'admin/commerce/subscription.twig', ['url' => $url] + $this->view->of($subscription));
	}
}
