<?php
	$file = isset($_GET['file']) ?
	            ($_GET['file'] == "upgrade-php" ?
	                "files/".$_GET['file'].".html" :
	                "files/includes/".str_replace("..", "-", $_GET['file'])) :
	            "files/includes/common-php.html" ;

	if (!file_exists($file))
		exit("Invalid file: ".$file);

	include $file;
