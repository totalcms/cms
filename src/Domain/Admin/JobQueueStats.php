<?php

namespace TotalCMS\Domain\Admin;

use TotalCMS\Domain\JobQueue\Repository\JobRepository;
use TotalCMS\Domain\JobQueue\Service\JobManager;
use TotalCMS\Domain\Rendering\Utilities\HTMLUtils;

readonly class JobQueueStats implements \Stringable
{
	public function __construct(
		private string $api,
		private string $collection = '',
	) {
	}

	public function tableByType(string $header = 'Job Queue by Type'): string
	{
		$jobManager = new JobManager(new JobRepository());

		$stats = $this->collection === ''
			? $jobManager->queueByType()
			: $jobManager->queueByTypeForCollection($this->collection);

		$rows = '';
		foreach ($stats as $key => $value) {
			$col1 = HTMLUtils::element('td', $key);
			$col2 = HTMLUtils::element('td', strval($value));
			$rows .= HTMLUtils::element('tr', $col1 . $col2, ['class' => strtolower($key)]);
		}

		$table = HTMLUtils::element('table', $rows, [
			'class'           => 'jobqueue-stats jobqueue-by-type cms-colors',
			'data-collection' => $this->collection,
			'data-api'        => $this->api,
		]);

		$header  = HTMLUtils::element('h4', $header);

		return HTMLUtils::element('div', $header . $table, [
			'class' => 'jobqueue-stats-wrapper',
		]);
	}

	public function tableByStatus(string $header = 'Job Queue by Status'): string
	{
		$jobManager = new JobManager(new JobRepository());

		$stats = $this->collection === ''
			? $jobManager->queueByStatus()
			: $jobManager->queueByStatusForCollection($this->collection);

		$rows = '';
		foreach ($stats as $key => $value) {
			$col1 = HTMLUtils::element('td', $key);
			$col2 = HTMLUtils::element('td', strval($value));
			$rows .= HTMLUtils::element('tr', $col1 . $col2, ['class' => strtolower($key)]);
		}

		$table = HTMLUtils::element('table', $rows, [
			'class'           => 'jobqueue-stats jobqueue-by-status cms-colors',
			'data-collection' => $this->collection,
			'data-api'        => $this->api,
		]);

		$header  = HTMLUtils::element('h4', $header);

		return HTMLUtils::element('div', $header . $table, [
			'class' => 'jobqueue-stats-wrapper',
		]);
	}

	public function allStats(): string
	{
		$tables  = $this->tableByType() . $this->tableByStatus();

		return HTMLUtils::element('div', $tables, [
			'class' => 'jobqueue-all-stats',
		]);
	}

	public function __toString(): string
	{
		return $this->allStats();
	}
}
