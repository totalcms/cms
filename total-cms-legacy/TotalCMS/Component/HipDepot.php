<?php
namespace TotalCMS\Component;

//---------------------------------------------------------------------------------
// Hipwig Depot class
//---------------------------------------------------------------------------------
class HipDepot extends Depot
{
	public function __construct($slug,$options=array())
	{
		// force type to be a depot
		$options['type'] = 'depot';

		parent::__construct($slug,$options);
	}

    public function save_content($contents,$options=array())
    {
		parent::save_content($contents,$options);

		// Get the absolute URL path to the target dir
		$cms_dir = $this->root_offset.str_replace($this->site_root,"",$this->target_dir);

		$response = new \StdClass;
        $response->link = "//".$_SERVER['HTTP_HOST']."$cms_dir/$this->target_file";

       	$this->log_message('Hipwig Depot Upload: '.$response->link);

        return $response;
    }
}
