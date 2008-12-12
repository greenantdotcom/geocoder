<?php

session_start();

if(!isset($_SESSION['authenticated']) and $_SERVER['SCRIPT_NAME'] != '/login.php')
{
	header('Location: /login.php');

	exit;
}