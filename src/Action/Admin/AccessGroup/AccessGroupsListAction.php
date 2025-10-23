<?php

declare(strict_types=1);

namespace TotalCMS\Action\Admin\AccessGroup;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use TotalCMS\Domain\AccessGroup\Service\AccessGroupLister;
use TotalCMS\Renderer\TwigRenderer;

/**
 * List all access groups.
 */
readonly class AccessGroupsListAction
{
	public function __construct(
		private AccessGroupLister $accessGroupLister,
		private TwigRenderer $twigRenderer,
	) {
	}

	/**
	 * @param array<string,string> $args
	 */
	public function __invoke(
		ServerRequestInterface $request,
		ResponseInterface $response,
		array $args,
	): ResponseInterface {
		return $this->twigRenderer->template($response, 'admin/utils/access-groups.twig', [
			'groups' => $this->accessGroupLister->listAll(),
		]);
	}
}
