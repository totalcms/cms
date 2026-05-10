<?php

declare(strict_types=1);

namespace Tests\Unit\Domain\Setup;

use Odan\Session\MemorySession;
use Odan\Session\SessionInterface;
use PHPUnit\Framework\TestCase;
use TotalCMS\Domain\Setup\Repository\SetupStateRepository;
use TotalCMS\Domain\Setup\Service\SetupStateManager;
use TotalCMS\Support\Config;

use function Tests\Unit\CLI\Command\createTestConfig;

require_once __DIR__ . '/../../CLI/Command/helpers.php';

final class SetupStateManagerTest extends TestCase
{
	private SetupStateManager $manager;
	private MemorySession $session;
	private Config $config;

	protected function setUp(): void
	{
		$this->session = new MemorySession();
		$this->config  = createTestConfig(['datadir' => '/nonexistent/path', 'auth' => ['collection' => 'auth']]);
		$this->manager = $this->makeManager($this->session, $this->config);
	}

	private function makeManager(SessionInterface $session, Config $config): SetupStateManager
	{
		return new SetupStateManager($session, $config, new SetupStateRepository($config));
	}

	public function testNoStepsCompleteInitially(): void
	{
		expect($this->manager->isStepComplete('environment'))->toBeFalse();
		expect($this->manager->isSetupComplete())->toBeFalse();
	}

	public function testCompleteStepStoresInSession(): void
	{
		$this->manager->completeStep('environment');

		expect($this->session->get('setup_state'))->toBe(['environment']);
	}

	public function testIsStepCompleteReadsFromSession(): void
	{
		$this->manager->completeStep('environment');
		$this->manager->completeStep('data-path');

		expect($this->manager->isStepComplete('environment'))->toBeTrue();
		expect($this->manager->isStepComplete('data-path'))->toBeTrue();
		expect($this->manager->isStepComplete('account'))->toBeFalse();
	}

	public function testGetCurrentStepReturnsFirstIncomplete(): void
	{
		$this->manager->completeStep('environment');

		expect($this->manager->getCurrentStep())->toBe('setup-data-path');
	}

	public function testGetCurrentStepReturnsCompleteWhenAllDone(): void
	{
		$this->manager->completeStep('environment');
		$this->manager->completeStep('data-path');
		$this->manager->completeStep('account');
		$this->manager->completeStep('license');
		$this->manager->completeStep('server-config');

		expect($this->manager->getCurrentStep())->toBe('setup-complete');
	}

	public function testIsSetupCompleteWhenAllStepsDone(): void
	{
		$this->manager->completeStep('environment');
		$this->manager->completeStep('data-path');
		$this->manager->completeStep('account');
		$this->manager->completeStep('license');
		$this->manager->completeStep('server-config');

		expect($this->manager->isSetupComplete())->toBeTrue();
	}

	public function testDataPathStepInferredFromFilesystem(): void
	{
		$tmpDir = sys_get_temp_dir() . '/tcms-setup-test-' . uniqid();
		mkdir($tmpDir, 0755);

		$config  = createTestConfig(['datadir' => $tmpDir, 'auth' => ['collection' => 'auth']]);
		$manager = $this->makeManager($this->session, $config);

		expect($manager->isStepComplete('data-path'))->toBeTrue();

		rmdir($tmpDir);
	}

	public function testAccountStepInferredFromAuthCollection(): void
	{
		$tmpDir = sys_get_temp_dir() . '/tcms-setup-test-' . uniqid();
		mkdir($tmpDir . '/auth', 0755, true);

		$config  = createTestConfig(['datadir' => $tmpDir, 'auth' => ['collection' => 'auth']]);
		$manager = $this->makeManager($this->session, $config);

		expect($manager->isStepComplete('account'))->toBeTrue();

		rmdir($tmpDir . '/auth');
		rmdir($tmpDir);
	}

	public function testDuplicateCompleteStepIsIgnored(): void
	{
		$this->manager->completeStep('environment');
		$this->manager->completeStep('environment');

		expect($this->session->get('setup_state'))->toBe(['environment']);
	}

	public function testCompleteStepPersistsToStateFileWhenDatadirExists(): void
	{
		$tmpDir = sys_get_temp_dir() . '/tcms-setup-test-' . uniqid();
		mkdir($tmpDir, 0755);

		try {
			$config  = createTestConfig(['datadir' => $tmpDir, 'auth' => ['collection' => 'auth']]);
			$manager = $this->makeManager($this->session, $config);

			$manager->completeStep('environment');
			$manager->completeStep('data-path');

			$statePath = $tmpDir . '/.system/setup-state.json';
			expect(is_file($statePath))->toBeTrue();

			$state = json_decode((string)file_get_contents($statePath), true);
			expect($state['completed_steps'])->toBe(['environment', 'data-path']);
			expect($state['completed_at'])->toBeNull();
		} finally {
			recursiveRmdir($tmpDir);
		}
	}

	public function testWizardResumesFromStateFileAfterSessionLoss(): void
	{
		// Models the real-world scenario: user gets through three steps, the
		// session is lost (browser close, login destroy, etc.), they revisit
		// /setup, and the wizard picks up at the next incomplete step
		// instead of restarting.
		$tmpDir = sys_get_temp_dir() . '/tcms-setup-test-' . uniqid();
		mkdir($tmpDir, 0755);

		try {
			$config        = createTestConfig(['datadir' => $tmpDir, 'auth' => ['collection' => 'auth']]);
			$firstSession  = new MemorySession();
			$firstManager  = $this->makeManager($firstSession, $config);

			$firstManager->completeStep('environment');
			$firstManager->completeStep('data-path');
			$firstManager->completeStep('account');

			// Simulate session loss: brand new session, brand new manager.
			$secondSession = new MemorySession();
			$secondManager = $this->makeManager($secondSession, $config);

			expect($secondManager->isStepComplete('environment'))->toBeTrue();
			expect($secondManager->isStepComplete('data-path'))->toBeTrue();
			expect($secondManager->isStepComplete('account'))->toBeTrue();
			expect($secondManager->isStepComplete('license'))->toBeFalse();
			expect($secondManager->getCurrentStep())->toBe('setup-license');
		} finally {
			recursiveRmdir($tmpDir);
		}
	}

	public function testStampsCompletedAtWhenLastStepDone(): void
	{
		$tmpDir = sys_get_temp_dir() . '/tcms-setup-test-' . uniqid();
		mkdir($tmpDir, 0755);

		try {
			$config  = createTestConfig(['datadir' => $tmpDir, 'auth' => ['collection' => 'auth']]);
			$manager = $this->makeManager($this->session, $config);

			$manager->completeStep('environment');
			$manager->completeStep('data-path');
			$manager->completeStep('account');
			$manager->completeStep('license');

			$state = json_decode((string)file_get_contents($tmpDir . '/.system/setup-state.json'), true);
			expect($state['completed_at'])->toBeNull();

			$manager->completeStep('server-config');

			$state = json_decode((string)file_get_contents($tmpDir . '/.system/setup-state.json'), true);
			expect($state['completed_at'])->not()->toBeNull();
			expect($manager->isSetupComplete())->toBeTrue();
		} finally {
			recursiveRmdir($tmpDir);
		}
	}

	public function testIsSetupCompleteSurvivesSessionWipeOnceCompletedAtIsStamped(): void
	{
		// Reproduces the bug fix: after login destroys the session, a fresh
		// manager with an empty session must still report setup as complete
		// so SetupCheckMiddleware doesn't bounce a logged-in admin back to
		// the wizard.
		$tmpDir = sys_get_temp_dir() . '/tcms-setup-test-' . uniqid();
		mkdir($tmpDir, 0755);

		try {
			$config  = createTestConfig(['datadir' => $tmpDir, 'auth' => ['collection' => 'auth']]);
			$first   = $this->makeManager(new MemorySession(), $config);

			foreach (['environment', 'data-path', 'account', 'license', 'server-config'] as $step) {
				$first->completeStep($step);
			}

			$afterLogin = $this->makeManager(new MemorySession(), $config);
			expect($afterLogin->isSetupComplete())->toBeTrue();
		} finally {
			recursiveRmdir($tmpDir);
		}
	}

	public function testReconcilesSessionAndFileWhenAnEarlierWriteWasSilentlyDropped(): void
	{
		// Models the data-path-step scenario: when the user picks a new
		// datadir, the directory moves but Config::datadir is still stale
		// for the remainder of that request. completeStep('data-path')
		// updates the session but writeStateFile bails (path doesn't exist
		// at the stale Config::datadir). The next completeStep call (from
		// the next request, with Config refreshed) must reconcile — the
		// orphaned step in session needs to catch up to the file.
		$tmpDir = sys_get_temp_dir() . '/tcms-setup-test-' . uniqid();
		mkdir($tmpDir, 0755);

		try {
			// Pre-seed the state file with only `environment` — this is the
			// state on disk after a stale-Config write attempt for data-path
			// silently no-ops.
			mkdir($tmpDir . '/.system', 0755, true);
			file_put_contents(
				$tmpDir . '/.system/setup-state.json',
				(string)json_encode(['completed_steps' => ['environment'], 'completed_at' => null]),
			);

			// Pre-seed the session with both env AND data-path — this is the
			// state in the session after the stale-Config request committed
			// the step to session but couldn't reach the file.
			$session = new MemorySession();
			$session->set('setup_state', ['environment', 'data-path']);

			$config  = createTestConfig(['datadir' => $tmpDir, 'auth' => ['collection' => 'auth']]);
			$manager = $this->makeManager($session, $config);

			// Now the user submits the account step. The reconcile happens
			// here — the union-write should pick up `data-path` from session
			// and persist it to the file alongside `account`.
			$manager->completeStep('account');

			$state = json_decode((string)file_get_contents($tmpDir . '/.system/setup-state.json'), true);
			expect($state['completed_steps'])->toBe(['environment', 'data-path', 'account']);
		} finally {
			recursiveRmdir($tmpDir);
		}
	}

	public function testInProgressWizardDoesNotShortCircuitOnAuthUsersBackwardCompat(): void
	{
		// Reproduces the bug where the account step (which creates the first
		// admin user) would trip the auth-users backward-compat check inside
		// isSetupComplete and cause SetupCheckMiddleware to bounce a still-
		// in-progress wizard to /admin. The wizard's state file presence (with
		// completed_at = null) is the signal that we're mid-flow and should
		// stay on signals only.
		$tmpDir = sys_get_temp_dir() . '/tcms-setup-test-' . uniqid();
		mkdir($tmpDir . '/auth', 0755, true);
		file_put_contents($tmpDir . '/auth/admin.json', '{"id":"admin"}');

		// State file exists with the wizard partially complete.
		mkdir($tmpDir . '/.system', 0755, true);
		file_put_contents(
			$tmpDir . '/.system/setup-state.json',
			(string)json_encode([
				'completed_steps' => ['environment', 'data-path', 'account'],
				'completed_at'    => null,
			]),
		);

		try {
			$config  = createTestConfig(['datadir' => $tmpDir, 'auth' => ['collection' => 'auth']]);
			$manager = $this->makeManager(new MemorySession(), $config);

			expect($manager->isSetupComplete())->toBeFalse();

			// State file should be untouched — no `completed_at` injection.
			$state = json_decode((string)file_get_contents($tmpDir . '/.system/setup-state.json'), true);
			expect($state['completed_at'])->toBeNull();
		} finally {
			recursiveRmdir($tmpDir);
		}
	}

	public function testIsSetupCompleteHonorsAuthUsersBackwardCompatAndSelfHeals(): void
	{
		// Existing installs that finished setup before this state file
		// existed don't have setup-state.json on disk — but they DO have
		// at least one user record in the auth collection.
		$tmpDir = sys_get_temp_dir() . '/tcms-setup-test-' . uniqid();
		mkdir($tmpDir . '/auth', 0755, true);
		file_put_contents($tmpDir . '/auth/admin.json', '{"id":"admin"}');

		try {
			$config  = createTestConfig(['datadir' => $tmpDir, 'auth' => ['collection' => 'auth']]);
			$manager = $this->makeManager($this->session, $config);

			expect($manager->isSetupComplete())->toBeTrue();

			// Self-heal: the state file should now exist with completed_at set.
			expect(is_file($tmpDir . '/.system/setup-state.json'))->toBeTrue();
			$state = json_decode((string)file_get_contents($tmpDir . '/.system/setup-state.json'), true);
			expect($state['completed_at'])->not()->toBeNull();
		} finally {
			recursiveRmdir($tmpDir);
		}
	}
}

/**
 * Helper: recursively remove a directory tree. Used by tests that create
 * a tmp datadir and need to clean it up regardless of what files the
 * SetupStateManager wrote inside.
 */
function recursiveRmdir(string $dir): void
{
	if (!is_dir($dir)) {
		return;
	}

	$entries = scandir($dir);
	if ($entries === false) {
		return;
	}

	foreach ($entries as $entry) {
		if ($entry === '.' || $entry === '..') {
			continue;
		}

		$path = $dir . '/' . $entry;
		if (is_dir($path) && !is_link($path)) {
			recursiveRmdir($path);
		} else {
			@unlink($path);
		}
	}

	@rmdir($dir);
}
