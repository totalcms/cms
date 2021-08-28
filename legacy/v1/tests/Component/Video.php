<?php

use TotalCMS\Component\Video;

class VideoTest extends PHPUnit_Framework_TestCase
{
    protected $object;
    protected $video;

	protected function setUp()
    {
        $this->object = new Video('phpunit');
        $this->video = 'https://vimeo.com/130172831';
    }

	public function testSave() {
		$this->object->save_content($this->video);
		$this->assertFileExists($this->object->target_path());
    }
	public function testContent() {
		$this->assertEquals($this->video,$this->object->get_contents());
    }

    /**
     * @group delete
     */
	public function testDelete() {
		$this->object->delete();
		$this->assertFileNotExists($this->object->target_path());
    }
}