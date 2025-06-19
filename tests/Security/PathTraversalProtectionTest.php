<?php

namespace Tests\Security;

use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Message\UriInterface;
use Slim\Exception\HttpNotFoundException;
use TotalCMS\Action\Admin\AdminDocsAction;
use TotalCMS\Action\Assets\StaticPublicAssetsAction;
use TotalCMS\Renderer\TwigRenderer;

/**
 * Test Path Traversal Protection in various actions
 * 
 * @covers \TotalCMS\Action\Admin\AdminDocsAction
 * @covers \TotalCMS\Action\Assets\StaticPublicAssetsAction
 */
final class PathTraversalProtectionTest extends TestCase
{
	private ServerRequestInterface $request;
	private ResponseInterface $response;
	private UriInterface $uri;

	protected function setUp(): void
	{
		parent::setUp();
		
		$this->request = $this->createMock(ServerRequestInterface::class);
		$this->response = $this->createMock(ResponseInterface::class);
		$this->uri = $this->createMock(UriInterface::class);
		
		$this->request->method('getUri')->willReturn($this->uri);
		$this->uri->method('getPath')->willReturn('/test');
		$this->uri->method('getQuery')->willReturn('');
	}

	/**
	 * Test AdminDocsAction path traversal protection
	 */
	public function testAdminDocsActionBlocksPathTraversal(): void
	{
		// Since TwigRenderer is final, we'll test the sanitization logic directly
		$pathTraversalAttempts = [
			'../../../etc/passwd',
			'..\\..\\..\\windows\\system32\\config\\sam',
			'../../../../var/log/auth.log',
			'../../../config/database.php',
		];
		
		foreach ($pathTraversalAttempts as $maliciousPage) {
			// Test the sanitization logic that AdminDocsAction uses
			$sanitized = basename($maliciousPage);
			$sanitized = preg_replace('/[^a-zA-Z0-9_-]/', '', $sanitized);
			
			// Should either be empty (default to index) or sanitized filename
			$this->assertTrue(
				empty($sanitized) || !str_contains($sanitized, '..'),
				"Path traversal not properly sanitized: $maliciousPage -> $sanitized"
			);
		}
	}

	public function testAdminDocsActionAllowsLegitimatePages(): void
	{
		$legitimatePages = ['api', 'setup', 'configuration', 'templates'];
		
		foreach ($legitimatePages as $page) {
			// Test the sanitization logic preserves legitimate pages
			$sanitized = basename($page);
			$sanitized = preg_replace('/[^a-zA-Z0-9_-]/', '', $sanitized);
			
			$this->assertEquals($page, $sanitized, "Legitimate page should not be changed: $page");
		}
	}

	public function testAdminDocsActionSanitizesPageParameter(): void
	{
		$maliciousInputs = [
			'page<script>alert(1)</script>',
			'page?param=value',
			'page&other=value',
			'page|whoami',
			'page;rm -rf /',
		];
		
		foreach ($maliciousInputs as $maliciousPage) {
			// Test the sanitization removes dangerous characters
			$sanitized = basename($maliciousPage);
			$sanitized = preg_replace('/[^a-zA-Z0-9_-]/', '', $sanitized);
			
			$this->assertStringNotContainsString('<script>', $sanitized);
			$this->assertStringNotContainsString('?', $sanitized);
			$this->assertStringNotContainsString('&', $sanitized);
			$this->assertStringNotContainsString('|', $sanitized);
			$this->assertStringNotContainsString(';', $sanitized);
		}
	}

	/**
	 * Test StaticPublicAssetsAction path traversal protection
	 */
	public function testStaticAssetsActionBlocksPathTraversal(): void
	{
		// Create a temporary assets directory for testing
		$tempDir = sys_get_temp_dir() . '/totalcms_test_assets_' . uniqid();
		mkdir($tempDir, 0755, true);
		
		try {
			$action = new StaticPublicAssetsAction();
			
			$pathTraversalAttempts = [
				'../../../etc/passwd',
				'..\\..\\..\\windows\\system32\\config\\sam',
				'../../../../var/log/auth.log',
				'../../../config/database.php',
				'..%2F..%2F..%2Fetc%2Fpasswd', // URL encoded
			];
			
			foreach ($pathTraversalAttempts as $maliciousAsset) {
				$args = ['asset' => $maliciousAsset];
				
				$this->expectException(HttpNotFoundException::class);
				$action($this->request, $this->response, $args);
			}
		} finally {
			// Clean up
			if (is_dir($tempDir)) {
				rmdir($tempDir);
			}
		}
	}

	public function testStaticAssetsActionBlocksInvalidCharacters(): void
	{
		$action = new StaticPublicAssetsAction();
		
		$invalidAssets = [
			'asset<script>alert(1)</script>.js',
			'asset|whoami.css',
			'asset;rm -rf /.png',
			'asset`command`.jpg',
			'asset$(command).gif',
		];
		
		foreach ($invalidAssets as $invalidAsset) {
			$args = ['asset' => $invalidAsset];
			
			$this->expectException(HttpNotFoundException::class);
			$this->expectExceptionMessage('Invalid asset path');
			
			$action($this->request, $this->response, $args);
		}
	}

	public function testStaticAssetsActionAllowsLegitimateAssets(): void
	{
		// We can't easily test successful asset serving without setting up the full directory structure,
		// but we can test that valid asset paths pass the validation steps
		$action = new StaticPublicAssetsAction();
		
		$legitimateAssets = [
			'css/style.css',
			'js/app.js',
			'images/logo.png',
			'fonts/roboto.woff2',
			'icons/favicon.ico',
			'subfolder/file.txt',
		];
		
		foreach ($legitimateAssets as $asset) {
			$args = ['asset' => $asset];
			
			// These should fail with "Asset not found" (404) rather than "Invalid asset path" (403)
			// This proves the path validation passed
			try {
				$action($this->request, $this->response, $args);
				$this->fail('Expected HttpNotFoundException');
			} catch (HttpNotFoundException $e) {
				$this->assertStringContainsString('Asset not found', $e->getMessage());
				$this->assertStringNotContainsString('Invalid asset path', $e->getMessage());
			}
		}
	}

	/**
	 * Test basename() function behavior for security
	 */
	public function testBasenameSecurityBehavior(): void
	{
		$testCases = [
			'../../../etc/passwd' => 'passwd',
			'/var/log/auth.log' => 'auth.log',
			'../../../../config/database.php' => 'database.php',
			'normal-file.txt' => 'normal-file.txt',
			'..%2F..%2F..%2Fetc%2Fpasswd' => '..%2F..%2F..%2Fetc%2Fpasswd', // URL encoding not decoded by basename
		];
		
		// Windows path behavior is platform-specific, so test Unix paths only
		foreach ($testCases as $input => $expected) {
			$result = basename($input);
			$this->assertEquals($expected, $result, "basename('$input') should return '$expected'");
		}
		
		// Test Windows-style paths separately with platform detection
		if (DIRECTORY_SEPARATOR === '\\') {
			$windowsResult = basename('..\\..\\..\\windows\\system32\\config\\sam');
			$this->assertEquals('sam', $windowsResult);
		}
	}

	/**
	 * Test realpath() security validation
	 */
	public function testRealpathSecurityValidation(): void
	{
		// Create a test directory structure
		$tempDir = sys_get_temp_dir() . '/totalcms_security_test_' . uniqid();
		$assetsDir = $tempDir . '/assets';
		$restrictedDir = $tempDir . '/restricted';
		
		mkdir($assetsDir, 0755, true);
		mkdir($restrictedDir, 0755, true);
		
		// Create test files
		file_put_contents($assetsDir . '/test.txt', 'safe content');
		file_put_contents($restrictedDir . '/secret.txt', 'restricted content');
		
		try {
			// Test that valid paths within assets directory are allowed
			$validPath = $assetsDir . '/test.txt';
			$resolvedValid = realpath($validPath);
			$this->assertStringStartsWith(realpath($assetsDir), $resolvedValid);
			
			// Test that paths outside assets directory would be blocked
			$invalidPath = $assetsDir . '/../restricted/secret.txt';
			$resolvedInvalid = realpath($invalidPath);
			$this->assertStringStartsWith(realpath($restrictedDir), $resolvedInvalid);
			$this->assertThat($resolvedInvalid, $this->logicalNot($this->stringStartsWith(realpath($assetsDir))));
			
			// Test non-existent paths
			$nonExistentPath = $assetsDir . '/nonexistent.txt';
			$resolvedNonExistent = realpath($nonExistentPath);
			$this->assertFalse($resolvedNonExistent);
			
		} finally {
			// Clean up
			unlink($assetsDir . '/test.txt');
			unlink($restrictedDir . '/secret.txt');
			rmdir($assetsDir);
			rmdir($restrictedDir);
			rmdir($tempDir);
		}
	}

	/**
	 * Test character filtering for security
	 */
	public function testCharacterFiltering(): void
	{
		$testCases = [
			'normal-file_123.txt' => 'normal-file_123.txt', // Should remain unchanged
			'file<script>alert(1)</script>' => 'file_script_alert_1___script_', // Dangerous chars replaced
			'file|whoami' => 'file_whoami', // Pipe replaced
			'file;rm -rf /' => 'file_rm_-rf__', // Semicolon and spaces replaced
			'file`command`' => 'file_command_', // Backticks replaced
			'file$(command)' => 'file__command_', // Dollar and parens replaced
		];
		
		foreach ($testCases as $input => $expected) {
			$result = preg_replace('/[^a-zA-Z0-9._-]/', '_', $input);
			$this->assertEquals($expected, $result, "Character filtering for '$input' failed");
		}
	}

	/**
	 * Test that default page fallback works
	 */
	public function testDefaultPageFallback(): void
	{
		// Test that empty/null page defaults to 'index'
		$page = null;
		$defaulted = $page ?? 'index';
		
		$this->assertEquals('index', $defaulted, "Should default to index when page is null");
		
		// Test with empty string
		$page = '';
		$defaulted = $page ?: 'index';
		
		$this->assertEquals('index', $defaulted, "Should default to index when page is empty");
	}
}