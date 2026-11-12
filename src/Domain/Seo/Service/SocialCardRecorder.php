<?php

declare(strict_types=1);

namespace TotalCMS\Domain\Seo\Service;

use TotalCMS\Domain\Seo\Data\SocialCardOutcome;

/**
 * Keeps what each cms.seo.head() call decided about its share card, while
 * the Share Cards utility renders a page. Off unless started, so an ordinary
 * page render keeps nothing.
 */
final class SocialCardRecorder
{
	private bool $recording = false;

	/** @var list<SocialCardOutcome> */
	private array $outcomes = [];

	public function start(): void
	{
		$this->recording = true;
		$this->outcomes  = [];
	}

	/**
	 * The outcomes since start(), oldest first; recording stops and the list clears.
	 *
	 * @return list<SocialCardOutcome>
	 */
	public function stop(): array
	{
		$outcomes        = $this->outcomes;
		$this->recording = false;
		$this->outcomes  = [];

		return $outcomes;
	}

	public function record(SocialCardOutcome $outcome): void
	{
		if ($this->recording) {
			$this->outcomes[] = $outcome;
		}
	}

	public function isRecording(): bool
	{
		return $this->recording;
	}
}
