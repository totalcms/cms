<?php

namespace App\Test\Traits;

use App\Domain\Storage\Filesystem;
use org\bovigo\vfs\vfsStream;
use Selective\TestTrait\Traits\ArrayTestTrait;
use Selective\TestTrait\Traits\ContainerTestTrait;
use Selective\TestTrait\Traits\HttpJsonTestTrait;
use Selective\TestTrait\Traits\HttpTestTrait;
use Selective\TestTrait\Traits\MockTestTrait;
use Slim\App;

/**
 * App Test Trait.
 */
trait AppTestTrait
{
    use ArrayTestTrait;
    use ContainerTestTrait;
    use HttpTestTrait;
    use HttpJsonTestTrait;
    use MockTestTrait;
    use RouteTestTrait;

    protected App $app;

    protected string $path;

    protected Filesystem $filesystem;

    /**
     * Before each test.
     *
     * @return void
     */
    protected function setUp(): void
    {
        $this->app = require __DIR__ . '/../../config/bootstrap.php';
        $this->setUpContainer($this->app->getContainer());

        // Mock filesystem
        $this->path = vfsStream::setup()->url();
        $this->setContainerValue(Filesystem::class, new Filesystem($this->path));
        $this->filesystem = $this->container->get(Filesystem::class);
    }
}
