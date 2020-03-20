<?php
require('vendor/autoload.php');

$parser = new Mni\FrontYAML\Parser();

$dir = "/Users/joeworkman/Development/websites/docs.joeworkman.net/_content/rapidweaver/stacks";
$assetDir = "/Users/joeworkman/Development/websites/docs.joeworkman.net/assets/rapidweaver/stacks";
$collection = 'faqs';

$it = new \FilesystemIterator($dir, \FilesystemIterator::SKIP_DOTS);
foreach ($it as $dirinfo) {
    if (!$dirinfo->isDir()) {
        // only files
        continue;
    }
    $stack = $dirinfo->getBasename();

    $subdir = "$dir/$stack";

    $iit = new \FilesystemIterator($subdir, \FilesystemIterator::SKIP_DOTS);
    foreach ($iit as $fileinfo) {
        if (!$fileinfo->isFile()) {
            // only files
            continue;
        }

        $basename = $fileinfo->getBasename('.md');

        $is_md  = (strpos('.md', $fileinfo->getExtension()??'') !== 1); // only MD files
        $is_dot   = (strpos($basename, '.') === 0); // ignore dot files

        if ($is_md || $is_dot) {
            continue;
        }

        $id = "$stack-$basename";

        $document = $parser->parse(file_get_contents($fileinfo->getPathname()));

        $yaml = $document->getYAML();
        $html = $document->getContent();

        $imageDir = "$collection/$id/content/image";
        preg_match_all('/{{assets}}\/(\S+)"/', $html, $matches);

        if (!empty($matches[1])) {
            if (!file_exists($imageDir)) {
                mkdir($imageDir, 0777, true);
            }
            $images = $matches[1];
            foreach ($images as $image) {
                $original = "$assetDir/$stack/$image";
                copy($original, "$imageDir/$image");
            }
        }

        $html = str_replace('{{assets}}', "/tcms-data/$imageDir", $html);

        $faq = [
            'id'         => $id,
            'hide'       => false,
            'collection' => $collection,
            'category'   => ['General'],
            'products'   => [$stack],
            'content'    => $html,
            'title'      => $yaml['title']
        ];

        $jsonFile = "$collection/$id.json";
        file_put_contents($jsonFile, json_encode($faq));

        echo json_encode($faq, JSON_PRETTY_PRINT);
    }
}

// General FAQs

$dir = "/Users/joeworkman/Development/websites/docs.joeworkman.net/_content/general";
$assetDir = "/Users/joeworkman/Development/websites/docs.joeworkman.net/assets/general";

$iit = new \FilesystemIterator($dir, \FilesystemIterator::SKIP_DOTS);
foreach ($iit as $fileinfo) {
    if (!$fileinfo->isFile()) {
        // only files
        continue;
    }

    $basename = $fileinfo->getBasename('.md');

    $is_md  = (strpos('.md', $fileinfo->getExtension()??'') !== 1); // only MD files
    $is_dot   = (strpos($basename, '.') === 0); // ignore dot files

    if ($is_md || $is_dot) {
        continue;
    }

    $id = $basename;

    $document = $parser->parse(file_get_contents($fileinfo->getPathname()));

    $yaml = $document->getYAML();
    $html = $document->getContent();

    $imageDir = "$collection/$id/content/image";
    preg_match_all('/{{assets}}\/(\S+)"/', $html, $matches);

    if (!empty($matches[1])) {
        if (!file_exists($imageDir)) {
            mkdir($imageDir, 0777, true);
        }
        $images = $matches[1];
        foreach ($images as $image) {
            $original = "$assetDir/$image";
            copy($original, "$imageDir/$image");
        }
    }

    $html = str_replace('{{assets}}', "/tcms-data/$imageDir", $html);

    $faq = [
        'id'         => $id,
        'hide'       => false,
        'collection' => $collection,
        'category'   => ['General'],
        'products'   => ['general'],
        'content'    => $html,
        'title'      => $yaml['title']
    ];

    $jsonFile = "$collection/$id.json";
    file_put_contents($jsonFile, json_encode($faq));

    echo json_encode($faq, JSON_PRETTY_PRINT);
}