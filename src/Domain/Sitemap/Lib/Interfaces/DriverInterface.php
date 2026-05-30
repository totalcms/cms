<?php declare(strict_types=1);

namespace TotalCMS\Domain\Sitemap\Lib\Interfaces;

use TotalCMS\Domain\Sitemap\Lib\Extensions\Image;
use TotalCMS\Domain\Sitemap\Lib\Extensions\Link;
use TotalCMS\Domain\Sitemap\Lib\Extensions\Mobile;
use TotalCMS\Domain\Sitemap\Lib\Extensions\News;
use TotalCMS\Domain\Sitemap\Lib\Extensions\Video;
use TotalCMS\Domain\Sitemap\Lib\Sitemap;
use TotalCMS\Domain\Sitemap\Lib\SitemapIndex;
use TotalCMS\Domain\Sitemap\Lib\Url;
use TotalCMS\Domain\Sitemap\Lib\Urlset;

interface DriverInterface
{
    public function visitSitemapIndex(SitemapIndex $sitemapIndex);

    public function visitSitemap(Sitemap $sitemap);

    public function visitUrlset(Urlset $urlset);

    public function visitUrl(Url $url);

    public function visitImageExtension(Image $image);

    public function visitLinkExtension(Link $link);

    public function visitMobileExtension(Mobile $mobile);

    public function visitNewsExtension(News $news);

    public function visitVideoExtension(Video $video);

    public function output(): string;
}
