<?php

use TotalCMS\Domain\License\Service\LicenseStatus;
use TotalCMS\Domain\License\Service\LicenseValidator;
use TotalCMS\Factory\LoggerFactory;

describe('LicenseStatus', function () {
    test('can be instantiated', function () {
        $licenseValidator = test()->createMock(LicenseValidator::class);
        $loggerFactory = test()->createMock(LoggerFactory::class);
        $logger = test()->createMock(\Psr\Log\LoggerInterface::class);

        $loggerFactory->method('addFileHandler')->willReturnSelf();
        $loggerFactory->method('createLogger')->willReturn($logger);

        $licenseStatus = new LicenseStatus($licenseValidator, $loggerFactory);

        expect($licenseStatus)->toBeInstanceOf(LicenseStatus::class);
    });
});