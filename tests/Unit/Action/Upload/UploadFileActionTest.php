<?php

declare(strict_types=1);

use Nyholm\Psr7\Factory\Psr17Factory;
use Psr\Http\Message\UploadedFileInterface;
use TotalCMS\Action\Upload\UploadFileAction;
use TotalCMS\Domain\Property\Service\UploadSaver;
use TotalCMS\Domain\Security\Upload\FileUploadValidator;
use TotalCMS\Renderer\JsonRenderer;
use TotalCMS\Support\Config;

// UploadFileAction is the write end of the upload surface: it accepts a file
// from the browser, decides whether it is allowed on disk at all, and picks
// the public URL it will be served from. The validator and renderer here are
// the real ones — mocking them would test the mock, and the point of these
// tests is the security behaviour (dangerous extensions, filename
// sanitisation, subpath traversal), not the wiring.
//
// The suite previously had a `describe('UploadFileAction')` block whose only
// test issued a GET (which routes to ListUploadFilesAction) and accepted six
// different status codes, so none of this was covered.

/**
 * An uploaded file whose moveTo() really writes $content to disk, so the
 * action's content-sniffing step has something genuine to inspect.
 */
function uploadTestFile(string $filename, string $mime, string $content = 'hello', bool $move = true): UploadedFileInterface
{
	$file = test()->createMock(UploadedFileInterface::class);
	$file->method('getClientFilename')->willReturn($filename);
	$file->method('getClientMediaType')->willReturn($mime);
	$file->method('getError')->willReturn(UPLOAD_ERR_OK);
	$file->method('getSize')->willReturn(strlen($content));
	$file->method('moveTo')->willReturnCallback(
		function (string $target) use ($content, $move): void {
			if ($move) {
				file_put_contents($target, $content);
			}
		}
	);

	return $file;
}

/** A real 16x16 JPEG, so finfo detects image/jpeg rather than text. */
function uploadTestJpeg(): string
{
	$img = imagecreatetruecolor(16, 16);
	ob_start();
	imagejpeg($img);
	$bytes = (string)ob_get_clean();
	imagedestroy($img);

	return $bytes;
}

/**
 * @param array<int,array{0:string,1:string,2:string,3:string,4:?string}> $saved
 */
function uploadAction(array &$saved, string $tmpdir, string $api = '/api'): UploadFileAction
{
	$saver = test()->createMock(UploadSaver::class);
	$saver->method('save')->willReturnCallback(
		function (string $collection, string $id, string $property, string $filepath, ?string $subpath) use (&$saved): string {
			$saved[] = [$collection, $id, $property, basename($filepath), $subpath];

			return $subpath === null
				? "$collection/$id/$property/" . basename($filepath)
				: "$collection/$id/$property/$subpath/" . basename($filepath);
		}
	);

	$config         = (new ReflectionClass(Config::class))->newInstanceWithoutConstructor();
	$config->tmpdir = $tmpdir;
	$config->api    = $api;

	return new UploadFileAction(new JsonRenderer(), $saver, $config, new FileUploadValidator());
}

/** @param array<string,mixed> $parsedBody */
function uploadInvoke(UploadFileAction $action, ?UploadedFileInterface $file, array $args, array $parsedBody = []): array
{
	$factory = new Psr17Factory();
	$request = $factory->createServerRequest('POST', '/api/upload');
	if ($file !== null) {
		$request = $request->withUploadedFiles(['file' => $file]);
	}
	if ($parsedBody !== []) {
		$request = $request->withParsedBody($parsedBody);
	}

	$response = $action($request, $factory->createResponse(), $args);

	return [
		'status' => $response->getStatusCode(),
		'body'   => json_decode((string)$response->getBody(), true) ?? [],
	];
}

beforeEach(function (): void {
	// Per-test tmpdir: the action writes into config->tmpdir, and a shared one
	// would collide between parallel workers.
	$this->tmpdir = sys_get_temp_dir() . '/tcms-upload-test-' . uniqid('', true);
	mkdir($this->tmpdir, 0700, true);
	$this->saved = [];
});

afterEach(function (): void {
	if (is_dir($this->tmpdir)) {
		array_map(unlink(...), glob($this->tmpdir . '/*') ?: []);
		rmdir($this->tmpdir);
	}
});

describe('UploadFileAction rejections', function (): void {
	it('returns 400 when the request carries no file', function (): void {
		$action = uploadAction($this->saved, $this->tmpdir);

		$out = uploadInvoke($action, null, ['collection' => 'blog', 'id' => 'post-1', 'property' => 'image']);

		expect($out['status'])->toBe(400);
		expect($out['body']['error'])->toBe('No file found for upload');
		expect($this->saved)->toBe([]);
	});

	it('refuses a dangerous extension and stores nothing', function (): void {
		// The headline security rule: executables and scripts never reach disk.
		$action = uploadAction($this->saved, $this->tmpdir);

		$out = uploadInvoke($action, uploadTestFile('shell.php', 'application/x-php', '<?php echo 1;'), [
			'collection' => 'blog', 'id' => 'post-1', 'property' => 'file',
		]);

		expect($out['status'])->toBe(400);
		expect($out['body']['error'])->toBe('File upload validation failed');
		expect(implode(' ', $out['body']['details']))->toContain("'.php' is not allowed");
		expect($this->saved)->toBe([]);
		expect(glob($this->tmpdir . '/*'))->toBe([]);
	});

	it('refuses every extension on the dangerous list, not just php', function (): void {
		$action = uploadAction($this->saved, $this->tmpdir);

		foreach (['evil.sh', 'evil.exe', 'evil.py', 'evil.jsp', 'evil.htaccess'] as $name) {
			$out = uploadInvoke($action, uploadTestFile($name, 'text/plain'), [
				'collection' => 'blog', 'id' => 'post-1', 'property' => 'file',
			]);
			expect($out['status'])->toBe(400, "expected $name to be rejected");
		}

		expect($this->saved)->toBe([]);
	});

	it('rejects the upload when the moved file is not on disk, and cleans up quietly', function (): void {
		// moveTo() silently doing nothing is the one way the content check can
		// fail with a null category. The cleanup unlink() is guarded against a
		// file that was never written, so this path must not raise a warning.
		$action = uploadAction($this->saved, $this->tmpdir);

		$out = uploadInvoke($action, uploadTestFile('photo.jpg', 'image/jpeg', 'x', move: false), [
			'collection' => 'blog', 'id' => 'post-1', 'property' => 'image',
		]);

		expect($out['status'])->toBe(400);
		expect($out['body']['error'])->toBe('File content validation failed');
		expect($out['body']['details'])->toContain('File does not exist');
		expect($this->saved)->toBe([]);
	});
});

describe('UploadFileAction filename handling', function (): void {
	it('accepts an unsafe filename but stores it under a sanitised name', function (): void {
		// 'Filename contains unsafe characters' is deliberately not fatal — the
		// action filters that one error out and uses the sanitised name.
		$action = uploadAction($this->saved, $this->tmpdir);

		$out = uploadInvoke($action, uploadTestFile('my photo (1).jpg', 'image/jpeg', uploadTestJpeg()), [
			'collection' => 'blog', 'id' => 'post-1', 'property' => 'image',
		]);

		expect($out['status'])->toBe(200);
		expect($this->saved)->toHaveCount(1);
		expect($this->saved[0][3])->toBe('my_photo__1_.jpg');
	});

	it('strips path components from the filename so an upload cannot escape tmpdir', function (): void {
		$action = uploadAction($this->saved, $this->tmpdir);

		$out = uploadInvoke($action, uploadTestFile('../../etc/passwd.jpg', 'image/jpeg', uploadTestJpeg()), [
			'collection' => 'blog', 'id' => 'post-1', 'property' => 'image',
		]);

		expect($out['status'])->toBe(200);
		expect($this->saved[0][3])->toBe('passwd.jpg');
		expect($this->saved[0][3])->not->toContain('..');
	});
});

describe('UploadFileAction link building', function (): void {
	it('serves an image through imageworks', function (): void {
		$action = uploadAction($this->saved, $this->tmpdir);

		$out = uploadInvoke($action, uploadTestFile('photo.jpg', 'image/jpeg', uploadTestJpeg()), [
			'collection' => 'blog', 'id' => 'post-1', 'property' => 'image',
		]);

		expect($out['status'])->toBe(200);
		expect($out['body']['link'])->toStartWith('/api/imageworks/upload/');
	});

	it('serves audio and video through stream, which supports range requests', function (): void {
		$action = uploadAction($this->saved, $this->tmpdir);

		foreach (['clip.mp4' => 'video/mp4', 'song.mp3' => 'audio/mpeg'] as $name => $mime) {
			$out = uploadInvoke($action, uploadTestFile($name, $mime), [
				'collection' => 'blog', 'id' => 'post-1', 'property' => 'media',
			]);
			expect($out['body']['link'])->toStartWith('/api/stream/upload/');
		}
	});

	it('serves anything else through download', function (): void {
		$action = uploadAction($this->saved, $this->tmpdir);

		$out = uploadInvoke($action, uploadTestFile('report.pdf', 'application/pdf'), [
			'collection' => 'blog', 'id' => 'post-1', 'property' => 'file',
		]);

		expect($out['body']['link'])->toStartWith('/api/download/upload/');
	});

	it('builds links from the api path only, not a full base url', function (): void {
		$action = uploadAction($this->saved, $this->tmpdir, api: 'https://example.test/api');

		$out = uploadInvoke($action, uploadTestFile('report.pdf', 'application/pdf'), [
			'collection' => 'blog', 'id' => 'post-1', 'property' => 'file',
		]);

		expect($out['body']['link'])->toStartWith('/api/download/upload/');
		expect($out['body']['link'])->not->toContain('example.test');
	});

	it('appends the parsed body to the link as a query string', function (): void {
		$action = uploadAction($this->saved, $this->tmpdir);

		$out = uploadInvoke(
			$action,
			uploadTestFile('report.pdf', 'application/pdf'),
			['collection' => 'blog', 'id' => 'post-1', 'property' => 'file'],
			['w' => '200', 'h' => '100']
		);

		expect($out['body']['link'])->toContain('?w=200&h=100');
	});
});

describe('UploadFileAction subpath handling', function (): void {
	it('passes a nested subpath through to the saver', function (): void {
		$action = uploadAction($this->saved, $this->tmpdir);

		uploadInvoke($action, uploadTestFile('photo.jpg', 'image/jpeg', uploadTestJpeg()), [
			'collection' => 'blog', 'id' => 'post-1', 'property' => 'mycard', 'path' => 'childprop',
		]);

		expect($this->saved[0][4])->toBe('childprop');
	});

	it('strips traversal segments out of the subpath', function (): void {
		// PathUtils::sanitizeSubpath removes '..' — without it a nested upload
		// could be written outside the object's own directory.
		$action = uploadAction($this->saved, $this->tmpdir);

		uploadInvoke($action, uploadTestFile('photo.jpg', 'image/jpeg', uploadTestJpeg()), [
			'collection' => 'blog', 'id' => 'post-1', 'property' => 'mycard', 'path' => '../../../etc',
		]);

		expect($this->saved[0][4])->not->toContain('..');
	});

	it('treats an empty subpath as no subpath', function (): void {
		$action = uploadAction($this->saved, $this->tmpdir);

		uploadInvoke($action, uploadTestFile('photo.jpg', 'image/jpeg', uploadTestJpeg()), [
			'collection' => 'blog', 'id' => 'post-1', 'property' => 'image', 'path' => '',
		]);

		expect($this->saved[0][4])->toBeNull();
	});
});
