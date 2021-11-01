<?php

namespace App\Action\ImageWorks;

use App\Domain\ImageWorks\Service\ImageFieldReader;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Slim\Exception\HttpNotFoundException;

final class ImageWorksImageFetchAction
{
    private ImageFieldReader $imageFieldReader;

    public function __construct(ImageFieldReader $imageFieldReader)
    {
        $this->imageFieldReader = $imageFieldReader;
    }

    /**
     * Action.
     *
     * @param ServerRequestInterface $request The request
     * @param ResponseInterface $response The response
     * @param array $args The arguments
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
        // Get image by field
        $collection = (string)$args['collection'];
        $id = (string)$args['id'];
        $property = (string)$args['property'];
        $queryParams = $request->getQueryParams();

        $image = $this->imageFieldReader->readImageByField($collection, $id, $property, $queryParams);

        if ($image === null) {
            throw new HttpNotFoundException($request, 'Image not found');
        }

        // @todo Create response with image here
        // ...

        return $response;
    }
}
