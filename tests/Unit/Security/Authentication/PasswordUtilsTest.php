<?php

use TotalCMS\Domain\Security\Authentication\PasswordUtils;
use TotalCMS\Domain\Property\Data\PasswordData;

describe('PasswordUtils', function (): void {
	test('PasswordUtils → verifies correct password', function (): void {
		$plainPassword = 'correct-password-123';
		$hashedPassword = password_hash($plainPassword, PASSWORD_DEFAULT);
		$passwordData = new PasswordData($hashedPassword);
		
		$isValid = PasswordUtils::verify($plainPassword, $passwordData);
		
		expect($isValid)->toBe(true);
	});

	test('PasswordUtils → rejects incorrect password', function (): void {
		$correctPassword = 'correct-password';
		$wrongPassword = 'wrong-password';
		$hashedPassword = password_hash($correctPassword, PASSWORD_DEFAULT);
		$passwordData = new PasswordData($hashedPassword);
		
		$isValid = PasswordUtils::verify($wrongPassword, $passwordData);
		
		expect($isValid)->toBe(false);
	});

	test('PasswordUtils → handles empty password verification', function (): void {
		$emptyPassword = '';
		$hashedPassword = password_hash('some-password', PASSWORD_DEFAULT);
		$passwordData = new PasswordData($hashedPassword);
		
		$isValid = PasswordUtils::verify($emptyPassword, $passwordData);
		
		expect($isValid)->toBe(false);
	});

	test('PasswordUtils → handles empty hash in PasswordData', function (): void {
		$password = 'test-password';
		$passwordData = new PasswordData('');
		
		$isValid = PasswordUtils::verify($password, $passwordData);
		
		expect($isValid)->toBe(false);
	});

	test('PasswordUtils → handles invalid hash format', function (): void {
		$password = 'test-password';
		$invalidHash = 'not-a-valid-hash';
		$passwordData = new PasswordData($invalidHash);
		
		$isValid = PasswordUtils::verify($password, $passwordData);
		
		expect($isValid)->toBe(false);
	});

	test('PasswordUtils → verifies complex password with special characters', function (): void {
		$complexPassword = 'P@ssw0rd!@#$%^&*()_+-=[]{}|;:,.<>?';
		$hashedPassword = password_hash($complexPassword, PASSWORD_DEFAULT);
		$passwordData = new PasswordData($hashedPassword);
		
		$isValid = PasswordUtils::verify($complexPassword, $passwordData);
		
		expect($isValid)->toBe(true);
	});

	test('PasswordUtils → verifies unicode password', function (): void {
		$unicodePassword = 'пароль123世界🔐';
		$hashedPassword = password_hash($unicodePassword, PASSWORD_DEFAULT);
		$passwordData = new PasswordData($hashedPassword);
		
		$isValid = PasswordUtils::verify($unicodePassword, $passwordData);
		
		expect($isValid)->toBe(true);
	});

	test('PasswordUtils → verifies long password', function (): void {
		$longPassword = str_repeat('long-password-', 20);
		$hashedPassword = password_hash($longPassword, PASSWORD_DEFAULT);
		$passwordData = new PasswordData($hashedPassword);
		
		$isValid = PasswordUtils::verify($longPassword, $passwordData);
		
		expect($isValid)->toBe(true);
	});

	test('PasswordUtils → case sensitive password verification', function (): void {
		$password = 'CaseSensitive123';
		$wrongCase = 'casesensitive123';
		$hashedPassword = password_hash($password, PASSWORD_DEFAULT);
		$passwordData = new PasswordData($hashedPassword);
		
		$validCorrect = PasswordUtils::verify($password, $passwordData);
		$validWrong = PasswordUtils::verify($wrongCase, $passwordData);
		
		expect($validCorrect)->toBe(true);
		expect($validWrong)->toBe(false);
	});

	test('PasswordUtils → verifies different password hash algorithms', function (): void {
		$password = 'test-algorithm';
		
		// Test with PASSWORD_DEFAULT (which is currently BCRYPT)
		$bcryptHash = password_hash($password, PASSWORD_DEFAULT);
		$bcryptData = new PasswordData($bcryptHash);
		
		expect(PasswordUtils::verify($password, $bcryptData))->toBe(true);
		
		// Test with explicit BCRYPT
		$bcryptExplicit = password_hash($password, PASSWORD_BCRYPT);
		$bcryptExplicitData = new PasswordData($bcryptExplicit);
		
		expect(PasswordUtils::verify($password, $bcryptExplicitData))->toBe(true);
	});

	test('PasswordUtils → works with PasswordData toString conversion', function (): void {
		$password = 'toString-test';
		$hashedPassword = password_hash($password, PASSWORD_DEFAULT);
		$passwordData = new PasswordData($hashedPassword);
		
		// PasswordUtils calls (string)$passwordData internally
		$isValid = PasswordUtils::verify($password, $passwordData);
		
		expect($isValid)->toBe(true);
		expect((string)$passwordData)->toBe($hashedPassword);
	});
});