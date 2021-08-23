<?php
use Dynamics\Services\CollectionsService;
use Dynamics\Services\ImageWorksService;
use Dynamics\Services\TemplatesService;
use Dynamics\Services\ImportService;
use Dynamics\Services\UploadService;

//----------------------------------------------------------------------
// Import Route Map
//----------------------------------------------------------------------
$app->group('/import', function () {
    $this->post('/csv/{collection}', ImportService::class.':importCSV');
    $this->post('/link/{collection}', ImportService::class.':importLink');
    // $this->post('/rss/{collection}', ImportService::class.':importRSS');
});

//----------------------------------------------------------------------
// Templates Route Map
//----------------------------------------------------------------------
$app->group('/templates', function () {
    $this->get('/{type}/{template}', TemplatesService::class.':getTemplate');
});

//----------------------------------------------------------------------
// ImageWorks Route Map
//----------------------------------------------------------------------
$app->group('/imageworks', function () {
    // Allow indexing of images
    header_remove('X-Robots-Tag');

    // $this->get('/gallery/{id}/{file}', Service::class.':getGalleryImage');
    // $this->get('/image/{id}', Service::class.':getImageById');
    $this->get('/{collection}/{id}/{field}', ImageWorksService::class.':getImageByField');
    $this->get('/{collection}/{id}/{field}/{file}', ImageWorksService::class.':getImage');
});

//----------------------------------------------------------------------
// Styled Text Upload API Route Map
//----------------------------------------------------------------------
$app->group('/upload', function () {
    $this->get('/{collection}/{id}/{field}/{type}', UploadService::class.':getFiles');
    $this->post('/{collection}/{id}/{field}/{type}', UploadService::class.':saveFile');
    $this->delete('/{collection}/{id}/{field}/{type}/{file}', UploadService::class.':deleteFile');
});

//----------------------------------------------------------------------
// Dynamics Route Map
//----------------------------------------------------------------------
$app->group('/collections', function () {
    // Collection Schema
    $this->get('/{collection}/schema', CollectionsService::class .':getSchema');
    $this->post('/{collection}/schema', CollectionsService::class .':saveSchema');

    // Collection Index
    $this->put('/{collection}/index', CollectionsService::class .':rebuildIndex');

    // Collection Objects
    $this->get('', CollectionsService::class .':getCollections');
    $this->get('/{collection}', CollectionsService::class .':getIndex');
    $this->get('/{collection}/{id}', CollectionsService::class .':getObject');
    $this->get('/{collection}/{id}/exists', CollectionsService::class .':exists');

    $this->post('/{collection}', CollectionsService::class .':saveObject');
    $this->post('/{collection}/{id}/{field}', CollectionsService::class .':saveField');

    $this->put('/{collection}/{id}', CollectionsService::class .':updateObject');
    $this->put('/{collection}/{id}/{field}', CollectionsService::class .':updateField');
    $this->put('/{collection}/{id}/{field}/{file}', CollectionsService::class .':updateFile');

    $this->delete('/{collection}/{id}', CollectionsService::class .':deleteObject');
    $this->delete('/{collection}/{id}/{field}/{file}', CollectionsService::class .':deleteFile');
    // $this->delete('/{collection}/{id}/{field}', CollectionsService::class .':deleteObjectField');
    $this->delete('/{collection}/{id}/{field}/cache', CollectionsService::class .':clearFieldCache');
});


// Image
// /rw_common/plugins/stacks/dynamics/public.php/imageworks/dynamics/products/total-cms/icon/icon.png?w=128&h=128

// Gallery
// /rw_common/plugins/stacks/dynamics/public.php/collections/products/movingbox/screenshots/edit-mode.jpg
