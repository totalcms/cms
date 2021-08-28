<?php

use TotalCMS\Component\Gallery;

class GalleryTest extends PHPUnit_Framework_TestCase
{
    protected static $portrait;
    protected static $landscape;
    protected static $alt;

	public static function setUpBeforeClass()
    {
        $_FILES = array(
            'portrait' => array(
                'name' => 'portrait.jpg',
                'type' => 'image/jpeg',
                'size' => 1615238,
                'tmp_name' => __DIR__ . '/../../../phpunit-testdata/portrait.jpg',
                'error' => 0
            ),
            'landscape' => array(
                'name' => 'landscape.jpg',
                'type' => 'image/jpeg',
                'size' => 2231317,
                'tmp_name' => __DIR__ . '/../../../phpunit-testdata/landscape.jpg',
                'error' => 0
            ),
        );

        $thumb  = array('suffix' => 'th','scale' => 256);
        $square = array('suffix' => 'sq','scale' => 256,'resize' => 'crop');

        self::$portrait = new Gallery('phpunit');
        self::$portrait->add_thumb($thumb);
        self::$portrait->add_thumb($square);

        self::$alt = 'This is my test alt tag';
    }

    public function testSave()
    {
        // Save portrait images and verify they are all there
        self::$portrait->save_content($_FILES['portrait'],array(
            'alt' => self::$alt
        ));
        foreach (self::$portrait->get_images() as $image) {
            $this->assertFileExists($image);
        }
        $this->assertFileExists(self::$portrait->get_alt_file());
    }
    public function testAltUpdate()
    {
        self::$portrait->update_alt(self::$alt);
        $this->assertEquals(self::$alt,self::$portrait->get_alt());
    }

    // Insert Tests to verify image sizes?

    public function testJson()
    {
        $verify = array(
            "img"    => "cms-data/gallery/phpunit/phpunit-1.jpg",
            "alt"    => "This is my test alt tag",
            "thumb"  => array(
                "th" => "cms-data/gallery/phpunit/phpunit-1-th.jpg",
                "sq" => "cms-data/gallery/phpunit/phpunit-1-sq.jpg"
            ),
            "index"  => "1"
        );
        $images = self::$portrait->process_data();
        $this->assertEquals($images[0],$verify);
    }

    /**
     * @group delete
     */
    public function testDelete()
    {
        self::$portrait->delete();

        foreach (self::$portrait->get_images() as $image) {
            $this->assertFileNotExists($image);
        }
        $this->assertFileNotExists(self::$portrait->get_alt_file());
    }
}