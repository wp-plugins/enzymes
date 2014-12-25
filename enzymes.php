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
require 'EnzymesPlugin.php';

//just comment the following line if you need to temporarily disable this plugin
$enzymesPlugin = new EnzymesPlugin();
