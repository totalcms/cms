<?php

declare(strict_types=1);

namespace Tests\Unit\Domain\Session;

use PHPUnit\Framework\TestCase;
use TotalCMS\Domain\Session\SessionKeys;

/**
 * Test session state validation and edge cases.
 */
class SessionStateValidationTest extends TestCase
{
    public function testSessionKeyCompleteness(): void
    {
        // Ensure we have keys for all major session use cases
        $requiredCategories = [
            'authentication' => [
                SessionKeys::AUTH_USER,
                SessionKeys::AUTH_COLLECTION,
                SessionKeys::AUTH_PERSISTENT_LOGIN,
            ],
            'request_tracking' => [
                SessionKeys::REQUEST_ORIGIN_URL,
                SessionKeys::REQUEST_REFERER_URL,
            ],
            'activity_monitoring' => [
                SessionKeys::LAST_ACTIVITY,
                SessionKeys::LOGIN_ATTEMPTS,
                SessionKeys::DOWNLOAD_ATTEMPTS,
            ],
        ];
        
        $allKeys = SessionKeys::getAllKeys();
        
        foreach ($requiredCategories as $category => $categoryKeys) {
            foreach ($categoryKeys as $key) {
                $this->assertContains(
                    $key,
                    $allKeys,
                    "Required key '$key' from category '$category' should be in getAllKeys()"
                );
            }
        }
    }
    
    public function testSessionKeyUniqueness(): void
    {
        // Ensure all session keys are unique
        $allKeys = SessionKeys::getAllKeys();
        $uniqueKeys = array_unique($allKeys);
        
        $this->assertCount(
            count($allKeys),
            $uniqueKeys,
            'All session keys should be unique'
        );
    }
    
    public function testSessionKeyNamingConsistency(): void
    {
        // Test naming patterns for consistency
        $authKeys = SessionKeys::getAuthKeys();
        foreach ($authKeys as $key) {
            $this->assertStringContainsString(
                'totalcms.auth.',
                $key,
                "Auth key '$key' should use totalcms.auth. prefix"
            );
        }
        
        // Request keys should follow pattern
        $requestKeys = SessionKeys::getRequestKeys();
        foreach ($requestKeys as $key) {
            $this->assertStringStartsWith(
                'totalcms.request',
                $key,
                "Request key '$key' should start with totalcms.request"
            );
        }
    }
    
    public function testSessionKeyLengthReasonableness(): void
    {
        // Ensure session keys aren't too long or too short
        $allKeys = SessionKeys::getAllKeys();
        
        foreach ($allKeys as $key) {
            $keyLength = strlen($key);
            
            $this->assertGreaterThanOrEqual(
                10, // At least "totalcms.x"
                $keyLength,
                "Key '$key' should be at least 10 characters"
            );
            
            $this->assertLessThanOrEqual(
                50, // Reasonable maximum
                $keyLength,
                "Key '$key' should not exceed 50 characters"
            );
        }
    }
    
    public function testSessionKeySpecialCharacters(): void
    {
        // Ensure session keys only contain safe characters
        $allKeys = SessionKeys::getAllKeys();
        
        foreach ($allKeys as $key) {
            // Should only contain alphanumeric, dots, and underscores
            $this->assertMatchesRegularExpression(
                '/^[a-zA-Z0-9._]+$/',
                $key,
                "Key '$key' should only contain alphanumeric, dots, and underscores"
            );
            
            // Should not have problematic patterns
            $this->assertStringNotContainsString('..', $key, "Key '$key' should not have double dots");
            $this->assertStringNotContainsString('__', $key, "Key '$key' should not have double underscores");
        }
    }
    
    public function testSessionKeyNamespaceIsolation(): void
    {
        // Test that Total CMS namespace is properly isolated
        $totalCMSPrefix = 'totalcms.';
        $allKeys = SessionKeys::getAllKeys();
        
        foreach ($allKeys as $key) {
            $this->assertStringStartsWith(
                $totalCMSPrefix,
                $key,
                "All Total CMS keys should start with '$totalCMSPrefix'"
            );
            
            // After the prefix, should not start with common external prefixes
            $keyWithoutPrefix = substr($key, strlen($totalCMSPrefix));
            $prohibitedPrefixes = ['session.', 'php.', 'app.', 'user.', 'admin.'];
            
            foreach ($prohibitedPrefixes as $prohibitedPrefix) {
                $this->assertStringStartsNotWith(
                    $prohibitedPrefix,
                    $keyWithoutPrefix,
                    "Key suffix '$keyWithoutPrefix' should not start with prohibited prefix '$prohibitedPrefix'"
                );
            }
        }
    }
    
    public function testSessionKeyGroupingCompleteness(): void
    {
        // Verify that grouped keys add up to all keys
        $authKeys = SessionKeys::getAuthKeys();
        $requestKeys = SessionKeys::getRequestKeys();
        $activityKeys = SessionKeys::getActivityKeys();
        $allKeys = SessionKeys::getAllKeys();
        
        $groupedKeysCount = count($authKeys) + count($requestKeys) + count($activityKeys);
        $allKeysCount = count($allKeys);
        
        $this->assertEquals(
            $allKeysCount,
            $groupedKeysCount,
            'Sum of grouped keys should equal total keys count'
        );
        
        // Verify no overlap between groups
        $allGrouped = array_merge($authKeys, $requestKeys, $activityKeys);
        $uniqueGrouped = array_unique($allGrouped);
        
        $this->assertCount(
            count($allGrouped),
            $uniqueGrouped,
            'No overlap should exist between key groups'
        );
    }
    
    public function testSessionKeyUtilityMethodReliability(): void
    {
        // Test edge cases for utility methods
        
        // Test isTotalCMSKey with edge cases
        $edgeCases = [
            '' => false,
            'totalcms' => false,  // Missing dot
            'totalcms.' => true,  // Just prefix
            'totalcms..' => true, // Double dot (starts with totalcms. so it's valid by our implementation)
            'TOTALCMS.test' => false, // Wrong case
            ' totalcms.test' => false, // Leading space
            'totalcms.test ' => true, // Trailing space (still starts with totalcms. so our implementation returns true)
        ];
        
        foreach ($edgeCases as $key => $expected) {
            $actual = SessionKeys::isTotalCMSKey($key);
            $this->assertEquals(
                $expected,
                $actual,
                "isTotalCMSKey('$key') should return " . ($expected ? 'true' : 'false')
            );
        }
    }
    
    public function testSessionKeyDocumentationAccuracy(): void
    {
        // Verify that constants match their intended purpose
        
        // Authentication keys should be clearly auth-related
        $this->assertStringContainsString('auth', SessionKeys::AUTH_USER);
        $this->assertStringContainsString('auth', SessionKeys::AUTH_COLLECTION);
        $this->assertStringContainsString('auth', SessionKeys::AUTH_PERSISTENT_LOGIN);
        
        // Request keys should be clearly request-related
        $this->assertStringContainsString('request', SessionKeys::REQUEST_ORIGIN_URL);
        $this->assertStringContainsString('request', SessionKeys::REQUEST_REFERER_URL);
        
        // Activity keys should describe their purpose
        $this->assertStringContainsString('Activity', SessionKeys::LAST_ACTIVITY);
        $this->assertStringContainsString('Attempts', SessionKeys::LOGIN_ATTEMPTS);
        $this->assertStringContainsString('Attempts', SessionKeys::DOWNLOAD_ATTEMPTS);
    }
}