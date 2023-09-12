<?php

use function Nekofar\Slim\Pest\delete;
use function Nekofar\Slim\Pest\get;
use function Nekofar\Slim\Pest\postJson;

beforeEach(function (): void {
    $this->setUpApp(bootstrap());
});

function templateTestData(): string
{
    return file_get_contents(testData('new-template.twig'));
}

it('saves a new template', function (): void {
    $template = templateTestData();
    $id       = 'new-template';
    $verify   = 'Test Template';

    // ! I had to add a hack to the TemplateSaveAction to allow for testing.
    postJson("/templates/{$id}", [$template])
        ->assertOk()
        ->assertSee($verify);

    $this->assertFileExists(templatePath($id));
});

it('cannot save a built-in template', function (): void {
    $id = 'form';

    // ! I had to add a hack to the TemplateSaveAction to allow for testing.
    postJson("/templates/{$id}", ['dummy data'])->assertBadRequest();

    $this->assertFileDoesNotExist(templatePath($id));
});

it('fetches a template', function (): void {
    $id     = 'new-template';
    $verify = 'Test Template';

    get("/templates/{$id}")
        ->assertOk()
        ->assertSee($verify);
});

it('fetches a built-in template', function (): void {
    $files = glob(__DIR__ . '/../../templates/*.twig');

    foreach ($files as $file) {
        $id     = basename($file, '.twig');
        $verify = file_get_contents($file);
        get("/templates/{$id}")
            ->assertOk()
            ->assertSee($verify);
    }
});

it('checks if a template exists', function (): void {
    head('/templates')
        ->assertOk();
});

it('fetches a list of all templates', function (): void {
    get('/templates')
        ->assertOk();
});

it('can delete a template', function (): void {
    $id = 'new-template';

    $this->assertFileExists(templatePath($id));

    delete("/templates/{$id}")->assertOk();

    $this->assertFileDoesNotExist(templatePath($id));
});
