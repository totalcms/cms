<?php

declare(strict_types=1);

use TotalCMS\Domain\Property\Data\PasswordData;
use TotalCMS\Domain\Security\Authentication\PasswordUtils;

describe('PasswordUtils', function (): void {
	// -------------------------
	// Password Verification
	// -------------------------

	test('PasswordUtils → verify returns true for correct password', function (): void {
		$plainPassword = 'correctpassword123';
		$passwordData  = new PasswordData($plainPassword);

		$result = PasswordUtils::verify($plainPassword, $passwordData);

		expect($result)->toBe(true);
	});

	test('PasswordUtils → verify returns false for incorrect password', function (): void {
		$plainPassword = 'correctpassword123';
		$wrongPassword = 'wrongpassword456';
		$passwordData  = new PasswordData($plainPassword);

		$result = PasswordUtils::verify($wrongPassword, $passwordData);

		expect($result)->toBe(false);
	});

	test('PasswordUtils → verify returns false for empty password against non-empty hash', function (): void {
		$plainPassword = 'somepassword';
		$emptyPassword = '';
		$passwordData  = new PasswordData($plainPassword);

		$result = PasswordUtils::verify($emptyPassword, $passwordData);

		expect($result)->toBe(false);
	});

	test('PasswordUtils → verify handles empty password data', function (): void {
		$plainPassword     = 'somepassword';
		$emptyPasswordData = new PasswordData(''); // Empty password creates empty hash

		$result = PasswordUtils::verify($plainPassword, $emptyPasswordData);

		expect($result)->toBe(false);
	});

	test('PasswordUtils → verify works with complex passwords', function (): void {
		$complexPassword = 'P@ssw0rd!$#%^&*()_+{}[]|\\:";\'<>?,./`~1234567890';
		$passwordData    = new PasswordData($complexPassword);

		expect(PasswordUtils::verify($complexPassword, $passwordData))->toBe(true);
		expect(PasswordUtils::verify($complexPassword . 'x', $passwordData))->toBe(false);
	});

	test('PasswordUtils → verify works with unicode passwords', function (): void {
		$unicodePassword = 'пароль123ñäöü世界🔒';
		$passwordData    = new PasswordData($unicodePassword);

		expect(PasswordUtils::verify($unicodePassword, $passwordData))->toBe(true);
		expect(PasswordUtils::verify('пароль123ñäöü世界', $passwordData))->toBe(false);
	});

	test('PasswordUtils → verify is case sensitive', function (): void {
		$password     = 'CaseSensitivePassword';
		$passwordData = new PasswordData($password);

		expect(PasswordUtils::verify($password, $passwordData))->toBe(true);
		expect(PasswordUtils::verify('casesensitivepassword', $passwordData))->toBe(false);
		expect(PasswordUtils::verify('CASESENSITIVEPASSWORD', $passwordData))->toBe(false);
	});

	test('PasswordUtils → verify handles whitespace correctly', function (): void {
		$passwordWithSpaces = ' password with spaces ';
		$passwordData       = new PasswordData($passwordWithSpaces);

		expect(PasswordUtils::verify($passwordWithSpaces, $passwordData))->toBe(true);
		expect(PasswordUtils::verify('password with spaces', $passwordData))->toBe(false);
		expect(PasswordUtils::verify(' password with spaces', $passwordData))->toBe(false);
		expect(PasswordUtils::verify('password with spaces ', $passwordData))->toBe(false);
	});

	// -------------------------
	// Pre-hashed Password Handling
	// -------------------------

	test('PasswordUtils → verify works with pre-hashed passwords', function (): void {
		$plainPassword     = 'testpassword';
		$preHashedPassword = password_hash($plainPassword, PASSWORD_DEFAULT);
		$passwordData      = new PasswordData($preHashedPassword); // Should recognize and use existing hash

		$result = PasswordUtils::verify($plainPassword, $passwordData);

		expect($result)->toBe(true);
	});

	test('PasswordUtils → verify rejects incorrect password against pre-hashed data', function (): void {
		$plainPassword     = 'testpassword';
		$wrongPassword     = 'wrongpassword';
		$preHashedPassword = password_hash($plainPassword, PASSWORD_DEFAULT);
		$passwordData      = new PasswordData($preHashedPassword);

		$result = PasswordUtils::verify($wrongPassword, $passwordData);

		expect($result)->toBe(false);
	});

	// -------------------------
	// Edge Cases and Error Handling
	// -------------------------

	test('PasswordUtils → verify handles moderately long passwords', function (): void {
		$longPassword = str_repeat('a', 60) . '123'; // 63 character password (within PHP limits)
		$passwordData = new PasswordData($longPassword);

		expect(PasswordUtils::verify($longPassword, $passwordData))->toBe(true);
		expect(PasswordUtils::verify($longPassword . 'x', $passwordData))->toBe(false);
	});

	test('PasswordUtils → verify handles single character passwords', function (): void {
		$singleCharPassword = 'x';
		$passwordData       = new PasswordData($singleCharPassword);

		expect(PasswordUtils::verify($singleCharPassword, $passwordData))->toBe(true);
		expect(PasswordUtils::verify('X', $passwordData))->toBe(false);
	});

	test('PasswordUtils → verify handles numeric string passwords', function (): void {
		$numericPassword = '123456789';
		$passwordData    = new PasswordData($numericPassword);

		expect(PasswordUtils::verify($numericPassword, $passwordData))->toBe(true);
		expect(PasswordUtils::verify('123456788', $passwordData))->toBe(false);
	});

	test('PasswordUtils → verify handles special characters and newlines', function (): void {
		$specialPassword = "password\nwith\ttabs\rand\r\nnewlines";
		$passwordData    = new PasswordData($specialPassword);

		expect(PasswordUtils::verify($specialPassword, $passwordData))->toBe(true);
		expect(PasswordUtils::verify('password with tabs and newlines', $passwordData))->toBe(false);
	});

	// -------------------------
	// Type Coercion and String Conversion
	// -------------------------

	test('PasswordUtils → verify properly casts PasswordData to string', function (): void {
		$password     = 'testpassword';
		$passwordData = new PasswordData($password);

		// The method should properly convert PasswordData to string via __toString()
		$hash = (string)$passwordData;
		expect($hash)->toBeString();
		expect($hash)->not->toBe($password); // Should be hashed, not plain
		expect(strlen($hash))->toBeGreaterThan(50); // Password hashes are long

		// Verify the conversion works in the utility
		expect(PasswordUtils::verify($password, $passwordData))->toBe(true);
	});

	// -------------------------
	// Security Properties
	// -------------------------

	test('PasswordUtils → different PasswordData instances with same password verify correctly', function (): void {
		$password      = 'samePlainPassword';
		$passwordData1 = new PasswordData($password);
		$passwordData2 = new PasswordData($password);

		// Both should verify the same password
		expect(PasswordUtils::verify($password, $passwordData1))->toBe(true);
		expect(PasswordUtils::verify($password, $passwordData2))->toBe(true);

		// But the hashes might be different due to salt
		// This is expected behavior for password_hash()
	});

	test('PasswordUtils → empty password data behaves consistently', function (): void {
		$emptyPasswordData = new PasswordData('');

		expect(PasswordUtils::verify('', $emptyPasswordData))->toBe(false);
		expect(PasswordUtils::verify('anypassword', $emptyPasswordData))->toBe(false);

		// Empty password data should have empty hash
		expect((string)$emptyPasswordData)->toBe('');
	});

	test('PasswordUtils → is static utility class', function (): void {
		$reflection = new ReflectionClass(PasswordUtils::class);
		$methods    = $reflection->getMethods(ReflectionMethod::IS_PUBLIC);

		// All public methods should be static
		foreach ($methods as $method) {
			expect($method->isStatic())->toBe(true, "Method {$method->getName()} should be static");
		}
	});
});
