<?php

declare(strict_types=1);

namespace TotalCMS\Domain\Setup\Service;

use Odan\Session\PhpSession;
use TotalCMS\Support\Config;

/**
 * Tracks wizard progress through the multi-step setup flow.
 *
 * Steps: environment → data-path → account → license
 */
class SetupStateManager
{
	private const SESSION_KEY = 'setup_state';

	/** @var list<string> */
	private const STEPS = ['environment', 'data-path', 'account', 'license'];

	public function __construct(
		private readonly PhpSession $session,
		private readonly Config $config,
	) {
	}

	/**
	 * Check if a specific step has been completed.
	 */
	public function isStepComplete(string $step): bool
	{
		// Environment and data-path can also be inferred from system state
		if ($step === 'data-path' && $this->dataPathExists()) {
			return true;
		}

		if ($step === 'account' && $this->authCollectionExists()) {
			return true;
		}

		$completed = $this->getCompletedSteps();
		return in_array($step, $completed, true);
	}

	/**
	 * Mark a step as completed.
	 */
	public function completeStep(string $step): void
	{
		$completed = $this->getCompletedSteps();
		if (!in_array($step, $completed, true)) {
			$completed[] = $step;
			$this->session->set(self::SESSION_KEY, $completed);
		}
	}

	/**
	 * Get the route name for the first incomplete step.
	 */
	public function getCurrentStep(): string
	{
		foreach (self::STEPS as $step) {
			if (!$this->isStepComplete($step)) {
				return 'setup-' . $step;
			}
		}

		return 'setup-complete';
	}

	/**
	 * Check if all setup steps are complete.
	 */
	public function isSetupComplete(): bool
	{
		foreach (self::STEPS as $step) {
			if (!$this->isStepComplete($step)) {
				return false;
			}
		}

		return true;
	}

	/**
	 * @return list<string>
	 */
	private function getCompletedSteps(): array
	{
		$steps = $this->session->get(self::SESSION_KEY);
		return is_array($steps) ? array_values(array_filter($steps, 'is_string')) : [];
	}

	private function dataPathExists(): bool
	{
		return $this->config->datadir !== '' && is_dir($this->config->datadir);
	}

	private function authCollectionExists(): bool
	{
		if (!$this->dataPathExists()) {
			return false;
		}

		$authCollection = $this->config->auth['collection'] ?? 'auth';
		$authPath       = $this->config->datadir . '/' . $authCollection;

		return is_dir($authPath);
	}
}
