<?php
ini_set('display_errors', 'on');

error_reporting(2147483647);

require_once('session.php');

require_once('model.php');

require_once('helpers.php');

require_once('geocoder.php');

$permittedUsers = array
(
	'robert' => 'r0b3rt5P@55w0rol',
	'mark'   => 'changeme'
);