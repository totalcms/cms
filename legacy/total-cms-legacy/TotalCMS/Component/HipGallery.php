<?php
namespace TotalCMS\Component;

//---------------------------------------------------------------------------------
// Hipwig Gallery class
//---------------------------------------------------------------------------------
class HipGallery extends Gallery
{
	public function __construct($slug,$options=array())
	{
		// force type to be a gallery
		$options['type'] = 'gallery';

		parent::__construct($slug,$options);
	}

    public function save_content($contents,$options=array())
    {
		parent::save_content($contents,$options);

		// Get the absolute URL path to the target dir
		$cms_dir = $this->root_offset.str_replace($this->site_root,"",$this->target_dir);

		$response = new \StdClass;
        $response->link = "$cms_dir/$this->target_file";

        $this->log_message('Hipwig Gallery Upload: '.$response->link);

        return $response;
    }

    public function to_data($id=false)
    {
		parent::to_data($id);

		$response = array();
		foreach ($this->images as $image) {
			$obj = new \StdClass;
            $obj->url   = $this->root_offset.'/'.$image['img'];
            $obj->thumb = $this->root_offset.'/'.$image['thumb']['sq'];
            $obj->name  = $image['index'];
            array_push($response,$obj);
        }
		return $response;
    }
}
