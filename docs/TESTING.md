# Total CMS Testing Guide

This guide provides comprehensive documentation for testing Total CMS, including setup, test organization, and best practices.

## Table of Contents

1. [Overview](#overview)
2. [Test Environment Setup](#test-environment-setup)
3. [Running Tests](#running-tests)
4. [Test Organization](#test-organization)
5. [Writing Tests](#writing-tests)
6. [Test Categories](#test-categories)
7. [Testing Best Practices](#testing-best-practices)
8. [Continuous Integration](#continuous-integration)
9. [Performance Testing](#performance-testing)
10. [Security Testing](#security-testing)

## Overview

Total CMS uses **Pest PHP** as the primary testing framework, providing a modern and expressive testing experience. The test suite is organized into multiple categories to ensure comprehensive coverage:

- **Unit Tests**: Test individual classes and methods in isolation
- **Feature Tests**: Test complete user workflows and API endpoints
- **Security Tests**: Validate security measures and vulnerability prevention
- **Integration Tests**: Test component interactions and data flow

### Testing Framework Stack

- **Pest PHP 3.0**: Modern testing framework with expressive syntax
- **PHPUnit 11**: Underlying test engine
- **Slim Test**: HTTP testing utilities for API endpoints
- **Faker**: Test data generation
- **VfsStream**: Virtual file system for testing

## Test Environment Setup

### Prerequisites

```bash
# Install dependencies
composer install

# Ensure Node.js dependencies are installed
yarn install
```

### Environment Configuration

Tests run in an isolated environment with:
- Separate test data directory (`tests/tcms-data/`)
- Test-specific configuration files
- Clean database state for each test
- Isolated session handling

### Test Data

Test data is organized in:
- `tests/test-data/`: Sample JSON files and test assets
- `tests/tcms-data/`: Runtime test data directory (auto-cleaned)

## Running Tests

### Basic Commands

```bash
# Run all tests
composer run test

# Run tests with coverage
composer run test:coverage

# Run specific test file
vendor/bin/pest tests/Feature/CollectionTest.php

# Run tests with specific filter
vendor/bin/pest --filter="saves a new collection"

# Run tests in parallel
vendor/bin/pest --parallel

# Update test snapshots
composer run test:updatesnaps
```

### Quality Assurance Commands

```bash
# Run all quality checks
composer run test:all

# Static analysis only
composer run stan

# Code style check
composer run cs

# Code style fix
composer run cs:fix

# Mess detection
composer run md

# PHP linting
composer run lint
```

### Debug and Verbose Output

```bash
# Run with verbose output
vendor/bin/pest --verbose

# Display all warnings and notices
vendor/bin/pest --display-notices --display-warnings --display-errors --display-deprecations

# Stop on first failure
vendor/bin/pest --stop-on-failure
```

## Test Organization

### Directory Structure

```
tests/
├── Feature/           # Integration and API tests
│   ├── AuthTest.php
│   ├── CollectionTest.php
│   ├── DocsTest.php
│   ├── FactoryTest.php
│   ├── FileTest.php
│   ├── ImageTest.php
│   ├── ObjectTest.php
│   ├── SchemaTest.php
│   └── TemplateTest.php
├── Security/          # Security-focused tests
│   ├── CSRFProtectionMiddlewareTest.php
│   ├── HTMLSanitizerTest.php
│   ├── SvgSanitizationTest.php
│   └── ...
├── Unit/              # Unit tests for individual classes
│   ├── Property/
│   │   ├── StringDataTest.php
│   │   ├── ImageDataTest.php
│   │   └── ...
│   └── ExampleTest.php
├── test-data/         # Sample test data
├── tcms-data/         # Runtime test data (auto-cleaned)
├── Pest.php           # Global test configuration
├── TestCase.php       # Base test case class
└── bootstrap.php      # Test environment bootstrap
```

### Test Categories

#### Feature Tests (`tests/Feature/`)
Test complete user workflows and API endpoints:

```php
it('creates a new blog post', function (): void {
    $postData = [
        'title' => 'Test Blog Post',
        'content' => 'This is test content',
        'status' => 'published'
    ];
    
    postJson('/collections/blog', $postData)
        ->assertCreated()
        ->assertJson()
        ->assertJsonFragment($postData);
});
```

#### Unit Tests (`tests/Unit/`)
Test individual classes and methods:

```php
it('validates email format', function (): void {
    $emailData = new EmailData('test@example.com');
    
    expect($emailData->getValue())->toBe('test@example.com');
    expect($emailData->isValid())->toBeTrue();
});
```

#### Security Tests (`tests/Security/`)
Validate security measures:

```php
it('prevents XSS attacks in HTML content', function (): void {
    $maliciousHtml = '<script>alert("xss")</script><p>Safe content</p>';
    $sanitizer = new HTMLSanitizer();
    
    $cleaned = $sanitizer->sanitize($maliciousHtml);
    
    expect($cleaned)->not->toContain('<script>');
    expect($cleaned)->toContain('<p>Safe content</p>');
});
```

## Writing Tests

### Test Structure

Total CMS uses Pest's expressive syntax:

```php
<?php

// Setup for all tests in this file
beforeEach(function (): void {
    $this->setUpApp(bootstrap());
});

// Cleanup after all tests
afterAll(function (): void {
    recursiveDelete(cmsDataDir());
});

// Test case
it('performs the expected action', function (): void {
    // Arrange
    $testData = ['key' => 'value'];
    
    // Act
    $result = performAction($testData);
    
    // Assert
    expect($result)->toBe('expected');
});
```

### HTTP Testing with Slim Test

```php
use function Nekofar\Slim\Pest\get;
use function Nekofar\Slim\Pest\postJson;
use function Nekofar\Slim\Pest\putJson;
use function Nekofar\Slim\Pest\delete;

it('handles GET request', function (): void {
    get('/api/collections')
        ->assertOk()
        ->assertJson()
        ->assertJsonStructure(['data']);
});

it('handles POST request', function (): void {
    $payload = ['name' => 'test'];
    
    postJson('/api/collections', $payload)
        ->assertCreated()
        ->assertJsonFragment($payload);
});
```

### Test Data Management

```php
// Using test helper functions
function collectionTestData(): array {
    $json = file_get_contents(testData('new-text-collection.json'));
    return json_decode($json, true);
}

// Using Faker for dynamic data
it('creates user with fake data', function (): void {
    $userData = [
        'name' => fake()->name(),
        'email' => fake()->email(),
        'password' => fake()->password(8)
    ];
    
    // Test with generated data
});
```

### Database Testing

```php
beforeEach(function (): void {
    // Clean test database
    recursiveDelete(cmsDataDir());
    
    // Setup fresh application
    $this->setUpApp(bootstrap());
});

it('stores data correctly', function (): void {
    $collection = 'test-collection';
    $objectId = 'test-object';
    
    // Create test data
    createTestCollection($collection);
    
    // Verify file exists
    expect(metaPath($collection))->toBeFile();
    expect(objectPath($collection, $objectId))->toBeFile();
});
```

## Test Categories

### 1. API Testing

Test REST API endpoints:

```php
describe('Collections API', function () {
    it('lists all collections', function (): void {
        get('/api/collections')
            ->assertOk()
            ->assertJson()
            ->assertJsonStructure([
                'data' => [
                    '*' => ['id', 'name', 'type']
                ]
            ]);
    });
    
    it('creates new collection', function (): void {
        $data = ['name' => 'test', 'type' => 'blog'];
        
        postJson('/api/collections', $data)
            ->assertCreated()
            ->assertJsonFragment($data);
    });
});
```

### 2. Validation Testing

Test data validation:

```php
describe('Input Validation', function () {
    it('validates required fields', function (): void {
        postJson('/api/collections', [])
            ->assertUnprocessableEntity()
            ->assertJsonValidationErrors(['name', 'type']);
    });
    
    it('validates field types', function (): void {
        $invalidData = ['name' => 123, 'type' => null];
        
        postJson('/api/collections', $invalidData)
            ->assertUnprocessableEntity();
    });
});
```

### 3. Authentication Testing

Test authentication and authorization:

```php
describe('Authentication', function () {
    it('requires authentication for protected routes', function (): void {
        get('/admin/dashboard')
            ->assertRedirect('/login');
    });
    
    it('allows access with valid session', function (): void {
        $this->actingAs($user);
        
        get('/admin/dashboard')
            ->assertOk();
    });
});
```

### 4. File Upload Testing

Test file handling:

```php
describe('File Uploads', function () {
    it('accepts valid image files', function (): void {
        $uploadedFile = createTestImage();
        
        postMultipart('/upload', [
            'file' => $uploadedFile,
            'collection' => 'images'
        ])
        ->assertCreated()
        ->assertJsonStructure(['path', 'size', 'mime_type']);
    });
    
    it('rejects invalid file types', function (): void {
        $invalidFile = createTestExecutable();
        
        postMultipart('/upload', ['file' => $invalidFile])
            ->assertBadRequest()
            ->assertSee('Invalid file type');
    });
});
```

## Testing Best Practices

### 1. Test Naming

Use descriptive test names that explain the scenario:

```php
// Good
it('creates a new blog post with valid data', function () {});
it('rejects blog post creation with missing title', function () {});

// Bad
it('tests blog post creation', function () {});
it('test validation', function () {});
```

### 2. Test Organization

Group related tests using `describe()`:

```php
describe('Blog Post Management', function () {
    describe('Creation', function () {
        it('creates with valid data', function () {});
        it('rejects invalid data', function () {});
    });
    
    describe('Updates', function () {
        it('updates existing post', function () {});
        it('prevents unauthorized updates', function () {});
    });
});
```

### 3. Test Data Isolation

Each test should be independent:

```php
beforeEach(function (): void {
    // Clean state for each test
    recursiveDelete(cmsDataDir());
    $this->setUpApp(bootstrap());
});

// Or use transactions for database tests
beforeEach(function (): void {
    $this->beginTransaction();
});

afterEach(function (): void {
    $this->rollbackTransaction();
});
```

### 4. Assertion Best Practices

Use specific assertions:

```php
// Good
expect($response)->toHaveStatus(201);
expect($data)->toHaveKey('id');
expect($collection)->toHaveCount(5);

// Less specific
expect($response->getStatusCode())->toBe(201);
expect(isset($data['id']))->toBeTrue();
expect(count($collection))->toBe(5);
```

### 5. Test Documentation

Document complex test scenarios:

```php
it('handles concurrent collection updates correctly', function (): void {
    // This test simulates race conditions where two requests
    // attempt to update the same collection simultaneously
    
    // Setup: Create a collection
    $collection = createTestCollection();
    
    // Act: Simulate concurrent updates
    // ... test implementation
    
    // Assert: Verify data integrity
    expect($finalState)->toMatchExpectedState();
});
```

## Continuous Integration

### PHPStan Configuration

Static analysis runs at Level 8 for maximum type safety:

```bash
# Run static analysis
composer run stan

# Configuration: phpstan.neon
parameters:
    level: 8
    paths:
        - src
        - tests
```

### Code Coverage

Generate coverage reports:

```bash
# Run with coverage
composer run test:coverage

# Generate HTML coverage report
vendor/bin/pest --coverage-html coverage-report
```

### Pre-commit Hooks

Recommended pre-commit checks:

```bash
#!/bin/bash
# Pre-commit hook

# Run linting
composer run lint || exit 1

# Run static analysis
composer run stan || exit 1

# Run code style check
composer run cs || exit 1

# Run tests
composer run test || exit 1

echo "All checks passed!"
```

## Performance Testing

### Benchmarking

Use Pest's benchmarking features:

```php
it('performs API calls efficiently', function (): void {
    $startTime = microtime(true);
    
    // Perform operation
    get('/api/collections')->assertOk();
    
    $endTime = microtime(true);
    $duration = $endTime - $startTime;
    
    expect($duration)->toBeLessThan(0.1); // 100ms max
});
```

### Memory Usage Testing

```php
it('handles large datasets without memory issues', function (): void {
    $initialMemory = memory_get_usage();
    
    // Process large dataset
    processLargeCollection(10000);
    
    $finalMemory = memory_get_usage();
    $memoryIncrease = $finalMemory - $initialMemory;
    
    expect($memoryIncrease)->toBeLessThan(50 * 1024 * 1024); // 50MB max
});
```

## Security Testing

### Input Sanitization

```php
describe('XSS Prevention', function () {
    it('sanitizes HTML input', function (): void {
        $maliciousInput = '<script>alert("xss")</script>';
        
        $result = sanitizeHtml($maliciousInput);
        
        expect($result)->not->toContain('<script>');
    });
});
```

### CSRF Protection

```php
describe('CSRF Protection', function () {
    it('requires CSRF token for state-changing requests', function (): void {
        postJson('/api/collections', ['name' => 'test'])
            ->assertStatus(403)
            ->assertSee('CSRF token required');
    });
    
    it('accepts requests with valid CSRF token', function (): void {
        $token = generateCSRFToken();
        
        postJson('/api/collections', ['name' => 'test'], [
            'X-CSRF-Token' => $token
        ])->assertCreated();
    });
});
```

### File Upload Security

```php
describe('File Upload Security', function () {
    it('prevents executable file uploads', function (): void {
        $phpFile = createMaliciousPhpFile();
        
        postMultipart('/upload', ['file' => $phpFile])
            ->assertBadRequest()
            ->assertSee('File type not allowed');
    });
});
```

## Debugging Tests

### Debug Output

```php
it('debugs test data', function (): void {
    $data = processTestData();
    
    // Dump data for debugging
    dump($data);
    ray($data); // If using Ray
    
    expect($data)->toMatchSomething();
});
```

### Test Isolation Issues

```php
// Clean up properly
afterEach(function (): void {
    // Clear any global state
    $_SESSION = [];
    $_GET = [];
    $_POST = [];
    
    // Reset singletons if any
    resetApplication();
});
```

## Advanced Testing Patterns

### Custom Assertions

```php
// Create custom expectations
expect()->extend('toBeValidCollection', function () {
    return $this->toHaveKeys(['id', 'name', 'type'])
                ->and($this->id)->not->toBeEmpty()
                ->and($this->name)->not->toBeEmpty();
});

// Usage
expect($collection)->toBeValidCollection();
```

### Test Factories

```php
function createTestCollection(array $overrides = []): array {
    return array_merge([
        'id' => 'test-' . uniqid(),
        'name' => 'Test Collection',
        'type' => 'blog',
        'schema' => 'default-blog',
    ], $overrides);
}
```

### Snapshot Testing

```php
it('generates expected API response structure', function (): void {
    $response = get('/api/collections')->json();
    
    expect($response)->toMatchSnapshot();
});
```

---

This testing guide ensures comprehensive coverage of Total CMS functionality while maintaining high code quality and security standards. Follow these patterns and practices to create reliable, maintainable tests that provide confidence in the system's behavior.