<?php
namespace TotalCMS\Component;

//---------------------------------------------------------------------------------
// Toggle class
//---------------------------------------------------------------------------------
class Toggle extends Component
{
	public function __construct($slug,$options=array())
	{
		$options = array_merge(array(
			'type' => 'toggle',
		), $options);

		parent::__construct($slug,$options);
	}

	public function status()
	{
    	// Get status
	    return file_exists($this->target_path());
	}

	public function toggle_on()
	{
    	// Create file
	    return fopen($this->target_path(),"w");
	}

	public function get_contents($format = false)
	{
	    return $this->status();
	}

    public function backup($prefix=false)
    {
    	// Don't backup Toggle
    	return;
    }

    public function save_content_to_cms($on,$options=array())
    {
    	// Toggle on/off
    	return $on === 'true' ? $this->toggle_on() : $this->delete();
    }
}