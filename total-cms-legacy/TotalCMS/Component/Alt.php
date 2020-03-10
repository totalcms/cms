<?php
namespace TotalCMS\Component;

//---------------------------------------------------------------------------------
// ALT Text class
//---------------------------------------------------------------------------------
class Alt extends Component
{
	public function __construct($slug,$options=array())
	{
		$options = array_merge(array(
			'type'     => 'image',
			'set'      => false,
			'filename' => false
		), $options);

		// $this->set = ($options['type'] === 'gallery');
		parent::__construct($slug,$options);

		$this->not_found = '';
	}
}
