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
    public function visitSitemapIndex(SitemapIndex $sitemapIndex): void;

    public function visitSitemap(Sitemap $sitemap): void;

    public function visitUrlset(Urlset $urlset): void;

    public function visitUrl(Url $url): void;

    public function visitImageExtension(Image $image): void;

    public function visitLinkExtension(Link $link): void;

    public function visitMobileExtension(Mobile $mobile): void;

    public function visitNewsExtension(News $news): void;

    public function visitVideoExtension(Video $video): void;

    public function output(): string;
}
