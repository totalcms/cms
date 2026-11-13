<?php

declare(strict_types=1);

namespace TotalCMS\Action\Admin;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use TotalCMS\Domain\ImageWorks\Filter\ImageFilterRegistry;
use TotalCMS\Renderer\TwigRenderer;

/**
 * Action.
 */
readonly class AdminImageworksAction
{
	public function __construct(
		private TwigRenderer $twigRenderer,
		private MediaToolAccess $access,
		private ImageFilterRegistry $filters,
	) {
	}

	public function __invoke(
		ServerRequestInterface $request,
		ResponseInterface $response,
	): ResponseInterface {
		$this->access->assertCanRead($request, 'image');

		// Labels of core filters are translation keys; the template translates
		// those by owner and shows every other label as given.
		$filters = [];
		foreach ($this->filters->all() as $registered) {
			$filters[] = [
				'name'  => $registered->name,
				'label' => $registered->filter->label(),
				'group' => $registered->group,
				'owner' => $registered->owner,
			];
		}

		return $this->twigRenderer->template($response, 'admin/imageworks.twig', ['filters' => $filters]);
	}
}
