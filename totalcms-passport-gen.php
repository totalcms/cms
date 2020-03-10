<?php
	// the output of this script goes into the file /cms-data/passport.total
	$server = 'juice.fnr1.com';
	$encode = base64_encode($server).base64_encode('T0talCMSR0cks!');
	echo $encode.md5($encode);
?>