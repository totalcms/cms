<?php

declare(strict_types=1);

namespace TotalCMS\Domain\Activity\Service;

use League\Csv\EscapeFormula;
use League\Csv\Writer;
use TotalCMS\Domain\Activity\Data\ActivityEntry;
use TotalCMS\Domain\Activity\Data\ActivityFilter;
use TotalCMS\Domain\Activity\Repository\ActivityRepository;

/**
 * The activity rows a filter matches, as CSV: every match, not just the page
 * on screen. Rows stream from SQLite into a php://temp stream, which spills to
 * disk past 2 MB, so a long audit trail never sits in memory.
 *
 * Cells that a spreadsheet would run as a formula (=, +, -, @ …) are escaped:
 * object ids, file names and refusal reasons can come from visitors.
 */
final readonly class ActivityCsvExporter
{
	private const HEADERS = [
		'Date', 'Event', 'Level', 'Actor', 'User', 'Auth Collection', 'OAuth Client',
		'API Key', 'On Behalf Of', 'Collection', 'Object', 'Summary', 'Detail',
	];

	public function __construct(
		private ActivityRepository $repository,
	) {
	}

	/**
	 * @return resource A stream rewound to the start
	 */
	public function export(ActivityFilter $filter): mixed
	{
		$stream = fopen('php://temp', 'w+');
		if ($stream === false) {
			throw new \RuntimeException('Could not open a temporary stream for the activity export');
		}

		$csv = Writer::createFromStream($stream);
		$csv->addFormatter((new EscapeFormula())->escapeRecord(...));
		$csv->insertOne(self::HEADERS);

		foreach ($this->repository->each($filter) as $entry) {
			$csv->insertOne($this->row($entry));
		}

		rewind($stream);

		return $stream;
	}

	public function filename(ActivityFilter $filter): string
	{
		$range = match (true) {
			$filter->from !== '' && $filter->to !== ''  => "{$filter->from}-to-{$filter->to}",
			$filter->from !== ''                        => "from-{$filter->from}",
			$filter->to !== ''                          => "to-{$filter->to}",
			default                                     => date('Y-m-d'),
		};

		return "activity-{$range}.csv";
	}

	/**
	 * @return list<string>
	 */
	private function row(ActivityEntry $entry): array
	{
		return [
			date('c', $entry->createdAt),
			$entry->event,
			$entry->level,
			$entry->actorKind,
			$entry->userId,
			$entry->authCollection,
			$entry->clientId,
			$entry->apiKeyId,
			$entry->initiator,
			$entry->collection,
			$entry->objectId,
			$entry->summary,
			$entry->detail === [] ? '' : (string)json_encode($entry->detail, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
		];
	}
}
