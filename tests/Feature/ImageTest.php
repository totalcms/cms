<?php

use function Nekofar\Slim\Pest\postUpload;

beforeEach(function (): void {
    $this->setUpApp(bootstrap());
});

it('can upload an image', function (): void {
    $uri   = '/collections/image/myimage/image';
    $image = testDataDir() . 'test-image.jpg';

    postUpload($uri, $image, 'image/jpeg', 'image')
        ->assertOk();
})->only();

it('can replace an image and clear its cache', function (): void {
})->todo();

it('can upload an image to a gallery', function (): void {
})->todo();

it('can delete an image', function (): void {
})->todo();

it('can delete an image from gallery', function (): void {
})->todo();

it('can update info for an image', function (): void {
})->todo();

it('can update info for an image from gallery', function (): void {
})->todo();

it('can resize an image', function (): void {
})->todo();

it('can resize an image from gallery', function (): void {
})->todo();

it('can crop an image', function (): void {
})->todo();

it('can crop an image from gallery', function (): void {
})->todo();

it('can clear cache for an image', function (): void {
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

        // $this->app->handle($request);

        return $this->send($request, $headers);
    }

*/
