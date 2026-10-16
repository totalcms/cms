<?php

declare(strict_types=1);

namespace TotalCMS\Action\Extension;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use TotalCMS\Domain\Extension\Service\Install\ExtensionRemover;
use TotalCMS\Domain\Extension\Service\Install\InstallException;
use TotalCMS\Renderer\JsonRenderer;

/**
 * POST /api/extensions/{extension}/remove — admin-only. Refusals for
 * bundled / Composer / project extensions come back as 400 with the owner
 * named; an unknown id is 404.
 */
readonly class ExtensionRemoveAction
{
	public function __construct(
		private ExtensionRemover $remover,
		private JsonRenderer $renderer,
	) {
	}

	/** @param array<string,string> $args */
	public function __invoke(ServerRequestInterface $request, ResponseInterface $response, array $args): ResponseInterface
	{
		$id = $args['extension'] ?? '';

		try {
			$this->remover->remove($id);
		} catch (InstallException $e) {
			$status = $e->codeName === InstallException::NOT_FOUND ? 404 : 400;

			return $this->renderer->json($response, ['error' => $e->getMessage(), 'code' => $e->codeName], $status);
		}

		return $this->renderer->json($response, ['status' => 'removed', 'id' => $id]);
	}
}
