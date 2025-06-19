<?php

namespace Tests\Security;

use PHPUnit\Framework\TestCase;
use TotalCMS\Utils\Cipher;
use TotalCMS\Utils\CSRFTokenManager;

/**
 * Test CSRF Token Manager functionality
 * 
 * @covers \TotalCMS\Utils\CSRFTokenManager
 */
final class CSRFTokenManagerTest extends TestCase
{
	private CSRFTokenManager $csrfManager;
	private Cipher $cipher;

	protected function setUp(): void
	{
		parent::setUp();
		
		// Start session for testing
		if (session_status() !== PHP_SESSION_ACTIVE) {
			session_start();
		}
		
		// Clear any existing CSRF data
		unset($_SESSION['csrf_token']);
		
		$this->cipher = new Cipher();
		$this->csrfManager = new CSRFTokenManager($this->cipher);
	}

	protected function tearDown(): void
	{
		// Clean up session data
		if (isset($_SESSION['csrf_token'])) {
			unset($_SESSION['csrf_token']);
		}
		
		parent::tearDown();
	}

	public function testGenerateTokenCreatesValidToken(): void
	{
		$token = $this->csrfManager->generateToken();
		
		$this->assertIsString($token);
		$this->assertEquals(64, strlen($token)); // 32 bytes = 64 hex chars
		$this->assertMatchesRegularExpression('/^[a-f0-9]+$/', $token);
	}

	public function testGenerateTokenStoresInSession(): void
	{
		$token = $this->csrfManager->generateToken();
		
		$this->assertArrayHasKey('csrf_token', $_SESSION);
		$this->assertArrayHasKey('token', $_SESSION['csrf_token']);
		$this->assertArrayHasKey('created_at', $_SESSION['csrf_token']);
		$this->assertEquals($token, $_SESSION['csrf_token']['token']);
	}

	public function testGetTokenReturnsExistingToken(): void
	{
		$token1 = $this->csrfManager->generateToken();
		$token2 = $this->csrfManager->getToken();
		
		$this->assertEquals($token1, $token2);
	}

	public function testGetTokenGeneratesNewTokenIfNoneExists(): void
	{
		$token = $this->csrfManager->getToken();
		
		$this->assertIsString($token);
		$this->assertEquals(64, strlen($token));
		$this->assertArrayHasKey('csrf_token', $_SESSION);
	}

	public function testValidateTokenWithValidToken(): void
	{
		$token = $this->csrfManager->generateToken();
		$isValid = $this->csrfManager->validateToken($token);
		
		$this->assertTrue($isValid);
	}

	public function testValidateTokenWithInvalidToken(): void
	{
		$this->csrfManager->generateToken();
		$isValid = $this->csrfManager->validateToken('invalid_token');
		
		$this->assertFalse($isValid);
	}

	public function testValidateTokenWithEmptyToken(): void
	{
		$this->csrfManager->generateToken();
		$isValid = $this->csrfManager->validateToken('');
		
		$this->assertFalse($isValid);
	}

	public function testValidateTokenWithoutSession(): void
	{
		// Don't generate a token first
		$isValid = $this->csrfManager->validateToken('some_token');
		
		$this->assertFalse($isValid);
	}

	public function testValidateTokenWithExpiredToken(): void
	{
		$token = $this->csrfManager->generateToken();
		
		// Manually set the token as expired
		$_SESSION['csrf_token']['created_at'] = time() - 7200; // 2 hours ago
		
		$isValid = $this->csrfManager->validateToken($token);
		
		$this->assertFalse($isValid);
		$this->assertArrayNotHasKey('csrf_token', $_SESSION); // Should be cleaned up
	}

	public function testClearTokenRemovesFromSession(): void
	{
		$this->csrfManager->generateToken();
		$this->assertArrayHasKey('csrf_token', $_SESSION);
		
		$this->csrfManager->clearToken();
		$this->assertArrayNotHasKey('csrf_token', $_SESSION);
	}

	public function testGetTokenFieldReturnsValidHTML(): void
	{
		$html = $this->csrfManager->getTokenField();
		
		$this->assertStringContainsString('<input type="hidden"', $html);
		$this->assertStringContainsString('name="csrf_token"', $html);
		$this->assertStringContainsString('value="', $html);
		$this->assertStringContainsString('/>', $html);
	}

	public function testGetTokenForAjaxReturnsCorrectStructure(): void
	{
		$ajaxData = $this->csrfManager->getTokenForAjax();
		
		$this->assertIsArray($ajaxData);
		$this->assertArrayHasKey('name', $ajaxData);
		$this->assertArrayHasKey('value', $ajaxData);
		$this->assertEquals('csrf_token', $ajaxData['name']);
		$this->assertIsString($ajaxData['value']);
		$this->assertEquals(64, strlen($ajaxData['value']));
	}

	public function testRegenerateTokenCreatesNewToken(): void
	{
		$token1 = $this->csrfManager->generateToken();
		$token2 = $this->csrfManager->regenerateToken();
		
		$this->assertNotEquals($token1, $token2);
		$this->assertEquals($token2, $_SESSION['csrf_token']['token']);
	}

	public function testGetTokenNameReturnsCorrectName(): void
	{
		$tokenName = $this->csrfManager->getTokenName();
		$this->assertEquals('csrf_token', $tokenName);
	}

	public function testValidateFromRequestWithPostData(): void
	{
		$token = $this->csrfManager->generateToken();
		$postData = ['csrf_token' => $token];
		
		$isValid = $this->csrfManager->validateFromRequest($postData);
		$this->assertTrue($isValid);
	}

	public function testValidateFromRequestWithHeader(): void
	{
		$token = $this->csrfManager->generateToken();
		$headers = ['X-CSRF-Token' => $token];
		
		$isValid = $this->csrfManager->validateFromRequest([], $headers);
		$this->assertTrue($isValid);
	}

	public function testValidateFromRequestWithQueryData(): void
	{
		$token = $this->csrfManager->generateToken();
		$queryData = ['csrf_token' => $token];
		
		$isValid = $this->csrfManager->validateFromRequest([], [], $queryData);
		$this->assertTrue($isValid);
	}

	public function testValidateFromRequestPrioritizesPostOverHeader(): void
	{
		$validToken = $this->csrfManager->generateToken();
		$invalidToken = 'invalid_token';
		
		$postData = ['csrf_token' => $validToken];
		$headers = ['X-CSRF-Token' => $invalidToken];
		
		$isValid = $this->csrfManager->validateFromRequest($postData, $headers);
		$this->assertTrue($isValid);
	}

	public function testValidateFromRequestWithNoValidToken(): void
	{
		$this->csrfManager->generateToken();
		
		$isValid = $this->csrfManager->validateFromRequest([], [], []);
		$this->assertFalse($isValid);
	}

	public function testTokenUniqueness(): void
	{
		$tokens = [];
		for ($i = 0; $i < 10; $i++) {
			$this->csrfManager->clearToken();
			$tokens[] = $this->csrfManager->generateToken();
		}
		
		$uniqueTokens = array_unique($tokens);
		$this->assertEquals(count($tokens), count($uniqueTokens), 'All tokens should be unique');
	}

	public function testSessionRequiredForGeneration(): void
	{
		// Destroy session
		session_destroy();
		
		$this->expectException(\RuntimeException::class);
		$this->expectExceptionMessage('Session must be active to generate CSRF token');
		
		$this->csrfManager->generateToken();
	}

	public function testSessionRequiredForGettingToken(): void
	{
		// Destroy session
		session_destroy();
		
		$this->expectException(\RuntimeException::class);
		$this->expectExceptionMessage('Session must be active to get CSRF token');
		
		$this->csrfManager->getToken();
	}

	public function testValidateTokenWithoutActiveSession(): void
	{
		// Create token first
		$token = $this->csrfManager->generateToken();
		
		// Destroy session
		session_destroy();
		
		$isValid = $this->csrfManager->validateToken($token);
		$this->assertFalse($isValid);
	}
}