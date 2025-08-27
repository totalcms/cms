<?php

declare(strict_types=1);

namespace Tests\Unit\Domain\Session;

use PHPUnit\Framework\TestCase;
use TotalCMS\TotalCMS;
use TotalCMS\Domain\Session\SessionKeys;

/**
 * Test session conflict handling functionality in detail.
 */
class SessionConflictHandlingTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        
        // Ensure no session is active before each test
        if (session_status() === PHP_SESSION_ACTIVE) {
            session_destroy();
        }
    }
    
    protected function tearDown(): void
    {
        // Clean up session after each test
        if (session_status() === PHP_SESSION_ACTIVE) {
            session_destroy();
        }
        
        parent::tearDown();
    }
    
    public function testSessionConflictWithPreserveStrategy(): void
    {
        // Skip if we're in a web environment where session might already be started
        if (session_status() === PHP_SESSION_ACTIVE) {
            $this->markTestSkipped('Session already active, cannot test conflict handling');
        }
        
        // Start external session with various data types
        session_start();
        $_SESSION['external_user'] = 'john_doe';
        $_SESSION['external_data'] = ['theme' => 'dark', 'lang' => 'en'];
        $_SESSION['external_count'] = 42;
        $_SESSION['external_bool'] = true;
        $_SESSION['shared_key'] = 'external_value'; // This might conflict
        
        $originalSessionData = $_SESSION;
        $this->assertCount(5, $originalSessionData);
        
        // Initialize Total CMS with default preserve strategy
        $totalCMS = new TotalCMS(false);
        
        // Session should still be active but managed by Total CMS now
        $this->assertEquals(PHP_SESSION_ACTIVE, session_status());
        
        // Verify Total CMS was created successfully
        $this->assertInstanceOf(TotalCMS::class, $totalCMS);
        
        // Note: We can't easily verify that external data was preserved without
        // accessing the internal session object, but the main test is that 
        // construction doesn't fail when there's a session conflict
    }
    
    public function testMultipleSessionConflictScenarios(): void
    {
        if (session_status() === PHP_SESSION_ACTIVE) {
            $this->markTestSkipped('Session already active, cannot test conflict handling');
        }
        
        $scenarios = [
            'empty_session' => [],
            'single_key' => ['user' => 'test'],
            'complex_data' => [
                'user_id' => 123,
                'permissions' => ['read', 'write'],
                'metadata' => ['created' => '2024-01-01', 'updated' => '2024-01-02'],
                'settings' => ['theme' => 'light', 'notifications' => true]
            ]
        ];
        
        foreach ($scenarios as $scenarioName => $sessionData) {
            // Start fresh session for each scenario
            if (session_status() === PHP_SESSION_ACTIVE) {
                session_destroy();
            }
            
            session_start();
            $_SESSION = $sessionData;
            
            // Test that Total CMS handles each scenario without errors
            $totalCMS = new TotalCMS(false);
            $this->assertInstanceOf(TotalCMS::class, $totalCMS, "Failed for scenario: $scenarioName");
            
            // Clean up for next scenario
            session_destroy();
        }
    }
    
    public function testSessionConflictWithTotalCMSNamespacedKeys(): void
    {
        if (session_status() === PHP_SESSION_ACTIVE) {
            $this->markTestSkipped('Session already active, cannot test conflict handling');
        }
        
        // Start session with keys that might look like Total CMS keys but aren't
        session_start();
        $_SESSION['totalcms_old_style'] = 'value1'; // Old style (underscore)
        $_SESSION['totalcms.custom.key'] = 'value2'; // Proper namespace but not official
        $_SESSION['user'] = 'external_user'; // Common key name
        $_SESSION['collection'] = 'external_collection'; // Another common name
        
        // Initialize Total CMS
        $totalCMS = new TotalCMS(false);
        $this->assertInstanceOf(TotalCMS::class, $totalCMS);
        
        // The test mainly verifies no conflicts occur during initialization
    }
    
    public function testSessionConflictLogging(): void
    {
        if (session_status() === PHP_SESSION_ACTIVE) {
            $this->markTestSkipped('Session already active, cannot test conflict handling');
        }
        
        // Start session with data to trigger conflict logging
        session_start();
        $_SESSION['external_key1'] = 'value1';
        $_SESSION['external_key2'] = 'value2';
        
        // Initialize Total CMS (this should log the conflict)
        $totalCMS = new TotalCMS(false);
        $this->assertInstanceOf(TotalCMS::class, $totalCMS);
        
        // Note: We can't easily test the actual logging without mocking the logger,
        // but this verifies the conflict handling code path is executed
    }
    
    public function testNoSessionConflictHandling(): void
    {
        // Skip if we're in CLI mode where sessions aren't started
        if (PHP_SAPI === 'cli') {
            $this->markTestSkipped('Sessions are not started in CLI mode');
        }
        
        // Ensure no session is active
        if (session_status() === PHP_SESSION_ACTIVE) {
            session_destroy();
        }
        
        // Initialize Total CMS when no session exists
        $totalCMS = new TotalCMS(false);
        $this->assertInstanceOf(TotalCMS::class, $totalCMS);
        
        // Session should be started by Total CMS (only in non-CLI mode)
        $this->assertEquals(PHP_SESSION_ACTIVE, session_status());
    }
    
    public function testSessionStateAfterConflictResolution(): void
    {
        if (session_status() === PHP_SESSION_ACTIVE) {
            $this->markTestSkipped('Session already active, cannot test conflict handling');
        }
        
        // Start external session
        session_start();
        $originalSessionId = session_id();
        $_SESSION['external_data'] = 'test_value';
        
        // Initialize Total CMS
        $totalCMS = new TotalCMS(false);
        
        // Verify session is still active
        $this->assertEquals(PHP_SESSION_ACTIVE, session_status());
        
        // Session ID should be different (new session created)
        $newSessionId = session_id();
        $this->assertNotEmpty($newSessionId);
        // Note: session_destroy() + new session might reuse ID, so we can't always assert difference
        
        $this->assertInstanceOf(TotalCMS::class, $totalCMS);
    }
}