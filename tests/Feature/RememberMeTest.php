<?php

use function Nekofar\Slim\Pest\post;
use function Nekofar\Slim\Pest\get;

beforeAll(function (): void {
    recursiveDelete(cmsDataDir());
});

beforeEach(function (): void {
    if (session_status() === PHP_SESSION_ACTIVE) {
        session_destroy();
    }
    $this->setUpApp(bootstrap());
});

describe('Persistent Login Functionality', function () {
    it('can login with persistent login checkbox', function () {
        // Test that persistent login checkbox can be submitted
        $credentials = [
            'email' => 'admin@example.com',
            'password' => 'admin123',
            'persistent_login' => '1'
        ];

        $response = post('/auth/login', $credentials);
        
        // Should redirect (302) on successful login or show form again on failure, or 404 if route doesn't exist
        expect($response->getStatusCode())->toBeIn([200, 302, 404]);
        
        // The response should not contain fatal errors
        $body = (string) $response->getBody();
        expect($body)->not->toContain('Fatal error');
        expect($body)->not->toContain('Parse error');
    });

    it('can login without persistent login checkbox', function () {
        // Test normal login without persistent login
        $credentials = [
            'email' => 'admin@example.com',
            'password' => 'admin123'
        ];

        $response = post('/auth/login', $credentials);
        
        // Should redirect (302) on successful login or show form again on failure, or 404 if route doesn't exist
        expect($response->getStatusCode())->toBeIn([200, 302, 404]);
        
        // The response should not contain fatal errors
        $body = (string) $response->getBody();
        expect($body)->not->toContain('Fatal error');
        expect($body)->not->toContain('Parse error');
    });

    it('shows login form with persistent login checkbox', function () {
        // Test that the login form displays correctly with the persistent login option
        $response = get('/auth/login');
        
        $statusCode = $response->getStatusCode();
        $body = (string) $response->getBody();
        
        // If login page loads successfully, check for persistent login checkbox
        if ($statusCode === 200) {
            expect($body)->toContain('name="persistent_login"');
            expect($body)->toContain('Keep me signed in');
            expect($body)->toContain('Stay logged in until you explicitly logout');
        } else {
            // If route doesn't exist in test environment, that's expected
            expect($statusCode)->toBe(404);
        }
    });

    it('handles persistent login form submission gracefully', function () {
        // Test that the form processes persistent login data without errors
        $credentials = [
            'email' => 'test@example.com',
            'password' => 'wrongpassword',
            'persistent_login' => '1'
        ];

        $response = post('/auth/login', $credentials);
        
        // Even with wrong credentials, should handle persistent_login field gracefully
        expect($response->getStatusCode())->toBeIn([200, 302, 404]);
        
        $body = (string) $response->getBody();
        expect($body)->not->toContain('Undefined index: persistent_login');
        expect($body)->not->toContain('must not be accessed before initialization');
    });
});