<?php

declare(strict_types=1);

namespace Tests\Unit\Domain\Setup;

use Odan\Session\MemorySession;
use PHPUnit\Framework\TestCase;
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
		$this->manager = new SetupStateManager($this->session, $this->config);
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
		$manager = new SetupStateManager($this->session, $config);

		expect($manager->isStepComplete('data-path'))->toBeTrue();

		rmdir($tmpDir);
	}

	public function testAccountStepInferredFromAuthCollection(): void
	{
		$tmpDir = sys_get_temp_dir() . '/tcms-setup-test-' . uniqid();
		mkdir($tmpDir . '/auth', 0755, true);

		$config  = createTestConfig(['datadir' => $tmpDir, 'auth' => ['collection' => 'auth']]);
		$manager = new SetupStateManager($this->session, $config);

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
}
