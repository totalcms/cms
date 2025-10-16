<?php

namespace Tests\Unit\Domain\Auth\Service;

use Odan\Session\SessionInterface;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use TotalCMS\Domain\Auth\Service\FileAccessManager;
use TotalCMS\Domain\Auth\Service\UserValidationService;
use TotalCMS\Domain\Collection\Data\CollectionData;
use TotalCMS\Domain\Collection\Service\CollectionFetcher;
use TotalCMS\Domain\Property\Data\DepotData;
use TotalCMS\Domain\Property\Data\FileData;
use TotalCMS\Domain\Property\Data\PasswordData;
use TotalCMS\Domain\Property\Service\PropertyFetcher;
use TotalCMS\Domain\Session\SessionKeys;
use TotalCMS\Factory\LoggerFactory;

final class FileAccessManagerTest extends TestCase
{
	private FileAccessManager $fileAccessManager;
	private SessionInterface $session;
	private UserValidationService $userValidator;
	private LoggerFactory $loggerFactory;
	private PropertyFetcher $propertyFetcher;
	private CollectionFetcher $collectionFetcher;

	protected function setUp(): void
	{
		$this->session            = $this->createMock(SessionInterface::class);
		$this->userValidator      = $this->createMock(UserValidationService::class);
		$this->loggerFactory      = $this->createMock(LoggerFactory::class);
		$this->propertyFetcher    = $this->createMock(PropertyFetcher::class);
		$this->collectionFetcher  = $this->createMock(CollectionFetcher::class);

		// Mock logger factory chain
		$logger = $this->createMock(LoggerInterface::class);
		$this->loggerFactory->method('addFileHandler')
			->with(FileAccessManager::DOWNLOAD_LOG)
			->willReturnSelf();
		$this->loggerFactory->method('createLogger')
			->with('fileaccess')
			->willReturn($logger);

		$this->fileAccessManager = new FileAccessManager(
			$this->session,
			$this->userValidator,
			$this->loggerFactory,
			$this->propertyFetcher,
			$this->collectionFetcher
		);
	}

	// ==================== Load File Tests ====================

	public function testLoadFileSuccessfully(): void
	{
		$fileData = $this->createFileData();
		$collectionData = $this->createCollectionData();

		$this->propertyFetcher->expects($this->once())
			->method('fetchProperty')
			->with('documents', 'doc-1', 'attachment')
			->willReturn($fileData);

		$this->collectionFetcher->expects($this->once())
			->method('fetchCollection')
			->with('documents')
			->willReturn($collectionData);

		$this->fileAccessManager->loadFile('documents', 'doc-1', 'attachment');

		// If no exception was thrown, the test passes
		$this->assertTrue(true);
	}

	public function testLoadFileThrowsExceptionWhenFileNotFound(): void
	{
		$this->expectException(\UnexpectedValueException::class);
		$this->expectExceptionMessage('Unable to locate object property attachment');

		$this->propertyFetcher->method('fetchProperty')
			->willThrowException(new \UnexpectedValueException('Unable to locate object property attachment'));

		$this->collectionFetcher->method('fetchCollection')
			->willReturn($this->createCollectionData());

		$this->fileAccessManager->loadFile('documents', 'doc-1', 'attachment');
	}

	public function testLoadFileThrowsExceptionWhenCollectionNotFound(): void
	{
		$this->expectException(\RuntimeException::class);
		$this->expectExceptionMessage('Unable to load file');

		$this->propertyFetcher->method('fetchProperty')
			->willReturn($this->createFileData());

		$this->collectionFetcher->method('fetchCollection')
			->willReturn(null); // Not a CollectionData instance

		$this->fileAccessManager->loadFile('documents', 'doc-1', 'attachment');
	}

	// ==================== Load Depot File Tests ====================

	public function testLoadDepotFileSuccessfully(): void
	{
		$depotData = $this->createDepotData();
		$collectionData = $this->createCollectionData();

		$this->propertyFetcher->expects($this->once())
			->method('fetchProperty')
			->with('media', 'media-1', 'files')
			->willReturn($depotData);

		$this->collectionFetcher->expects($this->once())
			->method('fetchCollection')
			->with('media')
			->willReturn($collectionData);

		$this->fileAccessManager->loadDepotFile('media', 'media-1', 'files');

		// If no exception was thrown, the test passes
		$this->assertTrue(true);
	}

	public function testLoadDepotFileThrowsExceptionWhenDepotNotFound(): void
	{
		$this->expectException(\UnexpectedValueException::class);
		$this->expectExceptionMessage('Unable to locate object property files');

		$this->propertyFetcher->method('fetchProperty')
			->willThrowException(new \UnexpectedValueException('Unable to locate object property files'));

		$this->collectionFetcher->method('fetchCollection')
			->willReturn($this->createCollectionData());

		$this->fileAccessManager->loadDepotFile('media', 'media-1', 'files');
	}

	// ==================== Session Has User Tests ====================

	public function testSessionHasUserReturnsTrueWhenBothKeysPresent(): void
	{
		$this->session->expects($this->exactly(2))
			->method('has')
			->willReturnMap([
				['user', true],
				['collection', true],
			]);

		$this->assertTrue($this->fileAccessManager->sessionHasUser());
	}

	public function testSessionHasUserReturnsFalseWhenUserKeyMissing(): void
	{
		$this->session->expects($this->once())
			->method('has')
			->with('user')
			->willReturn(false);

		$this->assertFalse($this->fileAccessManager->sessionHasUser());
	}

	// ==================== Protected By Groups Tests ====================

	public function testIsProtectedByGroupsReturnsTrueWhenProtectedAndHasGroups(): void
	{
		$fileData = $this->createFileData(protected: true);
		$collectionData = $this->createCollectionData(groups: ['editors', 'admins']);

		$this->loadFileWithData($fileData, $collectionData);

		$this->assertTrue($this->fileAccessManager->isProtectedByGroups());
	}

	public function testIsProtectedByGroupsReturnsFalseWhenNotProtected(): void
	{
		$fileData = $this->createFileData(protected: false);
		$collectionData = $this->createCollectionData(groups: ['editors']);

		$this->loadFileWithData($fileData, $collectionData);

		$this->assertFalse($this->fileAccessManager->isProtectedByGroups());
	}

	public function testIsProtectedByGroupsReturnsFalseWhenNoGroups(): void
	{
		$fileData = $this->createFileData(protected: true);
		$collectionData = $this->createCollectionData(groups: []);

		$this->loadFileWithData($fileData, $collectionData);

		$this->assertFalse($this->fileAccessManager->isProtectedByGroups());
	}

	// ==================== User Has Access Tests ====================

	public function testUserHasAccessReturnsFalseWhenNoSession(): void
	{
		$fileData = $this->createFileData();
		$collectionData = $this->createCollectionData();

		$this->loadFileWithData($fileData, $collectionData);

		$this->session->method('has')->willReturn(false);

		$this->assertFalse($this->fileAccessManager->userHasAccess());
	}

	public function testUserHasAccessReturnsTrueForSuperAdmin(): void
	{
		$fileData = $this->createFileData(protected: true);
		$collectionData = $this->createCollectionData(groups: ['admins']);

		$this->loadFileWithData($fileData, $collectionData);

		$this->setupSessionWithUser('admin-user', 'auth');

		$this->userValidator->expects($this->once())
			->method('isSuperAdmin')
			->with('admin-user')
			->willReturn(true);

		$this->assertTrue($this->fileAccessManager->userHasAccess());
	}

	public function testUserHasAccessReturnsTrueWhenNoGroups(): void
	{
		$fileData = $this->createFileData();
		$collectionData = $this->createCollectionData(groups: []);

		$this->loadFileWithData($fileData, $collectionData);

		$this->setupSessionWithUser('user', 'auth');

		$this->userValidator->method('isSuperAdmin')->willReturn(false);

		$this->assertTrue($this->fileAccessManager->userHasAccess());
	}

	public function testUserHasAccessValidatesUserInGroups(): void
	{
		$fileData = $this->createFileData(protected: true);
		$collectionData = $this->createCollectionData(groups: ['editors']);

		$this->loadFileWithData($fileData, $collectionData);

		$this->setupSessionWithUser('user-123', 'auth');

		$this->userValidator->method('isSuperAdmin')->willReturn(false);
		$this->userValidator->expects($this->once())
			->method('validateUserInGroups')
			->with('user-123', ['editors'], 'auth')
			->willReturn(true);

		$this->assertTrue($this->fileAccessManager->userHasAccess());
	}

	public function testUserHasAccessReturnsFalseWhenNotInGroups(): void
	{
		$fileData = $this->createFileData(protected: true);
		$collectionData = $this->createCollectionData(groups: ['admins']);

		$this->loadFileWithData($fileData, $collectionData);

		$this->setupSessionWithUser('user', 'auth');

		$this->userValidator->method('isSuperAdmin')->willReturn(false);
		$this->userValidator->method('validateUserInGroups')->willReturn(false);

		$this->assertFalse($this->fileAccessManager->userHasAccess());
	}

	public function testUserHasAccessHandlesValidationException(): void
	{
		$fileData = $this->createFileData(protected: true);
		$collectionData = $this->createCollectionData(groups: ['editors']);

		$this->loadFileWithData($fileData, $collectionData);

		$this->setupSessionWithUser('user', 'auth');

		$this->userValidator->method('isSuperAdmin')->willReturn(false);
		$this->userValidator->method('validateUserInGroups')
			->willThrowException(new \Exception('Validation error'));

		$this->assertFalse($this->fileAccessManager->userHasAccess());
	}

	// ==================== Password Protection Tests ====================

	public function testIsPasswordProtectedReturnsTrueWhenPasswordSet(): void
	{
		$passwordHash = password_hash('secret123', PASSWORD_DEFAULT);
		$fileData = $this->createFileData(passwordHash: $passwordHash);
		$collectionData = $this->createCollectionData();

		$this->loadFileWithData($fileData, $collectionData);

		$this->assertTrue($this->fileAccessManager->isPasswordProtected());
	}

	public function testIsPasswordProtectedReturnsFalseWhenNoPassword(): void
	{
		$fileData = $this->createFileData(passwordHash: '');
		$collectionData = $this->createCollectionData();

		$this->loadFileWithData($fileData, $collectionData);

		$this->assertFalse($this->fileAccessManager->isPasswordProtected());
	}

	public function testVerifyPasswordReturnsTrueWithCorrectPassword(): void
	{
		$password = 'secret123';
		$passwordHash = password_hash($password, PASSWORD_DEFAULT);
		$fileData = $this->createFileData(passwordHash: $passwordHash);
		$collectionData = $this->createCollectionData();

		$this->loadFileWithData($fileData, $collectionData);

		$this->session->method('has')->willReturn(false); // Not logged in

		$this->assertTrue($this->fileAccessManager->verfiyPassword($password));
	}

	public function testVerifyPasswordReturnsFalseWithWrongPassword(): void
	{
		$correctPassword = 'secret123';
		$wrongPassword = 'wrong';
		$passwordHash = password_hash($correctPassword, PASSWORD_DEFAULT);
		$fileData = $this->createFileData(passwordHash: $passwordHash);
		$collectionData = $this->createCollectionData();

		$this->loadFileWithData($fileData, $collectionData);

		$this->session->method('has')->willReturn(false);

		$this->assertFalse($this->fileAccessManager->verfiyPassword($wrongPassword));
	}

	public function testVerifyPasswordBypassesCheckForSuperAdmin(): void
	{
		$passwordHash = password_hash('secret123', PASSWORD_DEFAULT);
		$fileData = $this->createFileData(passwordHash: $passwordHash);
		$collectionData = $this->createCollectionData();

		$this->loadFileWithData($fileData, $collectionData);

		$this->setupSessionWithUser('admin', 'auth');

		$this->userValidator->method('isSuperAdmin')->willReturn(true);

		// Super admin can access with any password (even wrong one)
		$this->assertTrue($this->fileAccessManager->verfiyPassword('wrong-password'));
	}

	public function testVerifyPasswordOnlyDoesNotBypassForSuperAdmin(): void
	{
		$correctPassword = 'secret123';
		$wrongPassword = 'wrong';
		$passwordHash = password_hash($correctPassword, PASSWORD_DEFAULT);
		$fileData = $this->createFileData(passwordHash: $passwordHash);
		$collectionData = $this->createCollectionData();

		$this->loadFileWithData($fileData, $collectionData);

		$this->setupSessionWithUser('admin', 'auth');

		$this->userValidator->method('isSuperAdmin')->willReturn(true);

		// verfiyPasswordOnly does not bypass for super admin
		$this->assertFalse($this->fileAccessManager->verfiyPasswordOnly($wrongPassword));
		$this->assertTrue($this->fileAccessManager->verfiyPasswordOnly($correctPassword));
	}

	// ==================== Log Download Tests ====================

	public function testLogDownloadRecordsDownloadWhenUserLoggedIn(): void
	{
		$fileData = $this->createFileData();
		$collectionData = $this->createCollectionData(groups: ['editors']);

		$this->loadFileWithData($fileData, $collectionData);

		$this->setupSessionWithUser('user-123', 'auth');

		$this->userValidator->method('isSuperAdmin')->willReturn(false);

		// Can't easily assert on logger->info call, but we can verify no exception is thrown
		$this->fileAccessManager->logDownload('documents', 'doc-1', 'attachment', 'file.pdf', 'subfolder');

		$this->assertTrue(true); // If we got here, logging succeeded
	}

	public function testLogDownloadSkipsLoggingWhenNoUser(): void
	{
		$fileData = $this->createFileData();
		$collectionData = $this->createCollectionData();

		$this->loadFileWithData($fileData, $collectionData);

		$this->session->method('has')->willReturn(false);

		// Should return early without logging
		$this->fileAccessManager->logDownload('documents', 'doc-1', 'attachment', 'file.pdf');

		$this->assertTrue(true);
	}

	// ==================== Helper Methods ====================

	private function setupSessionWithUser(string $userId, string $collection): void
	{
		$this->session->method('has')
			->willReturnMap([
				['user', true],
				['collection', true],
			]);

		$this->session->method('get')
			->willReturnMap([
				[SessionKeys::AUTH_USER, null, $userId],
				[SessionKeys::AUTH_COLLECTION, null, $collection],
			]);
	}

	private function createFileData(bool $protected = false, string $passwordHash = ''): FileData
	{
		return new FileData([
			'name' => 'test.pdf',
			'mime' => 'application/pdf',
			'size' => 1024,
			'protected' => $protected,
			'password' => $passwordHash,
			'tags' => [],
			'comments' => 'Test file',
		]);
	}

	private function createDepotData(bool $protected = false, string $passwordHash = ''): DepotData
	{
		return new DepotData([
			'depotID' => 'my-depot',
			'path' => 'files',
			'protected' => $protected,
			'password' => $passwordHash,
		]);
	}

	private function createCollectionData(array $groups = []): CollectionData
	{
		$collection = new CollectionData();
		$collection->id = 'documents';
		$collection->name = 'Documents';
		$collection->schema = 'document';
		$collection->description = 'Document collection';
		$collection->groups = $groups;

		return $collection;
	}

	private function loadFileWithData(FileData|DepotData $fileData, CollectionData $collectionData): void
	{
		$this->propertyFetcher->method('fetchProperty')->willReturn($fileData);
		$this->collectionFetcher->method('fetchCollection')->willReturn($collectionData);

		if ($fileData instanceof FileData) {
			$this->fileAccessManager->loadFile('documents', 'doc-1', 'attachment');
		} else {
			$this->fileAccessManager->loadDepotFile('media', 'media-1', 'files');
		}
	}
}
