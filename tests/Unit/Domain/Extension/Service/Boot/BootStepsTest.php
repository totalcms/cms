<?php

declare(strict_types=1);

namespace Tests\Unit\Domain\Extension\Service\Boot;

use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\NullLogger;
use ReflectionProperty;
use TotalCMS\Domain\Admin\TotalForm;
use TotalCMS\Domain\Automation\Service\AutomationRegistry;
use TotalCMS\Domain\Builder\Service\PageMiddlewareRegistry;
use TotalCMS\Domain\Event\Service\EventDispatcher;
use TotalCMS\Domain\Extension\Data\AdminNavItem;
use TotalCMS\Domain\Extension\Data\AutomationDefinition;
use TotalCMS\Domain\Extension\Data\FormAction;
use TotalCMS\Domain\Extension\Service\Boot\AssetsStep;
use TotalCMS\Domain\Extension\Service\Boot\AutomationsStep;
use TotalCMS\Domain\Extension\Service\Boot\EventListenersStep;
use TotalCMS\Domain\Extension\Service\Boot\FieldTypesStep;
use TotalCMS\Domain\Extension\Service\Boot\FormActionsStep;
use TotalCMS\Domain\Extension\Service\Boot\McpStep;
use TotalCMS\Domain\Extension\Service\Boot\PageMiddlewareStep;
use TotalCMS\Domain\Extension\Service\Boot\SchemaDirectoriesStep;
use TotalCMS\Domain\Extension\Service\Boot\SearchProvidersStep;
use TotalCMS\Domain\Extension\Service\Boot\TwigStep;
use TotalCMS\Domain\Extension\Service\ExtensionManager;
use TotalCMS\Domain\Extension\Service\FormActionRegistry;
use TotalCMS\Domain\License\Data\Edition;
use TotalCMS\Domain\License\Service\EditionFeatureService;
use TotalCMS\Domain\Mcp\Resource\Service\ResourceRegistry;
use TotalCMS\Domain\Mcp\Tool\Service\ToolRegistry;
use TotalCMS\Domain\Schema\Repository\SchemaRepository;
use TotalCMS\Domain\Search\Service\SearchProvider;
use TotalCMS\Domain\Search\Service\SearchProviderRegistry;
use TotalCMS\Domain\Twig\Adapter\TotalCMSTwigAdapter;
use TotalCMS\Domain\Twig\Data\FrontendAsset;
use TotalCMS\Domain\Twig\Extension\TotalCMSTwigExtension;
use TotalCMS\Domain\Twig\Service\TwigEngine;

/**
 * Each boot step reads one accessor on the manager and writes to one
 * registry, guarded by container->has(). The manager and registries are
 * mocked; the integration net is tests/Integration/ExtensionBootWiringTest.
 */
final class BootStepsTest extends TestCase
{
	private MockObject $manager;

	/** @var array<string,class-string> */
	private array $savedExtensionFieldTypes;

	/** @var array<string,string> */
	private array $savedExtensionFieldDefaultTypes;

	protected function setUp(): void
	{
		// TotalForm::$extensionFieldTypes / $extensionFieldDefaultTypes are
		// process-global statics with no reset API (registerExtensionFieldTypes()
		// only merges). testFieldTypesRegisterBothMapsOnlyWhenTypesExist()
		// registers 'wiring' into them; under the parallel suite (sharded by
		// file) a leaked entry would survive into whatever other test file
		// lands in the same worker next, so snapshot and restore.
		$this->savedExtensionFieldTypes        = (new ReflectionProperty(TotalForm::class, 'extensionFieldTypes'))->getValue();
		$this->savedExtensionFieldDefaultTypes = (new ReflectionProperty(TotalForm::class, 'extensionFieldDefaultTypes'))->getValue();

		$this->manager = $this->createMock(ExtensionManager::class);
	}

	protected function tearDown(): void
	{
		(new ReflectionProperty(TotalForm::class, 'extensionFieldTypes'))->setValue(null, $this->savedExtensionFieldTypes);
		(new ReflectionProperty(TotalForm::class, 'extensionFieldDefaultTypes'))->setValue(null, $this->savedExtensionFieldDefaultTypes);
	}

	/** @param array<class-string,object> $services */
	private function container(array $services): ContainerInterface
	{
		$container = $this->createMock(ContainerInterface::class);
		$container->method('has')->willReturnCallback(fn (string $id): bool => isset($services[$id]));
		$container->method('get')->willReturnCallback(fn (string $id): object => $services[$id]);

		return $container;
	}

	public function testSchemaDirectoriesRegisterEachPermittedDirOnProOrHigher(): void
	{
		$editions = $this->createMock(EditionFeatureService::class);
		$editions->method('getEdition')->willReturn(Edition::PRO);
		$repo = $this->createMock(SchemaRepository::class);
		$repo->expects($this->exactly(2))->method('registerExtensionSchemaDir');
		$this->manager->method('getAllSchemaDirs')->willReturn(['a/x' => '/a/schemas', 'b/y' => '/b/schemas']);

		(new SchemaDirectoriesStep($this->container([EditionFeatureService::class => $editions, SchemaRepository::class => $repo]), new NullLogger()))
			->wire($this->manager);
	}

	public function testSchemaDirectoriesSkipBelowPro(): void
	{
		$editions = $this->createMock(EditionFeatureService::class);
		$editions->method('getEdition')->willReturn(Edition::LITE);
		$repo = $this->createMock(SchemaRepository::class);
		$repo->expects($this->never())->method('registerExtensionSchemaDir');
		$this->manager->expects($this->never())->method('getAllSchemaDirs');

		(new SchemaDirectoriesStep($this->container([EditionFeatureService::class => $editions, SchemaRepository::class => $repo]), new NullLogger()))
			->wire($this->manager);
	}

	public function testSchemaDirectoriesDoNothingWithoutASchemaRepository(): void
	{
		$this->manager->expects($this->never())->method('getAllSchemaDirs');

		(new SchemaDirectoriesStep($this->container([]), new NullLogger()))->wire($this->manager);
	}

	public function testEventListenersAreRegisteredAllAtOnce(): void
	{
		// EventDispatcher is final — PHPUnit cannot double it, so use a real
		// instance and assert on observable state via hasListeners().
		$listeners  = ['ev' => [[static fn () => null, 0]]];
		$dispatcher = new EventDispatcher(new NullLogger());
		$this->manager->method('getAllEventListeners')->willReturn($listeners);

		(new EventListenersStep($this->container([EventDispatcher::class => $dispatcher]), new NullLogger()))->wire($this->manager);

		self::assertTrue($dispatcher->hasListeners('ev'));
	}

	public function testEventListenersSkipTheDispatcherWhenNothingIsRegistered(): void
	{
		// registerAll([]) is a no-op, so asserting on dispatcher state can't
		// discriminate whether the `$eventListeners !== []` guard actually
		// ran. Assert the guard's real effect instead: the container's
		// get() must never be called to resolve the dispatcher at all.
		$container = $this->createMock(ContainerInterface::class);
		$container->method('has')->with(EventDispatcher::class)->willReturn(true);
		$container->expects($this->never())->method('get');
		$this->manager->method('getAllEventListeners')->willReturn([]);

		(new EventListenersStep($container, new NullLogger()))->wire($this->manager);
	}

	public function testAutomationsAreRegisteredByKey(): void
	{
		// AutomationRegistry and AutomationDefinition are both final (the
		// latter readonly too) — PHPUnit cannot double either, so use real
		// instances and assert on observable state.
		$definition = new AutomationDefinition('auto', 'Auto', [], static fn () => null);
		$registry   = new AutomationRegistry();
		$this->manager->method('getAllAutomations')->willReturn(['a/x:auto' => $definition]);

		(new AutomationsStep($this->container([AutomationRegistry::class => $registry]), new NullLogger()))->wire($this->manager);

		self::assertSame($definition, $registry->get('a/x:auto'));
	}

	public function testFieldTypesRegisterBothMapsOnlyWhenTypesExist(): void
	{
		$this->manager->method('getAllFieldTypes')->willReturn(['wiring' => \stdClass::class]);
		$this->manager->method('getAllFieldDefaultTypes')->willReturn(['wiring' => 'string']);

		(new FieldTypesStep($this->container([]), new NullLogger()))->wire($this->manager);

		self::assertSame(\stdClass::class, TotalForm::getExtensionFieldTypes()['wiring'] ?? null);
	}

	public function testPageMiddlewareRegistersEachNameAndSurvivesABadOne(): void
	{
		$registry = $this->createMock(PageMiddlewareRegistry::class);
		$registry->expects($this->exactly(2))->method('register')
			->willReturnCallback(function (string $name): void {
				if ($name === 'bad') {
					throw new \InvalidArgumentException('bad name');
				}
			});
		$this->manager->method('getAllPageMiddleware')->willReturn(['a/x' => ['bad' => 'V\\Bad', 'good' => 'V\\Good']]);

		(new PageMiddlewareStep($this->container([PageMiddlewareRegistry::class => $registry]), new NullLogger()))->wire($this->manager);
	}

	public function testFormActionsAreRegisteredOneByOne(): void
	{
		$fa       = new FormAction('fa', '/fa', 'FA');
		$registry = $this->createMock(FormActionRegistry::class);
		$registry->expects($this->once())->method('register')->with($fa);
		$this->manager->method('getAllFormActions')->willReturn(['a/x' => [$fa]]);

		(new FormActionsStep($this->container([FormActionRegistry::class => $registry]), new NullLogger()))->wire($this->manager);
	}

	public function testMcpRegistersToolsAndOnlyResolvesTheResourceRegistryWhenNeeded(): void
	{
		$tools = $this->createMock(ToolRegistry::class);
		$this->manager->method('getAllMcpTools')->willReturn([]);
		$this->manager->method('getAllMcpResources')->willReturn([]);
		$this->manager->method('getAllMcpResourceTemplates')->willReturn([]);
		$container = $this->createMock(ContainerInterface::class);
		$container->method('has')->willReturnCallback(fn (string $id): bool => $id === ToolRegistry::class || $id === ResourceRegistry::class);
		$container->expects($this->once())->method('get')->with(ToolRegistry::class)->willReturn($tools);

		(new McpStep($container, new NullLogger()))->wire($this->manager);
	}

	public function testSearchProvidersRegisterEachAndSkipCollisions(): void
	{
		// SearchProviderRegistry is final — PHPUnit cannot double it, so use a
		// real instance and assert on observable state via get(). Two
		// providers share the id 'dupe' to trigger the registry's own
		// LogicException on the second register() call.
		$p1 = $this->createStub(SearchProvider::class);
		$p1->method('id')->willReturn('dupe');
		$p2 = $this->createStub(SearchProvider::class);
		$p2->method('id')->willReturn('dupe');
		$registry = new SearchProviderRegistry();
		$this->manager->method('getAllMcpSearchProviders')->willReturn(['a/x' => [$p1], 'b/y' => [$p2]]);

		(new SearchProvidersStep($this->container([SearchProviderRegistry::class => $registry]), new NullLogger()))->wire($this->manager);

		self::assertSame($p1, $registry->get('dupe'));
		self::assertCount(1, $registry->all());
	}

	public function testTwigStepRegistersItemsNamespacesAndGlobals(): void
	{
		$engine    = $this->createMock(TwigEngine::class);
		$extension = $this->createStub(TotalCMSTwigExtension::class);
		$engine->expects($this->once())->method('addExtensionTemplatePath')->with('/x/templates', 'a-x');
		$engine->expects($this->once())->method('registerExtensionItems')
			->with([], [], $this->callback(fn (array $g): bool => isset($g['extensionNavItems']) && !isset($g['extensionDashWidgets'])));
		$this->manager->method('getAllTwigFunctions')->willReturn([]);
		$this->manager->method('getAllTwigFilters')->willReturn([]);
		$this->manager->method('getAllTwigGlobals')->willReturn([]);
		$this->manager->method('getAllTemplatePaths')->willReturn(['a-x' => '/x/templates']);
		// AdminNavItem is final readonly, so PHPUnit cannot double it — build a real one.
		$this->manager->method('getAllAdminNavItems')->willReturn([new AdminNavItem(label: 'Ext')]);
		$this->manager->method('getAllDashboardWidgets')->willReturn([]);

		(new TwigStep($this->container([TwigEngine::class => $engine, TotalCMSTwigExtension::class => $extension]), new NullLogger()))->wire($this->manager);
	}

	public function testTwigStepDoesNothingWithoutAnEngine(): void
	{
		$this->manager->expects($this->never())->method('getAllTwigFunctions');

		(new TwigStep($this->container([]), new NullLogger()))->wire($this->manager);
	}

	public function testAssetsStepRequiresBothTheEngineAndTheAdapter(): void
	{
		$adapter = $this->createMock(TotalCMSTwigAdapter::class);
		$adapter->expects($this->never())->method('addAdminAssets');
		$this->manager->method('getAllAdminAssets')->willReturn([new FrontendAsset('css', 'a.css', 'head')]);

		(new AssetsStep($this->container([TotalCMSTwigAdapter::class => $adapter]), new NullLogger()))->wire($this->manager);
	}

	public function testAssetsStepRegistersCoreThenExtensionAssets(): void
	{
		// CoreAdminAssetRegistrar/CoreFrontendAssetRegistrar run unconditionally
		// inside AssetsStep before the extension-asset guard, so addAdminAssets
		// is called once for core assets and once more for extension assets.
		// Capture every call and assert the LAST one carries the extension list.
		$engine     = $this->createStub(TwigEngine::class);
		$adapter    = $this->createMock(TotalCMSTwigAdapter::class);
		$adminCalls = [];
		$adapter->expects($this->atLeastOnce())->method('addAdminAssets')
			->willReturnCallback(function (array $assets) use (&$adminCalls): void {
				$adminCalls[] = $assets;
			});
		// getAllFrontendAssets() is empty, so AssetsStep itself never calls
		// addFrontendAssets — the single call observed here is CoreFrontendAssetRegistrar.
		$adapter->expects($this->once())->method('addFrontendAssets');
		$extensionAsset = new FrontendAsset('css', 'a.css', 'head');
		$this->manager->method('getAllAdminAssets')->willReturn([$extensionAsset]);
		$this->manager->method('getAllFrontendAssets')->willReturn([]);

		(new AssetsStep($this->container([TwigEngine::class => $engine, TotalCMSTwigAdapter::class => $adapter]), new NullLogger()))->wire($this->manager);

		self::assertSame([$extensionAsset], end($adminCalls));
	}
}
