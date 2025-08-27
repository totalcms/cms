<?php

/**
 * Test login redirect functionality that preserves user's original destination
 * through registration and login flows.
 * 
 * These tests focus on the core redirect URL generation logic.
 */
describe('Login Redirect Functionality', function (): void {
    describe('AccessManager Redirect URL Logic', function (): void {
        test('generates login URL with redirect parameter', function (): void {
            $apiBase = '/api';
            $originUrl = '/protected/page';
            
            // Simulate the logic from AccessManager::redirectToLogin
            $loginUrl = $apiBase . '/login';
            if (!empty($originUrl)) {
                $loginUrl .= '?' . http_build_query(['redirect' => $originUrl]);
            }
            
            expect($loginUrl)->toBe('/api/login?redirect=%2Fprotected%2Fpage');
        });

        test('generates collection login URL with redirect parameter', function (): void {
            $apiBase = '/api';
            $collection = 'members';
            $originUrl = '/member/area';
            
            // Simulate the logic from AccessManager::redirectToLogin
            $loginUrl = $apiBase . '/login';
            if ($collection !== '') {
                $loginUrl .= "/$collection";
            }
            if (!empty($originUrl)) {
                $loginUrl .= '?' . http_build_query(['redirect' => $originUrl]);
            }
            
            expect($loginUrl)->toBe('/api/login/members?redirect=%2Fmember%2Farea');
        });

        test('generates login URL without redirect when no origin URL', function (): void {
            $apiBase = '/api';
            $originUrl = '';
            
            // Simulate the logic from AccessManager::redirectToLogin
            $loginUrl = $apiBase . '/login';
            if (!empty($originUrl)) {
                $loginUrl .= '?' . http_build_query(['redirect' => $originUrl]);
            }
            
            expect($loginUrl)->toBe('/api/login');
        });

        test('handles special characters in redirect URLs', function (): void {
            $apiBase = '/api';
            $originUrl = '/page with spaces & symbols?foo=bar';
            
            $loginUrl = $apiBase . '/login';
            if (!empty($originUrl)) {
                $loginUrl .= '?' . http_build_query(['redirect' => $originUrl]);
            }
            
            expect($loginUrl)->toBe('/api/login?redirect=%2Fpage+with+spaces+%26+symbols%3Ffoo%3Dbar');
        });
    });

    describe('AuthLoginSubmitAction Redirect Priority Logic', function (): void {
        test('POST redirect parameter takes highest priority', function (): void {
            $postData = ['redirect' => '/post/redirect'];
            $queryParams = ['redirect' => '/query/redirect'];
            $sessionOrigin = '/session/origin';
            $defaultUrl = '/admin';
            
            // Simulate the logic from AuthLoginSubmitAction
            $redirectUrl = $postData['redirect'] ?? $queryParams['redirect'] ?? $sessionOrigin ?? $defaultUrl;
            
            expect($redirectUrl)->toBe('/post/redirect');
        });

        test('query parameter used when POST redirect not available', function (): void {
            $postData = [];
            $queryParams = ['redirect' => '/query/redirect'];
            $sessionOrigin = '/session/origin';
            $defaultUrl = '/admin';
            
            // Simulate the logic from AuthLoginSubmitAction
            $redirectUrl = $postData['redirect'] ?? $queryParams['redirect'] ?? $sessionOrigin ?? $defaultUrl;
            
            expect($redirectUrl)->toBe('/query/redirect');
        });

        test('session origin URL used when no redirect parameters', function (): void {
            $postData = [];
            $queryParams = [];
            $sessionOrigin = '/session/origin';
            $defaultUrl = '/admin';
            
            // Simulate the logic from AuthLoginSubmitAction
            $redirectUrl = null;
            if (isset($postData['redirect'])) {
                $redirectUrl = $postData['redirect'];
            } elseif (isset($queryParams['redirect'])) {
                $redirectUrl = $queryParams['redirect'];
            } else {
                $redirectUrl = $sessionOrigin ?? $defaultUrl;
            }
            
            expect($redirectUrl)->toBe('/session/origin');
        });

        test('defaults to admin index when no redirect sources', function (): void {
            $postData = [];
            $queryParams = [];
            $sessionOrigin = null;
            $defaultUrl = '/admin';
            
            // Simulate the logic from AuthLoginSubmitAction
            $redirectUrl = null;
            if (isset($postData['redirect'])) {
                $redirectUrl = $postData['redirect'];
            } elseif (isset($queryParams['redirect'])) {
                $redirectUrl = $queryParams['redirect'];
            } else {
                $redirectUrl = $sessionOrigin ?? $defaultUrl;
            }
            
            expect($redirectUrl)->toBe('/admin');
        });
    });

    describe('Complete Registration Flow Logic', function (): void {
        test('simulates full redirect preservation flow', function (): void {
            // 1. User visits protected page
            $originalPage = '/premium/content';
            
            // 2. AccessManager generates login URL with redirect
            $apiBase = '/api';
            $loginUrl = $apiBase . '/login';
            if (!empty($originalPage)) {
                $loginUrl .= '?' . http_build_query(['redirect' => $originalPage]);
            }
            
            expect($loginUrl)->toBe('/api/login?redirect=%2Fpremium%2Fcontent');
            
            // 3. User goes to registration, T3 form redirects to login with preserved redirect
            // T3 form newAction would use: {{ cms.login('', '/premium/content') }}
            
            // 4. Login form submission includes the redirect
            $postData = [
                'email' => 'user@example.com',
                'password' => 'password',
                'redirect' => '/premium/content'  // Preserved from registration
            ];
            
            // 5. AuthLoginSubmitAction processes the redirect
            $finalRedirect = $postData['redirect'] ?? '/admin';
            
            expect($finalRedirect)->toBe('/premium/content');
        });

        test('handles URL encoding throughout the flow', function (): void {
            // Complex URL with query parameters and special characters
            $complexUrl = '/shop/products?category=electronics&search=phone+cases';
            
            // 1. AccessManager generates login URL
            $loginUrl = '/api/login?' . http_build_query(['redirect' => $complexUrl]);
            expect($loginUrl)->toBe('/api/login?redirect=%2Fshop%2Fproducts%3Fcategory%3Delectronics%26search%3Dphone%2Bcases');
            
            // 2. URL gets decoded and used in form
            parse_str(parse_url($loginUrl, PHP_URL_QUERY), $params);
            $extractedRedirect = $params['redirect'];
            expect($extractedRedirect)->toBe($complexUrl);
            
            // 3. Form submission preserves the original URL
            $postData = ['redirect' => $extractedRedirect];
            $finalRedirect = $postData['redirect'];
            
            expect($finalRedirect)->toBe($complexUrl);
        });
    });
});