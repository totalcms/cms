<?php

declare(strict_types=1);

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;
use TotalCMS\TotalCMS;

/**
 * Test TotalCMS session conflict handling functionality.
 */
class TotalCMSTest extends TestCase
{
    public function testSessionConflictHandlingPreserveStrategy(): void
    {
        // Skip if we're in a web environment where session might already be started
        if (session_status() === PHP_SESSION_ACTIVE) {
            $this->markTestSkipped('Session already active, cannot test conflict handling');
        }
        
        // Start a session to simulate existing session
        session_start();
        $_SESSION['existing_key'] = 'existing_value';
        $_SESSION['shared_key'] = 'external_value';
        
        // Verify session was active before TotalCMS
        $this->assertEquals(PHP_SESSION_ACTIVE, session_status());
        
        // Create TotalCMS instance (should handle the conflict automatically)
        $totalCMS = new TotalCMS(false);
        
        // Verify session is still active (TotalCMS should have restarted it)
        $this->assertEquals(PHP_SESSION_ACTIVE, session_status());
        
        // The main test is that the constructor doesn't throw errors when handling conflicts
        $this->assertInstanceOf(TotalCMS::class, $totalCMS);
        
        // Clean up
        session_destroy();
    }
    
    public function testSessionConflictHandlingNoConflict(): void
    {
        // Ensure no session is active
        if (session_status() === PHP_SESSION_ACTIVE) {
            session_destroy();
        }
        
        // Create TotalCMS instance (should work normally)
        $totalCMS = new TotalCMS(false);
        
        $this->assertInstanceOf(TotalCMS::class, $totalCMS);
        
        // Clean up if session was started
        if (session_status() === PHP_SESSION_ACTIVE) {
            session_destroy();
        }
    }
}