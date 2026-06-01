<?php

declare(strict_types=1);

namespace TotalCMS\Action\Automation;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use TotalCMS\Domain\Automation\Service\AutomationGuard;
use TotalCMS\Domain\Object\Service\ObjectFetcher;
use TotalCMS\Domain\Object\Service\ObjectUpdater;

/**
 * One-click re-enable for an auto-disabled automation (the editor banner posts
 * here). Flips `enabled` back on and clears the failure counter so the
 * auto-disable breaker starts fresh, then redirects back to the editor.
 */
readonly class AutomationReenableAction
{
	public function __construct(
		private ObjectFetcher $objectFetcher,
		private ObjectUpdater $objectUpdater,
		private AutomationGuard $guard,
	) {
	}

	/** @param array<string,string> $args The routing arguments */
	public function __invoke(ServerRequestInterface $request, ResponseInterface $response, array $args): ResponseInterface
	{
		$id = $args['id'] ?? '';

		$data            = $this->objectFetcher->fetchObject('automations', $id)->toArray();
		$data['enabled'] = true;
		$this->objectUpdater->updateObject('automations', $id, $data);
		$this->guard->reset($id);

		// Back to the editor: strip the trailing /enable from this request path.
		$redirect = (string)preg_replace('#/enable$#', '', $request->getUri()->getPath());

		return $response->withHeader('Location', $redirect)->withStatus(302);
	}
}
