<?php

declare(strict_types=1);

namespace TotalCMS\Action\Admin\Utils;

use Psr\Http\Message\ServerRequestInterface;
use TotalCMS\Domain\Activity\Data\ActivityFilter;
use TotalCMS\Domain\Activity\Listener\ActivityListener;
use TotalCMS\Domain\Activity\Repository\ActivityRepository;
use TotalCMS\Domain\Activity\Service\ActivityRecorder;
use TotalCMS\Domain\Activity\Service\McpToolActivity;
use TotalCMS\Domain\Auth\Data\ActorKind;

/** The Activity utility: one filtered, paged slice of the activity log. */
final readonly class ActivityPageData implements UtilsPageData
{
	public function __construct(
		private ActivityRepository $repository,
		private ActivityRecorder $recorder,
	) {
	}

	public function build(ServerRequestInterface $request, string $page, string $action): array
	{
		$filter = ActivityFilter::fromQuery($request->getQueryParams());
		$total  = $this->repository->count($filter);

		// Every event the log can hold, plus any an older or newer version
		// recorded, so a row is always findable by its own event name.
		$events = array_values(array_unique([...ActivityListener::EVENTS, McpToolActivity::EVENT, ...$this->repository->distinct('event')]));
		sort($events);

		return [
			'activity' => [
				'entries'       => $this->repository->query($filter),
				'filter'        => $filter,
				'query'         => $filter->toQuery(),
				'total'         => $total,
				'page'          => $filter->page,
				'pages'         => max(1, (int)ceil($total / ActivityFilter::PAGE_SIZE)),
				'events'        => $events,
				'actorKinds'    => array_map(static fn (ActorKind $kind): string => $kind->value, ActorKind::cases()),
				'collections'   => $this->repository->distinct('collection'),
				'enabled'       => $this->recorder->enabled(),
				'retentionDays' => $this->recorder->retentionDays(),
			],
		];
	}
}
