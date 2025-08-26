<?php

use TotalCMS\Domain\Property\Data\FileData;
use TotalCMS\Domain\Property\Data\ListData;
use TotalCMS\Domain\Property\Data\PasswordData;
use TotalCMS\Domain\Property\Data\DateData;

describe('FileData', function (): void {
	test('FileData → creates with empty data', function (): void {
		$file = new FileData();
		
		expect($file->protected)->toBe(true);
		expect($file->name)->toBe('');
		expect($file->download)->toBe('');
		expect($file->mime)->toBe('');
		expect($file->comments)->toBe('');
		expect($file->size)->toBe(0);
		expect($file->count)->toBe(0);
		expect($file->tags)->toBeInstanceOf(ListData::class);
		expect($file->password)->toBeInstanceOf(PasswordData::class);
		expect($file->uploadDate)->toBeInstanceOf(DateData::class);
		expect($file->settings)->toBe([]);
	});

	test('FileData → creates with complete file data', function (): void {
		$fileData = [
			'protected' => false,
			'name' => 'document.pdf',
			'download' => 'my-document.pdf',
			'mime' => 'application/pdf',
			'comments' => 'Important document',
			'size' => 1024000,
			'count' => 5,
			'tags' => ['document', 'pdf', 'important'],
			'password' => 'secret123',
			'uploadDate' => '2024-01-15T10:30:00+00:00',
		];
		
		$file = new FileData($fileData);
		
		expect($file->protected)->toBe(false);
		expect($file->name)->toBe('document.pdf');
		expect($file->download)->toBe('my-document.pdf');
		expect($file->mime)->toBe('application/pdf');
		expect($file->comments)->toBe('Important document');
		expect($file->size)->toBe(1024000);
		expect($file->count)->toBe(5);
		expect($file->tags->list)->toBe(['document', 'pdf', 'important']);
		expect($file->uploadDate->date)->toBe('2024-01-15T10:30:00+00:00');
	});

	test('FileData → creates with settings', function (): void {
		$settings = ['maxSize' => 5000000, 'allowedTypes' => ['pdf', 'doc']];
		$file = new FileData([], $settings);
		
		expect($file->settings)->toBe($settings);
	});

	test('FileData → uses name as download when download is empty', function (): void {
		$fileData = [
			'name' => 'original-file.txt',
			'download' => '', // Empty download
		];
		
		$file = new FileData($fileData);
		
		expect($file->download)->toBe('original-file.txt');
	});

	test('FileData → uses provided download when not empty', function (): void {
		$fileData = [
			'name' => 'original-file.txt',
			'download' => 'renamed-file.txt',
		];
		
		$file = new FileData($fileData);
		
		expect($file->download)->toBe('renamed-file.txt');
	});

	test('FileData → handles missing download field', function (): void {
		$fileData = [
			'name' => 'test-file.jpg',
			// No download field
		];
		
		$file = new FileData($fileData);
		
		expect($file->download)->toBe('test-file.jpg');
	});

	test('FileData → sets default upload date when empty', function (): void {
		$file = new FileData(['uploadDate' => '']);
		
		// Should be today's date in ISO format
		expect($file->uploadDate->date)->toMatch('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}[+\-]\d{2}:\d{2}$/');
	});

	test('FileData → preserves provided upload date', function (): void {
		$specificDate = '2023-12-25T15:45:30+00:00';
		$file = new FileData(['uploadDate' => $specificDate]);
		
		expect($file->uploadDate->date)->toBe($specificDate);
	});

	test('FileData → converts string numbers to integers', function (): void {
		$fileData = [
			'size' => '2048000',
			'count' => '10',
		];
		
		$file = new FileData($fileData);
		
		expect($file->size)->toBe(2048000);
		expect($file->count)->toBe(10);
	});

	test('FileData → handles invalid numeric values', function (): void {
		$fileData = [
			'size' => 'invalid',
			'count' => 'also-invalid',
		];
		
		$file = new FileData($fileData);
		
		expect($file->size)->toBe(0);
		expect($file->count)->toBe(0);
	});

	test('FileData → handles different mime types', function (): void {
		$mimeTypes = [
			'image/jpeg',
			'application/pdf',
			'text/plain',
			'video/mp4',
			'audio/mpeg',
		];
		
		foreach ($mimeTypes as $mime) {
			$file = new FileData(['mime' => $mime]);
			expect($file->mime)->toBe($mime);
		}
	});

	test('FileData → transforms to array correctly', function (): void {
		$fileData = [
			'protected' => false,
			'name' => 'test.pdf',
			'download' => 'test-download.pdf',
			'mime' => 'application/pdf',
			'comments' => 'Test file',
			'size' => 1024,
			'count' => 3,
			'tags' => ['test', 'pdf'],
			'password' => 'secret',
			'uploadDate' => '2024-01-01T00:00:00+00:00',
		];
		
		$file = new FileData($fileData);
		$result = $file->transform();
		
		expect($result)->toHaveKey('protected', false);
		expect($result)->toHaveKey('name', 'test.pdf');
		expect($result)->toHaveKey('download', 'test-download.pdf');
		expect($result)->toHaveKey('mime', 'application/pdf');
		expect($result)->toHaveKey('comments', 'Test file');
		expect($result)->toHaveKey('size', 1024);
		expect($result)->toHaveKey('count', 3);
		expect($result)->toHaveKey('tags', ['test', 'pdf']);
		expect($result)->toHaveKey('uploadDate', '2024-01-01T00:00:00+00:00');
		// Password transform might return hash, so just check it exists
		expect($result)->toHaveKey('password');
	});

	test('FileData → converts to JSON string', function (): void {
		$fileData = [
			'name' => 'document.txt',
			'mime' => 'text/plain',
			'size' => 500,
		];
		
		$file = new FileData($fileData);
		$json = (string)$file;
		
		expect($json)->toBeString();
		expect($json)->not->toBe('');
		
		$decoded = json_decode($json, true);
		expect($decoded)->toBeArray();
		expect($decoded['name'])->toBe('document.txt');
		expect($decoded['mime'])->toBe('text/plain');
		expect($decoded['size'])->toBe(500);
	});

	test('FileData → handles empty tags array', function (): void {
		$file = new FileData(['tags' => []]);
		
		expect($file->tags->list)->toBe([]);
	});

	test('FileData → processes tags through ListData', function (): void {
		$file = new FileData(['tags' => ['tag1', 'tag2', 'tag1', '', 'tag3']]);
		
		// ListData removes empty values and duplicates
		expect($file->tags->list)->toBe(['tag1', 'tag2', 'tag3']);
	});

	test('FileData → handles password through PasswordData', function (): void {
		$file = new FileData(['password' => 'mypassword']);
		
		expect($file->password)->toBeInstanceOf(PasswordData::class);
		// Password gets hashed, so just verify it's not the original
		expect($file->password->hash)->not->toBe('mypassword');
		expect($file->password->hash)->not->toBe('');
	});

	test('FileData → handles boolean protected field', function (): void {
		$protectedFile = new FileData(['protected' => true]);
		$unprotectedFile = new FileData(['protected' => false]);
		
		expect($protectedFile->protected)->toBe(true);
		expect($unprotectedFile->protected)->toBe(false);
	});

	test('FileData → handles complex file scenarios', function (): void {
		$complexFile = [
			'name' => 'annual-report-2024.pdf',
			'download' => 'Annual Report 2024.pdf',
			'mime' => 'application/pdf',
			'comments' => 'Quarterly financial report with charts and analysis',
			'size' => 15728640, // 15MB
			'count' => 127,
			'tags' => ['annual', 'report', '2024', 'financial', 'pdf'],
			'protected' => true,
			'password' => 'confidential2024',
			'uploadDate' => '2024-03-31T23:59:59+00:00',
		];
		
		$file = new FileData($complexFile);
		
		expect($file->name)->toBe('annual-report-2024.pdf');
		expect($file->download)->toBe('Annual Report 2024.pdf');
		expect($file->size)->toBe(15728640);
		expect($file->count)->toBe(127);
		expect($file->protected)->toBe(true);
		expect(count($file->tags->list))->toBe(5);
		expect($file->uploadDate->date)->toBe('2024-03-31T23:59:59+00:00');
	});

	test('FileData → handles zero values correctly', function (): void {
		$file = new FileData([
			'size' => 0,
			'count' => 0,
		]);
		
		expect($file->size)->toBe(0);
		expect($file->count)->toBe(0);
	});
});