<?php

namespace TotalCMS\Action\ImageWorks;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Slim\Exception\HttpNotFoundException;
use TotalCMS\Domain\ImageWorks\Service\ImageGenerator;

final class ImageWorksGalleryFetchAction
{
    public function __construct(private ImageGenerator $imageGenerator)
    {
    }

    /**
     * Action.
     *
     * @param ServerRequestInterface $request
     * @param ResponseInterface $response
     * @param array<string,string> $args The arguments
     *
     * @throws HttpNotFoundException
     *
     * @return ResponseInterface
     */
    public function __invoke(
        ServerRequestInterface $request,
        ResponseInterface $response,
        array $args
    ): ResponseInterface {
        $collection  = $args['collection'] ?? 'gallery';
        $id          = $args['id'];
        $property    = $args['property'] ?? 'gallery';
        $filename    = $args['filename'];
        $queryParams = $request->getQueryParams();

        $queryParams['fm'] = $args['format'];

        try {
            $image = $this->imageGenerator->generateGalleryImage($collection, $id, $property, $filename, $queryParams);
        } catch (\Exception $e) {
            throw new HttpNotFoundException($request, 'Image not found:' . $e->getMessage());
        }

        return $image;
    }
}
