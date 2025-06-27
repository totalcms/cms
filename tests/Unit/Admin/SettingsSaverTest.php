<?php

use TotalCMS\Domain\Admin\SettingsSaver;
use org\bovigo\vfs\vfsStream;
use org\bovigo\vfs\vfsStreamWrapper;

beforeEach(function (): void {
    // Clean up any existing test data before each test
    recursiveDelete(cmsDataDir());

    if (session_status() === PHP_SESSION_ACTIVE) {
        session_destroy();
    }
    $this->setUpApp(bootstrap());
    
    // Setup virtual filesystem for settings file
    vfsStreamWrapper::register();
    vfsStreamWrapper::setRoot(vfsStream::newDirectory('root'));
    
    // Store original DOCUMENT_ROOT and set to virtual filesystem
    $this->originalDocumentRoot = $_SERVER['DOCUMENT_ROOT'] ?? '';
    $_SERVER['DOCUMENT_ROOT'] = vfsStream::url('root');
});

afterEach(function () {
    // Restore original DOCUMENT_ROOT
    $_SERVER['DOCUMENT_ROOT'] = $this->originalDocumentRoot;
});

test('saves basic settings when no existing config', function () {
    $container = $this->app->getContainer();
    $settingsSaver = $container->get(SettingsSaver::class);

    $settings = [
        'timezone' => 'America/New_York',
        'datadir' => 'custom-data',
        'sentry' => 'on'
    ];

    $result = $settingsSaver->save($settings);

    expect(file_exists(vfsStream::url('root/tcms.php')))->toBeTrue();
    
    $savedConfig = include vfsStream::url('root/tcms.php');
    expect($savedConfig)->toBeArray();
    expect($savedConfig['timezone'])->toBe('America/New_York');
    expect($savedConfig['datadir'])->toBe('custom-data');
    expect($savedConfig['sentry'])->toBeTrue();
});

test('removes csrf tokens', function () {
    $container = $this->app->getContainer();
    $settingsSaver = $container->get(SettingsSaver::class);

    $settings = [
        'timezone' => 'UTC',
        'csrf_token' => 'abc123',
        'csrf_token_name' => 'token_name'
    ];

    $settingsSaver->save($settings);

    $savedConfig = include vfsStream::url('root/tcms.php');
    expect($savedConfig)->not->toHaveKey('csrf_token');
    expect($savedConfig)->not->toHaveKey('csrf_token_name');
    expect($savedConfig['timezone'])->toBe('UTC');
});

test('filters empty values', function () {
    $container = $this->app->getContainer();
    $settingsSaver = $container->get(SettingsSaver::class);

    $settings = [
        'timezone' => 'UTC',
        'datadir' => '',
        'notfound' => 'page.html'
    ];

    $settingsSaver->save($settings);

    $savedConfig = include vfsStream::url('root/tcms.php');
    expect($savedConfig)->not->toHaveKey('datadir');
    expect($savedConfig['timezone'])->toBe('UTC');
    expect($savedConfig['notfound'])->toBe('page.html');
});

test('handles sentry checkbox', function () {
    $container = $this->app->getContainer();
    $settingsSaver = $container->get(SettingsSaver::class);

    // Test sentry enabled (checkbox checked)
    $settings = ['sentry' => 'on'];
    $settingsSaver->save($settings);
    
    $savedConfig = include vfsStream::url('root/tcms.php');
    expect($savedConfig['sentry'])->toBeTrue();

    // Test sentry disabled (checkbox unchecked)
    $settings = ['timezone' => 'UTC']; // No sentry key
    $settingsSaver->save($settings);
    
    $savedConfig = include vfsStream::url('root/tcms.php');
    expect($savedConfig['sentry'])->toBeFalse();
});

test('transforms pagination to dashboard', function () {
    $container = $this->app->getContainer();
    $settingsSaver = $container->get(SettingsSaver::class);

    $settings = [
        'timezone' => 'UTC',
        'pagination' => '100'
    ];

    $settingsSaver->save($settings);

    $savedConfig = include vfsStream::url('root/tcms.php');
    expect($savedConfig)->not->toHaveKey('pagination');
    expect($savedConfig)->toHaveKey('dashboard');
    expect($savedConfig['dashboard']['pagination'])->toBe(100);
});

test('handles json presets', function () {
    $container = $this->app->getContainer();
    $settingsSaver = $container->get(SettingsSaver::class);

    $presets = ['preset1' => ['width' => 100], 'preset2' => ['width' => 200]];
    $settings = [
        'timezone' => 'UTC',
        'presets' => json_encode($presets)
    ];

    $settingsSaver->save($settings);

    $savedConfig = include vfsStream::url('root/tcms.php');
    expect($savedConfig['presets'])->toBe($presets);
});

test('preserves existing custom settings', function () {
    $container = $this->app->getContainer();
    $settingsSaver = $container->get(SettingsSaver::class);

    // Create existing config with custom settings
    $existingConfig = [
        'timezone' => 'America/Chicago',
        'htmlclean' => [
            'enabled' => true,
            'allowed_css_properties' => ['color', 'margin']
        ],
        'custom_feature' => [
            'enabled' => true,
            'options' => ['a', 'b', 'c']
        ]
    ];

    $configContent = "<?php\n\nreturn json_decode(<<<JSON\n";
    $configContent .= json_encode($existingConfig, JSON_PRETTY_PRINT);
    $configContent .= "\nJSON, true);\n";
    
    file_put_contents(vfsStream::url('root/tcms.php'), $configContent);

    // Save new settings that should merge with existing
    $newSettings = [
        'timezone' => 'UTC',
        'datadir' => 'new-data'
    ];

    $settingsSaver->save($newSettings);

    $savedConfig = include vfsStream::url('root/tcms.php');
    
    // New settings should be applied
    expect($savedConfig['timezone'])->toBe('UTC');
    expect($savedConfig['datadir'])->toBe('new-data');
    
    // Custom settings should be preserved
    expect($savedConfig['htmlclean'])->toBe(['enabled' => true, 'allowed_css_properties' => ['color', 'margin']]);
    expect($savedConfig['custom_feature'])->toBe(['enabled' => true, 'options' => ['a', 'b', 'c']]);
});

test('deep merges nested settings', function () {
    $container = $this->app->getContainer();
    $settingsSaver = $container->get(SettingsSaver::class);

    // Create existing config with nested dashboard settings
    $existingConfig = [
        'timezone' => 'UTC',
        'dashboard' => [
            'pagination' => 50,
            'custom_feature' => true,
            'nested' => [
                'deep' => 'value',
                'other' => 'data'
            ]
        ]
    ];

    $configContent = "<?php\n\nreturn json_decode(<<<JSON\n";
    $configContent .= json_encode($existingConfig, JSON_PRETTY_PRINT);
    $configContent .= "\nJSON, true);\n";
    
    file_put_contents(vfsStream::url('root/tcms.php'), $configContent);

    // Save new settings including pagination
    $newSettings = [
        'timezone' => 'America/New_York',
        'pagination' => '100'
    ];

    $settingsSaver->save($newSettings);

    $savedConfig = include vfsStream::url('root/tcms.php');
    
    // New settings should be applied
    expect($savedConfig['timezone'])->toBe('America/New_York');
    
    // Dashboard settings should be deeply merged
    expect($savedConfig['dashboard']['pagination'])->toBe(100); // Updated
    expect($savedConfig['dashboard']['custom_feature'])->toBeTrue(); // Preserved
    expect($savedConfig['dashboard']['nested'])->toBe(['deep' => 'value', 'other' => 'data']); // Preserved
});

test('returns processed settings', function () {
    $container = $this->app->getContainer();
    $settingsSaver = $container->get(SettingsSaver::class);

    $settings = [
        'timezone' => 'UTC',
        'sentry' => 'on',
        'pagination' => '50',
        'csrf_token' => 'should_be_removed'
    ];

    $result = $settingsSaver->save($settings);

    // Should return original form data (before merge)
    expect($result['timezone'])->toBe('UTC');
    expect($result['sentry'])->toBeTrue();
    expect($result['pagination'])->toBe('50');
    expect($result)->not->toHaveKey('csrf_token');
});

test('handles invalid existing config', function () {
    $container = $this->app->getContainer();
    $settingsSaver = $container->get(SettingsSaver::class);

    // Create invalid config file (not returning array)
    file_put_contents(vfsStream::url('root/tcms.php'), "<?php\nreturn 'invalid';");

    $settings = ['timezone' => 'UTC'];
    $settingsSaver->save($settings);

    $savedConfig = include vfsStream::url('root/tcms.php');
    expect($savedConfig)->toBeArray();
    expect($savedConfig['timezone'])->toBe('UTC');
});

test('creates readable json format', function () {
    $container = $this->app->getContainer();
    $settingsSaver = $container->get(SettingsSaver::class);

    $settings = [
        'timezone' => 'UTC',
        'htmlclean' => [
            'enabled' => true,
            'allowed_css_properties' => ['color', 'margin']
        ]
    ];

    $settingsSaver->save($settings);

    $fileContent = file_get_contents(vfsStream::url('root/tcms.php'));
    
    // Should be valid PHP
    expect($fileContent)->toStartWith('<?php');
    
    // Should contain JSON
    expect($fileContent)->toContain('json_decode(<<<JSON');
    expect($fileContent)->toContain('JSON, true)');
    
    // Should be formatted nicely
    expect($fileContent)->toContain('    "timezone": "UTC"');
    expect($fileContent)->toContain('    "htmlclean": {');
});