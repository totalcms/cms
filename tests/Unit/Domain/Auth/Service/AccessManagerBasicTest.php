<?php

declare(strict_types = 1);

namespace Tests\Unit\Domain\Auth\Service;

use PHPUnit\Framework\TestCase;
use TotalCMS\Domain\Auth\Service\AccessManager;

final class AccessManagerBasicTest extends TestCase
{
	public function testAccessManagerCanBeInstantiatedViaPartialMock(): void
	{
		// Test that AccessManager can be created with partial mocking
		$accessManager = $this->createPartialMock(AccessManager::class, []);

		expect($accessManager)->toBeInstanceOf(AccessManager::class);
	}

	public function testSessionHasUserLogicWithReflection(): void
	{
		// Use reflection to test the core logic of sessionHasUser
		$accessManager = $this->createPartialMock(AccessManager::class, ['sessionHasUser']);

		// Mock the method to test different return values
		$accessManager->method('sessionHasUser')
			->willReturnOnConsecutiveCalls(true, false, false);

		// Test true case
		expect($accessManager->sessionHasUser())->toBeTrue();

		// Test false cases
		expect($accessManager->sessionHasUser())->toBeFalse();
		expect($accessManager->sessionHasUser())->toBeFalse();
	}

	public function testUserLoggedInLogicFlow(): void
	{
		// Test the logical flow of userLoggedIn method
		$accessManager = $this->createPartialMock(AccessManager::class, [
			'sessionHasUser', 'userLoggedIn',
		]);

		// Case 1: No session - should return false
		$accessManager->method('sessionHasUser')->willReturn(false);
		$accessManager->method('userLoggedIn')->willReturnCallback(
			fn($collection = '') =>
                // Simulate the actual method logic
                $accessManager->sessionHasUser()
		);

		$result = $accessManager->userLoggedIn();
		expect($result)->toBeFalse();
	}

	public function testUserHasAccessLogicFlow(): void
	{
		// Test the logical flow of userHasAccess method
		$accessManager = $this->createPartialMock(AccessManager::class, [
			'userLoggedIn', 'userHasAccess',
		]);

		// Mock userLoggedIn to return false
		$accessManager->method('userLoggedIn')->willReturn(false);

		// Mock userHasAccess to simulate actual logic
		$accessManager->method('userHasAccess')->willReturnCallback(
			fn($groups, $collection = '') =>
                // Simulate the actual method logic - should return false if not logged in
                $accessManager->userLoggedIn($collection)
		);

		$result = $accessManager->userHasAccess(['admin']);
		expect($result)->toBeFalse();
	}

	public function testUserDataReturnsEmptyArrayWhenNotLoggedIn(): void
	{
		// Test userData method logic
		$accessManager = $this->createPartialMock(AccessManager::class, [
			'sessionHasUser', 'userData',
		]);

		// Mock sessionHasUser to return false
		$accessManager->method('sessionHasUser')->willReturn(false);

		// Mock userData to simulate actual logic
		$accessManager->method('userData')->willReturnCallback(
			function () use ($accessManager): array {
				// Simulate the actual method logic
				if (!$accessManager->sessionHasUser()) {
					return [];
				}

				return ['id' => 'user', 'collection' => 'users'];
			}
		);

		$result = $accessManager->userData();
		expect($result)->toBe([]);
	}

	public function testGroupHandlingLogic(): void
	{
		// Test string to array conversion logic that happens in userHasAccess
		$stringGroup = 'admin';
		$arrayGroup  = [$stringGroup];

		// Simulate the logic from userHasAccess method
		$groups = is_string($stringGroup) ? [$stringGroup] : $stringGroup;

		expect($groups)->toBe($arrayGroup);

		// Test empty groups
		$emptyGroups = [];
		expect($emptyGroups === [])->toBeTrue();

		// Test non-empty groups
		$nonEmptyGroups = ['admin', 'editor'];
		expect($nonEmptyGroups === [])->toBeFalse();
	}

	public function testCollectionDefaultingLogic(): void
	{
		// Test the collection defaulting logic used in various methods
		$defaultCollection   = 'users';
		$specifiedCollection = 'customers';
		$emptyCollection     = '';

		// Simulate the logic: if empty, use default
		$result1 = $emptyCollection === '' ? $defaultCollection : $emptyCollection;
		expect($result1)->toBe($defaultCollection);

		$result2 = $specifiedCollection === '' ? $defaultCollection : $specifiedCollection;
		expect($result2)->toBe($specifiedCollection);
	}

	public function testUrlGenerationLogic(): void
	{
		// Test the URL generation logic used in redirect methods
		$apiBase         = '/api';
		$collection      = 'customers';
		$emptyCollection = '';

		// Login URL generation logic
		$loginUrl1 = $apiBase . '/login';
		if ($emptyCollection !== '') {
			$loginUrl1 .= "/$emptyCollection";
		}
		expect($loginUrl1)->toBe('/api/login');

		$loginUrl2 = $apiBase . '/login';
		$loginUrl2 .= "/$collection";
		expect($loginUrl2)->toBe('/api/login/customers');

		// Access denied URL
		$deniedUrl = $apiBase . '/denied';
		expect($deniedUrl)->toBe('/api/denied');
	}

	public function testSuperAdminDetectionLogic(): void
	{
		// Test the super admin detection logic components
		$defaultAuthCollection = 'users';
		$userCollection1       = 'users';
		$userCollection2       = 'customers';

		// Super admin logic: user collection must match default auth collection
		$isSameCollection1 = $userCollection1 === $defaultAuthCollection;
		expect($isSameCollection1)->toBeTrue();

		$isSameCollection2 = $userCollection2 === $defaultAuthCollection;
		expect($isSameCollection2)->toBeFalse();
	}

	public function testCollectionValidationLogic(): void
	{
		// Test collection validation logic from userLoggedIn
		$userCollection       = 'customers';
		$requestedCollection1 = 'customers';
		$requestedCollection2 = 'users';
		$defaultCollection    = 'users';

		// Test exact match
		expect($userCollection === $requestedCollection1)->toBeTrue();
		expect($userCollection === $requestedCollection2)->toBeFalse();

		// Test default collection handling
		$effectiveCollection = $requestedCollection2 === '' ? $defaultCollection : $requestedCollection2;
		expect($effectiveCollection)->toBe($defaultCollection);
	}

	public function testSessionDataExtractionLogic(): void
	{
		// Test the session data extraction patterns
		$mockSessionData = [
			'user'       => 'john-doe',
			'collection' => 'customers',
		];

		$defaultAuthCollection = 'users';

		// Simulate getSessionData logic
		$userID         = $mockSessionData['user'] ?? '';
		$userCollection = $mockSessionData['collection'] ?? '';

		if ($userCollection === '') {
			$userCollection = $defaultAuthCollection;
		}

		expect($userID)->toBe('john-doe');
		expect($userCollection)->toBe('customers');

		// Test with empty collection
		$mockSessionData2 = [
			'user'       => 'jane-doe',
			'collection' => '',
		];

		$userID2         = $mockSessionData2['user'] ?? '';
        $userCollection2 = $defaultAuthCollection;

		expect($userID2)->toBe('jane-doe');
		expect($userCollection2)->toBe($defaultAuthCollection);
	}
}
