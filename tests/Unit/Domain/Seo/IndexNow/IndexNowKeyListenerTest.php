<?php

declare(strict_types=1);

namespace Tests\Unit\Domain\Seo\IndexNow;

use Monolog\Level;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use TotalCMS\Domain\Event\Payload\ObjectEventPayload;
use TotalCMS\Domain\Event\Service\EventDispatcher;
use TotalCMS\Domain\Object\Data\ObjectData;
use TotalCMS\Domain\Object\Service\ObjectUpdater;
use TotalCMS\Domain\Property\Data\BooleanData;
use TotalCMS\Domain\Property\Data\StringData;
use TotalCMS\Domain\Seo\IndexNow\IndexNowKeyListener;
use TotalCMS\Factory\LoggerFactory;

/**
 * Driven through a real EventDispatcher, as IndexNowListenerTest is, so the
 * payload arrives with `object` and `previous` as live ObjectData.
 */
final class IndexNowKeyListenerTest extends TestCase
{
	private const SHARED = '36rgpfrs4itk3z6qes1ejtbco78tsu6a';

	private EventDispatcher $dispatcher;
	private MockObject $updater;

	protected function setUp(): void
	{
		$this->updater    = $this->createMock(ObjectUpdater::class);
		$this->dispatcher = new EventDispatcher(new NullLogger());

		$listener = new IndexNowKeyListener($this->updater, new LoggerFactory(['level' => Level::Debug, 'test' => new NullLogger()]));
		$this->dispatcher->listen('object.created', $listener->onObjectSaved(...));
		$this->dispatcher->listen('object.updated', $listener->onObjectSaved(...));
		$this->dispatcher->listen('import.created', $listener->onImportSaved(...));
		$this->dispatcher->listen('import.updated', $listener->onImportSaved(...));
	}

	private static function record(bool $enabled, string $key, string $collection = 'seo-site'): ObjectData
	{
		return new ObjectData($collection, [
			'siteName'        => new StringData('Juniper Table'),
			'indexNowEnabled' => new BooleanData($enabled),
			'indexNowKey'     => new StringData($key),
		]);
	}

	/** The data the listener writes back, captured from its one update. */
	private function expectOneWrite(): \ArrayObject
	{
		$written = new \ArrayObject();
		$this->updater->expects($this->once())->method('updateObject')
			->with('seo-site', 'seo-site', $this->callback(function (array $data) use ($written): bool {
				$written->exchangeArray($data);

				return true;
			}))
			->willReturnCallback(fn (): ObjectData => self::record(true, (string)$written['indexNowKey']));

		return $written;
	}

	public function testTurningIndexNowOnGivesTheSiteANewKey(): void
	{
		$written = $this->expectOneWrite();

		$this->dispatcher->dispatch('object.updated', new ObjectEventPayload('seo-site', 'seo-site', self::record(true, self::SHARED), self::record(false, self::SHARED)));

		self::assertMatchesRegularExpression('/^[a-z0-9]{32}$/', $written['indexNowKey']);
		self::assertNotSame(self::SHARED, $written['indexNowKey']);
		self::assertSame('Juniper Table', $written['siteName']);
		self::assertTrue($written['indexNowEnabled']);
	}

	public function testANewRecordThatArrivesWithIndexNowOnGetsANewKey(): void
	{
		$written = $this->expectOneWrite();

		$this->dispatcher->dispatch('import.created', new ObjectEventPayload('seo-site', 'seo-site', self::record(true, self::SHARED)));

		self::assertNotSame(self::SHARED, $written['indexNowKey']);
	}

	public function testAKeyTheFormMadeWhenTheToggleWentOnIsKept(): void
	{
		$this->updater->expects($this->never())->method('updateObject');

		$this->dispatcher->dispatch('object.updated', new ObjectEventPayload('seo-site', 'seo-site', self::record(true, 'formmadekey0123456789abcdefghijk'), self::record(false, self::SHARED)));
	}

	public function testAnImportThatTurnsIndexNowOnGetsANewKeyEvenWithADifferentOne(): void
	{
		$written = $this->expectOneWrite();

		$this->dispatcher->dispatch('import.updated', new ObjectEventPayload('seo-site', 'seo-site', self::record(true, 'othersitekey0123456789abcdefghij'), self::record(false, self::SHARED)));

		self::assertNotSame('othersitekey0123456789abcdefghij', $written['indexNowKey']);
		self::assertNotSame(self::SHARED, $written['indexNowKey']);
	}

	public function testAStaleKeyFromAnOpenFormIsReplacedWithTheStoredOne(): void
	{
		$written = $this->expectOneWrite();

		$this->dispatcher->dispatch('object.updated', new ObjectEventPayload('seo-site', 'seo-site', self::record(true, self::SHARED), self::record(true, 'storedkey0123456789abcdefghijklmn')));

		self::assertSame('storedkey0123456789abcdefghijklmn', $written['indexNowKey']);
	}

	public function testASaveThatKeepsTheKeyWritesNothing(): void
	{
		$this->updater->expects($this->never())->method('updateObject');

		$this->dispatcher->dispatch('object.updated', new ObjectEventPayload('seo-site', 'seo-site', self::record(true, self::SHARED), self::record(true, self::SHARED)));
		$this->dispatcher->dispatch('object.updated', new ObjectEventPayload('seo-site', 'seo-site', self::record(false, self::SHARED), self::record(true, self::SHARED)));
		$this->dispatcher->dispatch('object.created', new ObjectEventPayload('seo-site', 'seo-site', self::record(false, self::SHARED)));
	}

	public function testOtherCollectionsAreIgnored(): void
	{
		$this->updater->expects($this->never())->method('updateObject');

		$this->dispatcher->dispatch('object.updated', new ObjectEventPayload('blog', 'seo-site', self::record(true, self::SHARED, 'blog'), self::record(false, self::SHARED, 'blog')));
	}

	public function testTheListenersOwnWriteDoesNotTriggerAnother(): void
	{
		// The real updater dispatches object.updated for the second write;
		// that event carries a "previous" whose key differs, and must be ignored.
		$this->updater->expects($this->once())->method('updateObject')
			->willReturnCallback(function (string $collection, string $id, array $data): ObjectData {
				$saved = self::record(true, (string)$data['indexNowKey']);
				$this->dispatcher->dispatch('object.updated', new ObjectEventPayload($collection, $id, $saved, self::record(true, self::SHARED)));

				return $saved;
			});

		$this->dispatcher->dispatch('object.updated', new ObjectEventPayload('seo-site', 'seo-site', self::record(true, self::SHARED), self::record(false, self::SHARED)));
	}

	public function testTheDecisionOnItsOwn(): void
	{
		self::assertNotSame('old', IndexNowKeyListener::keyFor(['indexNowEnabled' => true, 'indexNowKey' => 'old'], ['indexNowEnabled' => false, 'indexNowKey' => 'old']));
		self::assertSame('stored', IndexNowKeyListener::keyFor(['indexNowEnabled' => true, 'indexNowKey' => 'posted'], ['indexNowEnabled' => true, 'indexNowKey' => 'stored']));
		self::assertSame('stored', IndexNowKeyListener::keyFor(['indexNowEnabled' => false, 'indexNowKey' => 'posted'], ['indexNowEnabled' => true, 'indexNowKey' => 'stored']));
		self::assertSame('posted', IndexNowKeyListener::keyFor(['indexNowEnabled' => true, 'indexNowKey' => 'posted'], ['indexNowEnabled' => true, 'indexNowKey' => '']));
		self::assertSame('fresh', IndexNowKeyListener::keyFor(['indexNowEnabled' => false, 'indexNowKey' => 'fresh'], null));
		self::assertSame('formmadekey', IndexNowKeyListener::keyFor(['indexNowEnabled' => true, 'indexNowKey' => 'formmadekey'], ['indexNowEnabled' => false, 'indexNowKey' => 'old'], true));
		self::assertNotSame('formmadekey', IndexNowKeyListener::keyFor(['indexNowEnabled' => true, 'indexNowKey' => 'formmadekey'], ['indexNowEnabled' => false, 'indexNowKey' => 'old']));
		self::assertNotSame('copiedkey00', IndexNowKeyListener::keyFor(['indexNowEnabled' => true, 'indexNowKey' => 'copiedkey00'], null, true));
		self::assertNotSame('Not A Key!', IndexNowKeyListener::keyFor(['indexNowEnabled' => true, 'indexNowKey' => 'Not A Key!'], ['indexNowEnabled' => false, 'indexNowKey' => 'old'], true));
	}
}
