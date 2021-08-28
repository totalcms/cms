<?php

use TotalCMS\Component\Depot;

class DepotTest extends PHPUnit_Framework_TestCase
{
    protected static $depot;

    public static function setUpBeforeClass()
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
        self::$depot = new Depot('phpunit',array('filename'=>$_FILES['file']['name']));
    }

    public function testSave()
    {
        $this->assertFileExists(self::$depot->save_content($_FILES['file']));
    }

    public function testJson()
    {
        $verify = array("totalcms.png");
        $files = self::$depot->process_data();
        $this->assertEquals($files,$verify);
    }

    /**
     * @group delete
     */
    public function testDelete()
    {
        self::$depot->delete();
        $this->assertFileNotExists(self::$depot->target_path());
    }
}