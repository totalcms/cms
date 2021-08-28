<?php

use TotalCMS\Component\File;

class FileTest extends PHPUnit_Framework_TestCase
{
    protected $object;

	protected function setUp()
    {
        $_FILES = array(
            'file' => array(
                'name' => 'totalcms.png',
                'type' => 'image/png',
                'size' => 54459,
                'tmp_name' => __DIR__ . '/../../../phpunit-testdata/totalcms.png',
                'error' => 0
            )
        );
        $this->object = new File('phpunit',array('ext'=>'png'));
    }

	public function testSave()
	{
        $this->assertFileExists($this->object->save_content($_FILES['file']));
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