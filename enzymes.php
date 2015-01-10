<?php
/*
Plugin Name: Enzymes
Plugin URI: http://wordpress.org/extend/plugins/enzymes/
Description: Enrich your content with custom fields and properties.
Version: 3.0.0
Author: Andrea Ercolino
Author URI: http://andowebsit.es/blog/noteslog.com/
License: GPLv2 or later
*/


define('ENZYMES_FILENAME', __FILE__);
require_once 'src/EnzymesPlugin.php';

$enzymesPlugin = new EnzymesPlugin();



// ---------------------------------------------------------------------------------------------------------------------
// The following code will allow old version 2 enzymes sequences to work exactly as they did.
require_once 'enzymes.2/enzymes.php';
// ---------------------------------------------------------------------------------------------------------------------
