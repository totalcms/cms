<?php

use TotalCMS\Component\Toggle;

class ToggleTest extends PHPUnit_Framework_TestCase
{
    protected $object;

	protected function setUp()
    {
        $this->object = new Toggle('toggletest');
    }

	public function testSave()
    {
		$this->object->save_content('true');
		$this->assertFileExists($this->object->target_path());
    }

    /**
     * @group delete
     */
	public function testDelete()
    {
		$this->object->delete();
		$this->assertFileNotExists($this->object->target_path());
    }
}