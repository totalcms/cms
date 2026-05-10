<?php

declare(strict_types=1);

namespace TotalCMS\Domain\Setup\Service;

use Odan\Session\SessionInterface;
use TotalCMS\Domain\Setup\Data\SetupState;
use TotalCMS\Domain\Setup\Repository\SetupStateRepository;
use TotalCMS\Support\Config;

/**
 * Tracks wizard progress through the multi-step setup flow.
 *
 * Steps: environment → data-path → account → license → server-config
 *
 * The manager owns the wizard semantics — which steps exist, what the
 * "current" step is, when the wizard is fully done. Persistence of the
 * step list lives in SetupStateRepository; session state and the
 * filesystem-derived signals (datadir present, auth collection has
 * users) stay here because they're domain-level inferences about the
 * install rather than I/O concerns.
 */
class SetupStateManager
{
	private const SESSION_KEY = 'setup_state';

	/** @var list<string> */
	private const STEPS = ['environment', 'data-path', 'account', 'license', 'server-config'];

	public function __construct(
		private readonly SessionInterface $session,
		private readonly Config $config,
		private readonly SetupStateRepository $stateRepository,
	) {
	}

	/**
	 * Check if a specific step has been completed.
	 */
	public function isStepComplete(string $step): bool
	{
		// Some steps can also be inferred from system state — useful when
		// the durable record is missing but the install clearly progressed.
		if ($step === 'data-path' && $this->dataPathExists()) {
			return true;
		}

		if ($step === 'account' && $this->authCollectionExists()) {
			return true;
		}

		return in_array($step, $this->getCompletedSteps(), true);
	}

	/**
	 * Mark a step as completed.
	 *
	 * Writes to both session (fast path for the current request) and the
	 * durable state file (survives login's session destroy and browser
	 * close). Persists the UNION of session + file rather than just
	 * appending the current step — this self-heals across earlier
	 * completeStep calls that couldn't write the file (e.g. the data-path
	 * step itself, when Config::datadir was still pointing at the
	 * pre-move auto-detected location).
	 */
	public function completeStep(string $step): void
	{
		$sessionSteps = $this->getSessionSteps();
		if (!in_array($step, $sessionSteps, true)) {
			$sessionSteps[] = $step;
			$this->session->set(self::SESSION_KEY, $sessionSteps);
		}

		$state = $this->stateRepository->read() ?? SetupState::empty();

		foreach ($sessionSteps as $sessionStep) {
			$state = $state->withStep($sessionStep);
		}

		if (!$state->isComplete() && $this->allStepsCompleteFromSignals()) {
			$state = $state->markComplete();
		}

		$this->stateRepository->write($state);
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
	 *
	 * Trusts the durable state file's `completedAt` first — it's stamped
	 * by completeStep() once the wizard reaches its final step and survives
	 * login's session destroy. Falls back to session+filesystem signals
	 * while the wizard is still in progress.
	 */
	public function isSetupComplete(): bool
	{
		$state = $this->stateRepository->read();
		if ($state !== null && $state->isComplete()) {
			return true;
		}

		// Backward-compat for installs that finished setup before the state
		// file existed. Only fires when NO state file is present — a
		// present-but-incomplete file means a wizard is in progress and
		// shouldn't be auto-completed just because the user has reached
		// the account step.
		if ($state === null && $this->authCollectionHasUsers()) {
			$this->stateRepository->write(
				(new SetupState(self::STEPS))->markComplete(),
			);

			return true;
		}

		return $this->allStepsCompleteFromSignals();
	}

	private function allStepsCompleteFromSignals(): bool
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
	private function getSessionSteps(): array
	{
		$steps = $this->session->get(self::SESSION_KEY);

		return is_array($steps) ? array_values(array_filter($steps, is_string(...))) : [];
	}

	/**
	 * Union of completed steps from the state file and the current session.
	 *
	 * @return list<string>
	 */
	private function getCompletedSteps(): array
	{
		$sessionSteps = $this->getSessionSteps();

		$state     = $this->stateRepository->read();
		$fileSteps = $state !== null ? $state->completedSteps : [];

		return array_values(array_unique([...$fileSteps, ...$sessionSteps]));
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

	private function authCollectionHasUsers(): bool
	{
		if (!$this->authCollectionExists()) {
			return false;
		}

		$authCollection = $this->config->auth['collection'] ?? 'auth';
		$authPath       = $this->config->datadir . '/' . $authCollection;

		$entries = @scandir($authPath);
		if ($entries === false) {
			return false;
		}

		foreach ($entries as $entry) {
			if (str_ends_with($entry, '.json')) {
				return true;
			}
		}

		return false;
	}
}
