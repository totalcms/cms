<?php

use TotalCMS\Component\Feed;

class FeedTest extends PHPUnit_Framework_TestCase
{
    protected static $feed_with_image;
    protected static $feed_text_only;
    protected static $post;
    protected static $alt;

	public static function setUpBeforeClass()
    {
        $_FILES = array(
            'image' => array(
                'name' => 'landscape.jpg',
                'type' => 'image/jpeg',
                'size' => 2231317,
                'tmp_name' => __DIR__ . '/../../../phpunit-testdata/landscape.jpg',
                'error' => 0
            ),
        );

        self::$alt = 'This is my test alt tag';
        self::$post = 'This is a test post for the news feed.';

        self::$feed_text_only = new Feed('phpunittext');
        self::$feed_with_image = new Feed('phpunitimage');
    }

    public function testSave()
    {
        // Save post text
        self::$feed_text_only->save_content(self::$post);
        $this->assertFileExists(self::$feed_text_only->target_path());

        // Verify contents
        $this->assertEquals(self::$post,self::$feed_text_only->get_contents());

        // Save image post
        self::$feed_with_image->save_content(self::$post,array(
            'image' => $_FILES['image'],
            'alt'   => self::$alt
        ));

        // Verify contents
        $this->assertEquals(self::$post,self::$feed_with_image->get_contents());

        // Verify Images exist
        $gallery = self::$feed_with_image->get_gallery();
        foreach ($gallery->get_images() as $image) {
            $this->assertFileExists($image);
        }
        // Verify Alt file exists
        $this->assertFileExists($gallery->get_alt_file());
    }

    public function testJson()
    {
        $post = array(
            "content"   => self::$post,
            "timestamp" => self::$feed_text_only->timestamp,
            "date"      => date('c',self::$feed_text_only->timestamp),
        );
        $posts = self::$feed_text_only->process_data();
        $this->assertEquals($posts[0],$post);

        $image_base = "cms-data/gallery/feed-phpunitimage/feed-phpunitimage-";
        $image = array(
            "content"   => self::$post,
            "timestamp" => self::$feed_with_image->timestamp,
            "date"      => date('c',self::$feed_text_only->timestamp),
            "alt"       => self::$alt,
            "img"       => $image_base.self::$feed_with_image->timestamp.".jpg",
            "thumb"     => array(
                "th"    => $image_base.self::$feed_with_image->timestamp."-th.jpg",
                "sq"    => $image_base.self::$feed_with_image->timestamp."-sq.jpg"
            )
        );
        $images = self::$feed_with_image->process_data();
        $this->assertEquals($images[0],$image);
    }

    /**
     * @group delete
     */
    public function testDelete() {
        // Delete text post and verify
        self::$feed_text_only->delete();
        $this->assertFileNotExists(self::$feed_text_only->target_path());

        // Delete image post
        self::$feed_with_image->delete();

        // Verify text does not exist
        $this->assertFileNotExists(self::$feed_with_image->target_path());

        // Verify images and alt do not exist
        $gallery = self::$feed_with_image->get_gallery();
        foreach ($gallery->get_images() as $image) {
            $this->assertFileNotExists($image);
        }
        $this->assertFileNotExists($gallery->get_alt_file());
    }

}