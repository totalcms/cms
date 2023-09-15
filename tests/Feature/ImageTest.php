<?php

use function Nekofar\Slim\Pest\get;
use function Nekofar\Slim\Pest\postUpload;

beforeAll(function (): void {
    // TODO: Remove this once the file upload PR is merged
    $image = testDataDir() . 'test-image.jpg';
    $json  = testDataDir() . 'myimage.json';
    $dir   = cmsDataDir() . 'image/myimage/image';

    if (!file_exists($dir)) {
        mkdir($dir, 0777, true);
    }
    copy($json, cmsDataDir() . 'image/myimage.json');
    copy($image, cmsDataDir() . 'image/myimage/image/test-image.jpg');
});

beforeEach(function (): void {
    $this->setUpApp(bootstrap());
});

it('can upload an image', function (): void {
    $uri   = '/collections/image/myimage/image';
    $image = testDataDir() . 'test-image.jpg';

    postUpload($uri, $image, 'image/jpeg', 'image')
        ->assertOk();
    // ! https://discourse.slimframework.com/t/testing-file-uploads/5693
})->skip('awaiting implementation of PR');

it('can update info for an image', function (): void {
})->todo();

it('can get an image', function (): void {
    get('/imageworks/image/myimage/image.jpg')
        ->assertOk()
        ->assertHeader('Content-Type', 'image/jpeg');
});

it('can convert a jpeg to a png', function (): void {
    get('/imageworks/myimage.png')
        ->assertOk()
        ->assertHeader('Content-Type', 'image/png');
});

it('can resize an image', function (): void {
    $size = 300;
    $resp = get("/imageworks/image/myimage/image.jpg?w=$size")
        ->assertOk()
        ->assertHeader('Content-Type', 'image/jpeg');

    $imageCache = $resp->getBody()->getMetadata()['uri'];
    $imageWidth = getimagesize($imageCache)[0];

    expect($imageWidth)->toBe($size);
});

it('can resize an image from gallery', function (): void {
})->todo();

it('can crop an image', function (): void {
    $size = 300;
    $resp = get("/imageworks/image/myimage/image.jpg?h=$size&w=$size&crop=crop&fit=crop")
        ->assertOk()
        ->assertHeader('Content-Type', 'image/jpeg');

    $imageCache  = $resp->getBody()->getMetadata()['uri'];
    $imageWidth  = getimagesize($imageCache)[0];
    $imageHeight = getimagesize($imageCache)[1];

    expect($imageWidth)->toBe($size);
    expect($imageHeight)->toBe($size);
});

it('can replace an image and clear its cache', function (): void {
})->todo();

it('can delete an image', function (): void {
})->todo();

// Gallery
it('can upload an image to a gallery', function (): void {
})->todo();

it('can delete an image from gallery', function (): void {
})->todo();

it('can update info for an image from gallery', function (): void {
})->todo();

it('can clear cache for an image', function (): void {
})->todo();

it('can crop an image from gallery', function (): void {
})->todo();

it('can clear cache for an image from gallery', function (): void {
})->todo();

/*

- nekofar/pest-plugin-slim/src/Autoload.php

    function postUpload(string $uri, string $file, string $mime, array $data = [], array $headers = []): TestResponse
    {
        return test()->postUpload(...func_get_args());
    }

- nekofar/slim-test/src/Traits/HttpMethodsTestTrait.php

    final public function postUpload(string $uri, string $file, string $mime, string $name = 'file', array $data = [], array $headers = []): TestResponse
    {
        $request = $this->createFormRequest(RequestMethodInterface::METHOD_POST, $uri, $data);
        $request = $request->withHeader('Content-Type', 'multipart/form-data');

        $uploadFile = new UploadedFile($file, basename($file), $mime, filesize($file));
        $request    = $request->withUploadedFiles([$name => [$uploadFile]]);

        return $this->send($request, $headers);
    }

*/
