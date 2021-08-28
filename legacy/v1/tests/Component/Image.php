<?php

use TotalCMS\Component\Image;

class ImageTest extends PHPUnit_Framework_TestCase
{
    protected $portrait;
    protected $landscape;

	protected function setUp()
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
            'big' => array(
                'name' => 'big.jpg',
                'type' => 'image/jpeg',
                'size' => 2010327,
                'tmp_name' => __DIR__ . '/../../../phpunit-testdata/big.jpg',
                'error' => 0
            ),
            'totalcms' => array(
                'name' => 'totalcms.png',
                'type' => 'image/png',
                'size' => 54459,
                'tmp_name' => __DIR__ . '/../../../phpunit-testdata/totalcms.png',
                'error' => 0
            )
        );

        $thumb  = array('suffix' => 'th','scale' => 256);
        $square = array('suffix' => 'sq','scale' => 256,'resize' => 'crop');

        $this->portrait = new Image('portrait');
        $this->portrait->add_thumb($thumb);
        $this->portrait->add_thumb($square);

        $this->landscape = new Image('landscape');
        $this->landscape->add_thumb($thumb);
        $this->landscape->add_thumb($square);

        $this->big = new Image('big');
        $this->big->add_thumb($thumb);
        $this->big->add_thumb($square);

        $this->totalcms = new Image('totalcms',array('ext'=>'png'));
        $this->totalcms->add_thumb($square);
    }

	public function testSave()
	{
        // Save portrait images and verify they are all there
        $this->portrait->save_content($_FILES['portrait']);
        foreach ($this->portrait->get_images() as $image) {
            $this->assertFileExists($image);
        }
        $this->assertFileExists($this->portrait->get_alt_file());

        // Save landscape images and verify they are all there
        $this->landscape->save_content($_FILES['landscape']);
        foreach ($this->landscape->get_images() as $image) {
            $this->assertFileExists($image);
        }
        $this->assertFileExists($this->landscape->get_alt_file());

        // Save landscape images and verify they are all there
        $this->big->save_content($_FILES['big']);
        foreach ($this->big->get_images() as $image) {
            $this->assertFileExists($image);
        }
        $this->assertFileExists($this->big->get_alt_file());

        // Save landscape images and verify they are all there
        $this->totalcms->save_content($_FILES['totalcms']);
        foreach ($this->totalcms->get_images() as $image) {
            $this->assertFileExists($image);
        }
        $this->assertFileExists($this->totalcms->get_alt_file());
    }
    public function testAltUpdate()
    {
        $alt = 'This is my test alt tag';
        $this->landscape->update_alt($alt);
        $this->assertEquals($alt,$this->landscape->get_alt());
    }

    // Insert Tests to verify image sizes?

    /**
     * @group delete
     */
    public function testDelete()
    {
        $this->portrait->delete();
        foreach ($this->portrait->get_images() as $image) {
            $this->assertFileNotExists($image);
        }
        $this->assertFileNotExists($this->portrait->get_alt_file());

        $this->totalcms->delete();
        foreach ($this->totalcms->get_images() as $image) {
            $this->assertFileNotExists($image);
        }
        $this->assertFileNotExists($this->totalcms->get_alt_file());

        $this->landscape->delete();
        foreach ($this->landscape->get_images() as $image) {
            $this->assertFileNotExists($image);
        }
        $this->assertFileNotExists($this->landscape->get_alt_file());

        $this->big->delete();
        foreach ($this->big->get_images() as $image) {
            $this->assertFileNotExists($image);
        }
        $this->assertFileNotExists($this->big->get_alt_file());
    }
}