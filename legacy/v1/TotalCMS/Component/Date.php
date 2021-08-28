<?php
namespace TotalCMS\Component;

//---------------------------------------------------------------------------------
// DATE class
//---------------------------------------------------------------------------------
class Date extends Component
{
	public $timestamp;

	public function __construct($slug,$options=array())
	{
    	$options = array_merge(array(
			'type'   => 'date',
			'timestamp' => time(),
    	), $options);

		parent::__construct($slug,$options);
	}

    public function save_content_to_cms($timestamp=false,$options=array())
    {
    	if (!$timestamp) $timestamp = $this->timestamp;
    	return file_put_contents($this->target_path(),$timestamp, LOCK_EX);
    }
}
