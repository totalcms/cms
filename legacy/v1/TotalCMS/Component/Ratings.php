<?php
namespace TotalCMS\Component;

//---------------------------------------------------------------------------------
// Ratings class
//---------------------------------------------------------------------------------
class Ratings extends Component
{
	public $max;
	public $score;
  public $ratings;
	public $icon;
	private $round;

	public function __construct($slug,$options=array())
	{
    	$options = array_merge(array(
			'type' => 'ratings',
			'icon' => 'fa-star',
			'max'  => 3
    	), $options);

        // Add the max number to slug so that ratings start fresh when using different max
        $slug = $slug.$options["max"];

		parent::__construct($slug,$options);

		$this->icon  = $options["icon"];
		$this->round = $this->get_round($options["icon"]);

		if (file_exists($this->target_path())) {
			$data = json_decode(file_get_contents($this->target_path()));
			$this->max     = $data->max;
            $this->score   = $data->score;
			$this->ratings = $data->ratings;
		}
		else {
			$this->max     = intval($options["max"]);
            $this->score   = 0;
			$this->ratings = array();
			for ($i=0; $i < $this->max; $i++) {
				$this->ratings[] = 0;
			}
		}
	}

    public function manual_score($score,$options=array())
    {
        $score = floatval($score);
        $this->score = $score;
        return $this->save_ratings_json($score);
    }

    public function save_content_to_cms($score,$options=array())
    {
    	$score = intval($score);
    	if ($score === 0) {
    		$this->log_error('Unable to save a rating:'.$score);
    	}
    	if ($score > $this->max) {
    		$this->log_error("Warning: Rating ($score) higher than defined max (".$this->max."). Using max instead.");
    		$score = $this->max;
    	}

    	$this->ratings[$score-1]++; // subtract 1 for the array index
        return $this->save_ratings_json();
    }

    public function change_score($old,$new)
    {
        $old = intval($old);
        $new = intval($new);

        $this->ratings[$old-1]--; // Decrement Old Rating
        if ($new !== 0) $this->ratings[$new-1]++; // Increment New Rating. Zero only removes old rating
        return $this->save_ratings_json();
    }

    private function save_ratings_json($score=false)
    {
        $json_data = json_encode(array(
            "max"     => $this->max,
            "score"   => $score ? $score : $this->calculate_score($this->round),
            "ratings" => $this->ratings
        ));
        file_put_contents($this->target_path(),$json_data, LOCK_EX);
        return $json_data;
    }

    private function get_round($icon)
    {
    	switch ($icon) {
	    	case 'fa-star':
	    		return 2; // fa-star supports half stars
	    	case 'fa-circle':
	    		return 2; // fa-circle supports half circle with fa-adjust
	    	case 'fa-battery-full':
	    		return 4; // fa-battery supports quarters
	    	default:
    			return 1; // everything else only has whole
    	}
    }

    public function total_ratings()
    {
        return array_sum($this->ratings);
    }

    private function calculate_score($round)
    {
    	$round = intval($round);
		// round to nearest 1.0, 0.5. 0.25
    	if (!in_array($round,[1,2,4])) {
    		$round = 1;
    	}

    	$total    = 0; // total stars received
    	$possible = 0; // possible max stars
    	$stars    = 0; // number of stars
    	foreach ($this->ratings as $score) {
    		$stars++;
    		$total += $score*$stars;
    		$possible += $score;
    	}
    	$possible *= $stars;

    	$percent = $possible === 0 ? 0 : $total/$possible; // no division by zero just incase
    	$this->score = round($percent * $stars * $round) / $round;
   		return $this->score;
    }

  //   public function generate_html($icon=false)
  //   {
  //   	if (!$icon) $icon = $this->icon; // default to object icon

  //   	// Icon Templates
  //   	$template = array(
  //   		"star" => array(
  //   			"full"  => "fa-star",
  //   			"empty" => "fa-star-o",
  //   			"half"  => "fa-star-half-o"
  //   		),
  //   		"circle" => array(
  //   			"full"  => "fa-circle",
  //   			"empty" => "fa-circle-o",
  //   			"half"  => "fa-adjust fa-flip-horizontal"
  //   		),
  //   		"heart" => array(
  //   			"full"  => "fa-heart",
  //   			"empty" => "fa-heart-o",
  //   			"half"  => "fa-heart"
  //   		)
  //   	);

  //   	// Icon Templates vs Single Icon Default
  //   	if (array_key_exists($icon,$template)) {
  //   		$full  = $template[$icon]["full"];
  //   		$empty = $template[$icon]["empty"];
  //   		$half  = $template[$icon]["half"];
  //   	}
  //   	else {
  //   		$full = $empty = $half = $icon; // set them all to use the icon
  //   	}

  //   	$html   = '';
  //   	$max    = $this->max;
  //   	$score = $this->score;

  //   	// Build the HTML
		// while ($max > 0) {
		// 	if ($score >= 1) {
		// 		$html .= "<i class='rating-full fa $full'></i>";
		// 	}
		// 	elseif($score <= 0) {
		// 		$html .= "<i class='rating-empty fa $empty'></i>";
		// 	}
		// 	else {
		// 		$html .= "<i class='rating-half fa $half'></i>";
		// 	}
		// 	$max--;
		// 	$score--;
		// }
		// return $html;
  //   }
}
