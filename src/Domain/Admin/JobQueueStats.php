<?php

namespace TotalCMS\Domain\Admin;

use TotalCMS\Domain\JobQueue\Repository\JobRepository;
use TotalCMS\Domain\JobQueue\Service\JobManager;
use TotalCMS\Utils\HTMLUtils;

final class JobQueueStats
{
	public function __construct(
		private string $collection = "",
	){
	}

	private function statsTable(): string
	{
		$jobManager = new JobManager(new JobRepository());

		$stats = empty($this->collection)
			? $jobManager->queueStats()
			: $jobManager->queueStatsForCollection($this->collection);

		$rows = '';
		foreach ($stats as $key => $value) {
			$col1 = HTMLUtils::element('td', $key);
			$col2 = HTMLUtils::element('td', strval($value));
			$rows .= HTMLUtils::element('tr',  $col1 . $col2, ['class' => strtolower($key)]);
		}

		$table = HTMLUtils::element('table', $rows, [
			'class'           => 'jobqueue-stats cms-colors',
			'data-collection' => $this->collection,
		]);
		return $table;
	}

	public function build(): string
	{
		$table = $this->statsTable();

		return $table;
	}

	public function __toString()
	{
		return $this->build();
	}
}
