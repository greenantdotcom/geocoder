<?php

$selected = false;

switch($_SERVER['SCRIPT_NAME'])
{
	case '/unresolved.php':
		$selected = 'unresolved';
	break;

	case '/lookup.php':
		$selected = 'lookup';
	break;

	case '/suggestions.php':
		$selected = 'suggestions';
	break;

	case '/resolved.php':
		$selected = 'resolved';
	break;

	case '/hotels.php':
		$selected = 'hotel';
	break;
}
?>


<html>
	<head>
		<title><?php echo $title; ?></title>

		<link href="/css/base.css" media="screen" rel="Stylesheet" type="text/css" />
	</head>

 	<body>

		<table id="top-nav">
		    <tr id="navtop">
				<td>
					<div id="logo"><img alt="Advanced Reservation Systems" src="/images/ares_logo.png" /></div>

					<div id="bannerText">Geocode Manager</div>
				</td>
		        <td align="right" valign="top" style="padding: 0; color: #666" class="ntu">
			        <ul id="top-tabs">
						<li><a href="/logout.php"><span>Log Out</span></a></li>
					</ul>
		        </td>
		     </tr>
		    <tr id="top-menu">
		        <td colspan="2" id="hd">
		            <ul>
		            	<li><a href="/lookup.php" <?php if($selected == 'lookup') { echo 'id="current"'; } ?>>Lookup Location</a></li>
		            	<li><a href="/hotels.php" <?php if($selected == 'hotel') { echo 'id="current"'; } ?>>Hotel Locations</a></li>
		            	<li><a href="/resolved.php" <?php if($selected == 'resolved') { echo 'id="current"'; } ?>>Resolved Locations</a></li>
		                <li><a href="/unresolved.php"  <?php if($selected == 'unresolved') { echo 'id="current"'; } ?>>Unresolved Locations</a></li>
		                <li><a href="/suggestions.php" <?php if($selected == 'suggestions') { echo 'id="current"'; } ?>>Locations With Suggestions</a></li>
		            </ul>
		        </td>
		    </tr>
		</table>