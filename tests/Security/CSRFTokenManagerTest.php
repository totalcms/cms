<?php

namespace Tests\Security;

use Odan\Session\PhpSession;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use TotalCMS\Domain\Security\CSRF\CSRFTokenManager;

/**
 * Test CSRF Token Manager functionality.
 */
#[CoversClass(CSRFTokenManager::class)]
final class CSRFTokenManagerTest extends TestCase
{
	private CSRFTokenManager $csrfManager;
	private PhpSession $session;

	protected function setUp(): void
	{
		parent::setUp();

		// Create PhpSession instance for testing
		$this->session = new PhpSession();
		$this->session->start();

		// Clear any existing CSRF data
		$this->session->delete('csrf_token');

		$this->csrfManager = new CSRFTokenManager($this->session);
	}

	protected function tearDown(): void
	{
		// Clean up session data
		if ($this->session->isStarted()) {
			$this->session->delete('csrf_token');
			$this->session->destroy();
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

		$this->assertTrue($this->session->has('csrf_token'));
		$sessionData = $this->session->get('csrf_token');
		$this->assertArrayHasKey('token', $sessionData);
		$this->assertArrayHasKey('created_at', $sessionData);
		$this->assertEquals($token, $sessionData['token']);
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
		$this->assertTrue($this->session->has('csrf_token'));
	}

	public function testValidateTokenWithValidToken(): void
	{
		$token   = $this->csrfManager->generateToken();
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
		$expiredData               = $this->session->get('csrf_token');
		$expiredData['created_at'] = time() - 7200; // 2 hours ago
		$this->session->set('csrf_token', $expiredData);

		$isValid = $this->csrfManager->validateToken($token);

		$this->assertFalse($isValid);
		$this->assertFalse($this->session->has('csrf_token')); // Should be cleaned up
	}

	public function testClearTokenRemovesFromSession(): void
	{
		$this->csrfManager->generateToken();
		$this->assertTrue($this->session->has('csrf_token'));

		$this->csrfManager->clearToken();
		$this->assertFalse($this->session->has('csrf_token'));
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
		$sessionData = $this->session->get('csrf_token');
		$this->assertEquals($token2, $sessionData['token']);
	}

	public function testGetTokenNameReturnsCorrectName(): void
	{
		$tokenName = $this->csrfManager->getTokenName();
		$this->assertEquals('csrf_token', $tokenName);
	}

	public function testValidateFromRequestWithPostData(): void
	{
		$token    = $this->csrfManager->generateToken();
		$postData = ['csrf_token' => $token];

		$isValid = $this->csrfManager->validateFromRequest($postData);
		$this->assertTrue($isValid);
	}

	public function testValidateFromRequestWithHeader(): void
	{
		$token   = $this->csrfManager->generateToken();
		$headers = ['X-CSRF-Token' => $token];

		$isValid = $this->csrfManager->validateFromRequest([], $headers);
		$this->assertTrue($isValid);
	}

	public function testValidateFromRequestWithQueryData(): void
	{
		$token     = $this->csrfManager->generateToken();
		$queryData = ['csrf_token' => $token];

		$isValid = $this->csrfManager->validateFromRequest([], [], $queryData);
		$this->assertTrue($isValid);
	}

	public function testValidateFromRequestPrioritizesPostOverHeader(): void
	{
		$validToken   = $this->csrfManager->generateToken();
		$invalidToken = 'invalid_token';

		$postData = ['csrf_token' => $validToken];
		$headers  = ['X-CSRF-Token' => $invalidToken];

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
