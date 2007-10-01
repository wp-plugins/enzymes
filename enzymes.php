<?php
/*
Plugin Name: Enzymes
Plugin URI: http://noteslog.com/enzymes/
Description: Transclude custom fields values wherever you need
Author: Andrea Ercolino
Version: 1.3
Author URI: http://noteslog.com
*/

class Enzymes {
	var $content   = '';  // the content of the post, modified by Enzymes
	var $post      = '';  // the post which the content belongs to
	var $product   = '';  // the output of the pathway
	var $substrate = '';  // a custom field passed as additional input to an enzyme
	var $result    = '';  // the output of the enzyme
	var $e;               // array of patterns of regular expressions
	var $quote     = '='; // the quote used for fields (texturize safe and single char)

	function Enzymes() {

		//basic expressions
		$this->e = array( 
			  'post'      => '\d*'
			, 'quoted'    => $this->quote.'[^'.$this->quote.'\\\\]*'
					.'(?:\\\\.[^'.$this->quote.'\\\\]*)*'.$this->quote
			, 'oneword'   => '[\w\-]+'
			, 'star'      => '\*'
			, 'template'  => '(?:(?P<tempType>\/|\\\\)(?P<template>(?:[^\|])+))'
			, 'comment'   => '(?P<comment>\/\*.*?\*\/)'
			, 'rest'      => '(?:\|(?P<rest>.+))'
			, 'before'    => '(?P<before>.*?)'
			, 'statement' => '\{\[(?P<statement>.*?)\]\}'
			, 'after'     => '(?P<after>.*)'
		);

		//complex expressions
		$this->e['content']   = 
				'^'.$this->e['before'].$this->e['statement'].$this->e['after'].'$';

		$this->e['field']     = // this is used twice, so cannot have names for its pieces
				$this->e['quoted'].'|'.$this->e['oneword'].'|'.$this->e['star'];

		$this->e['enzyme']    = 
				'(?P<enzymePost>'.$this->e['post'].')\.(?P<enzymeField>'.$this->e['field'].')';

		$this->e['substrate'] =
				'(?P<substratePost>'.$this->e['post'].')\.(?P<substrateField>'.$this->e['field'].')';

		$this->e['subBlock']  = 
				'(?P<subBlock>\((?:'.$this->e['substrate'].')?\))';

		$this->e['block']     = 
				$this->e['enzyme'].$this->e['subBlock'].'?'.$this->e['template'].'?';

		//  X = block|block|...|block
		//    if processing X, match /block|rest*/ against X
		$this->e['pathway']   = 
				'^'.$this->e['block'].$this->e['rest'].'?$';

		//    if admitting X, match /(|head)+/ against |X
		$this->e['pathway2']  = 
				'^(?:\|'.$this->e['block'].')+$';
	}

	function apply_template( $template ) {
		if( '' != $template ) {
			$file_path = ABSPATH . '/wp-content/' . $template;
			if( file_exists( $file_path ) ) {
				ob_start();
				include( $file_path ); // include the requested template in the local scope
				$this->result = ob_get_contents();
				ob_end_clean();
			}
		}
	}

	function apply_enzyme( $enzyme ) {
		if( $this->post ) 
			$post = $this->post;
		else
			global $post;
		if( '' != $enzyme ) {
			ob_start();
			eval( $enzyme ); // evaluate the requested enzyme in the local scope
			$this->result = ob_get_contents();
			ob_end_clean();
		}
	}

	function item( $id, $key ) {
		if( $this->post ) 
			$post = $this->post;
		else
			global $post;
		if( '' == $key ) return '';
		if( '*' == $key ) { 
			if( '' == $id ) return $post->post_content;
			$a_post = get_post( $id ); 
			return $a_post->post_content; 
		}
		if( '' == $id ) $id = $post->ID;
		if( preg_match( '/'.$this->e['quoted'].'/', $key ) ) {
			// unwrap from quotes
			$key = substr( $key, 1, -1 );
			// unescape escaped quotes
			$key = preg_replace( '/\\\\'.$this->quote.'/', $this->quote, $key );
		}
		return get_post_meta( $id, $key, true );
	}

	function catalyze( $matches ) {
		$enzyme = $this->item( $matches['enzymePost'], $matches['enzymeField'] );
		if( '' == $matches['subBlock'] ) { 
			// transclusion
			$this->substrate = '';
			$this->result = $enzyme;
			if( '' != $matches['template'] ) {
				if( '/' == $matches['tempType'] ) {
					// slash template
					$this->result = $this->product . $this->result;
					$this->apply_template( $matches['template'] );
				}
				else {
					// backslash template
					$this->apply_template( $matches['template'] );
					$this->result = $this->product . $this->result;
				}
			}
			else {
				$this->result = $this->product . $this->result;
			}
		}
		else { 
			// execution
			$this->substrate = $this->item( $matches['substratePost'], $matches['substrateField'] );
			if( $enzyme ) $this->apply_enzyme( $enzyme );
			else $this->result = '';
			$this->apply_template( $matches['template'] );
			if( '' != $matches['template'] ) {
				if( '/' == $matches['tempType'] ) {
					// slash template
				}
				else {
					// backslash template
					$this->result = $this->product . $this->result;
				}
			}
		}
		$this->product = $this->result;
	}

	function cb_strip_blanks( $matches ) {
		// I suspect the $matches array is a copy without names
		$before = $matches[1];
		$quoted = $matches[2];
		$after  = $matches[3];
		$blanks = '/(?:\s|\n)+/';
		if( '' != $quoted ) {
			return preg_replace( $blanks, '', $before ) . $quoted;
		}
		else {
			return preg_replace( $blanks, '', $after );
		}
	}

	function metabolism( $content, $post = '' ) {
		if( ! preg_match( '/'.$this->e['content'].'/s', $content, $matchesOut ) ) return $content;
		else {
//echo ":-)";
			$this->content = '';
			$this->post = $post;
			do {
				if( '{' == substr( $matchesOut['before'], -1 ) ) { //not to be processed
					$result = '['.$matchesOut['statement'].']}';
				}
				else {
					// erase tags
					$sttmnt = strip_tags( $matchesOut['statement'] );
					// erase blanks (except inside quoted strings)
					$sttmnt = preg_replace_callback( 
						'/(.*?)('.$this->e['quoted'].')|(.+)/s', array( $this, 'cb_strip_blanks' ), $sttmnt 
					);
					// erase comments
					$sttmnt = preg_replace( '/'.$this->e['comment'].'/', '', $sttmnt );

					if( ! preg_match( '/'.$this->e['pathway2'].'/', '|'.$sttmnt ) ) { //not a pathway
						$result = '{['.$matchesOut['statement'].']}';
					}
					else { // process statement
						$this->product = '';
						$matchesIn['rest'] = $sttmnt;
						while( preg_match( '/'.$this->e['pathway'].'/', $matchesIn['rest'], $matchesIn ) ) {
							$this->catalyze( $matchesIn );
						}
						$result = $this->product;
					}
				}
				$this->content .= $matchesOut['before'].$result;
				$after = $matchesOut['after']; // save tail, if next match fails
			} 
			while( preg_match( '/'.$this->e['content'].'/s', $matchesOut['after'], $matchesOut ) );

			return $this->content.$after;
		}
	}
}

$enzymes = new Enzymes();
add_filter( 'wp_title',        array( &$enzymes, 'metabolism' ), 10, 2 );
add_filter( 'the_title',       array( &$enzymes, 'metabolism' ), 10, 2 );
add_filter( 'the_title_rss',   array( &$enzymes, 'metabolism' ), 10, 2 );
add_filter( 'the_excerpt',     array( &$enzymes, 'metabolism' ), 10, 2 );
add_filter( 'the_excerpt_rss', array( &$enzymes, 'metabolism' ), 10, 2 );
add_filter( 'the_content',     array( &$enzymes, 'metabolism' ), 10, 2 );

function metabolize( $content, $post = '' ) {
	global $enzymes;
	echo $enzymes->metabolism( $content, $post );
}

?>