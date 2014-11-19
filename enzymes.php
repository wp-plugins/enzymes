<?php
/*
Plugin Name: Enzymes
Plugin URI: http://noteslog.com/enzymes/
Description: Retrieve properties and custom fields of posts, pages, and authors, right into the visual editor of posts and pages, and everywhere else.
Author: Andrea Ercolino
Version: 2.3
Author URI: http://noteslog.com
*/

require_once 'lib/Enzymes.php';

$enzymes = new Enzymes();
add_filter( 'wp_title',        array( &$enzymes, 'metabolism' ), 10, 2 );
add_filter( 'the_title',       array( &$enzymes, 'metabolism' ), 10, 2 );
add_filter( 'the_title_rss',   array( &$enzymes, 'metabolism' ), 10, 2 );
add_filter( 'the_excerpt',     array( &$enzymes, 'metabolism' ), 10, 2 );
add_filter( 'the_excerpt_rss', array( &$enzymes, 'metabolism' ), 10, 2 );
add_filter( 'the_content',     array( &$enzymes, 'metabolism' ), 10, 2 );

function metabolize( $content, $post = '' ) 
{
	global $enzymes;
	echo $enzymes->metabolism( $content, $post );
}
