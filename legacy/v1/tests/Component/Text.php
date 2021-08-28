<?php

use TotalCMS\Component\Text;

class TextTest extends PHPUnit_Framework_TestCase
{
    protected $object;
    protected $text;

	protected function setUp()
    {
        $this->object = new Text('phpunit');
        $this->text = 'This is a test';
    }

	public function testSave() {
		$this->object->save_content($this->text);
		$this->assertFileExists($this->object->target_path());
    }
	public function testContent() {
		$this->assertEquals($this->text,$this->object->get_contents());
    }

    /**
     * @group delete
     */
	public function testDelete() {
		$this->object->delete();
		$this->assertFileNotExists($this->object->target_path());
    }
}