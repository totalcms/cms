<?php

declare(strict_types=1);

namespace TotalCMS\Action\Admin;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use TotalCMS\Domain\Extension\Service\ExtensionManager;
use TotalCMS\Domain\ImageWorks\Filter\ImageFilterRegistry;
use TotalCMS\Renderer\TwigRenderer;

/**
 * Action.
 */
readonly class AdminImageworksAction
{
	/** Owners whose looks and duotones share the main row: core and the site config */
	private const MAIN_ROW_OWNERS = ['core', 'config'];

	public function __construct(
		private TwigRenderer $twigRenderer,
		private MediaToolAccess $access,
		private ImageFilterRegistry $filters,
		private ExtensionManager $extensions,
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

		return $this->twigRenderer->template($response, 'admin/imageworks.twig', [
			'filters'    => $filters,
			'filterRows' => $this->rows($filters),
		]);
	}

	/**
	 * The strip's rows: core and site looks then duotones, one row per
	 * extension with looks or duotones (titled with its name), then every
	 * effect in Extras. Rows only label the tiles; whether a tile replaces or
	 * stacks comes from its group, so a look in an extension row still
	 * replaces a core look.
	 *
	 * @param list<array{name:string, label:string, group:string, owner:string}> $filters
	 *
	 * @return list<array{kind:string, title:?string, filters:list<array{name:string, label:string, group:string, owner:string}>}>
	 */
	private function rows(array $filters): array
	{
		$main      = [];
		$duotones  = [];
		$extras    = [];
		$byOwner   = [];
		foreach ($filters as $filter) {
			if ($filter['group'] === 'effect') {
				$extras[] = $filter;
			} elseif (!in_array($filter['owner'], self::MAIN_ROW_OWNERS, true)) {
				$byOwner[$filter['owner']][] = $filter;
			} elseif ($filter['group'] === 'duotone') {
				$duotones[] = $filter;
			} else {
				$main[] = $filter;
			}
		}

		$rows = [['kind' => 'main', 'title' => null, 'filters' => [...$main, ...$duotones]]];
		foreach ($byOwner as $owner => $ownFilters) {
			usort($ownFilters, static fn (array $a, array $b): int => ($a['group'] === 'duotone') <=> ($b['group'] === 'duotone'));
			$rows[] = ['kind' => 'extension', 'title' => $this->extensionName((string)$owner), 'filters' => $ownFilters];
		}
		$rows[] = ['kind' => 'extras', 'title' => null, 'filters' => $extras];

		return array_values(array_filter($rows, static fn (array $row): bool => $row['filters'] !== []));
	}

	private function extensionName(string $extensionId): string
	{
		$name = $this->extensions->getExtension($extensionId)['name'] ?? null;

		return is_string($name) && $name !== '' ? $name : $extensionId;
	}
}
