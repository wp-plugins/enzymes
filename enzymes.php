<?php
/*
Plugin Name: Enzymes
Plugin URI: http://noteslog.com/enzymes/
Description: Retrieve properties and custom fields of posts, pages, and authors, right into the visual editor of posts and pages, and everywhere else.
Author: Andrea Ercolino
Version: 2.3
Author URI: http://noteslog.com
*/

class Enzymes 
{
	var $templates_path = 'wp-content/plugins/enzymes/templates/';
	var $content   = '';  // the content of the post, modified by Enzymes
	var $post      = '';  // the post which the content belongs to
	var $pathway   = '';  // the output of the pathway
	var $substrate = '';  // a custom field passed as input to an enzyme
	var $enzyme    = '';  // the value of an enzyme
	var $merging   = '';  // the function used to merge the pathway and the enzyme
	var $e;               // array of patterns of regular expressions
	var $matches;         // array of matches for the current enzyme
	
	var $post_key = null;
	var $user_key = null;
	
	function Enzymes() {
		$this->e = array();
		
		$this->e['oneword']   = '(?:(?:\w|-|~)+)';
		$this->e['glue']      = '(?:\.|\:)';
		$this->e['quoted']    = '(?:=[^=\\\\]*(?:\\\\.[^=\\\\]*)*=)';
		$this->e['template']  = '(?:(?P<tempType>\/|\\\\)(?P<template>(?:[^\|])+))';
		$this->e['comment']   = '(?P<comment>\/\*.*?\*\/)';

		$this->e['id1']       = '(?:\d+|@(?:\w|-)+)';
		$this->e['id2']       = '(?:~\w+)';
		$this->e['id']        = '(?:'.$this->e['id1'].$this->e['id2'].'?|'.$this->e['id1'].'?'.$this->e['id2'].'|)'; //'\d*';
		$this->e['key']       = '(?:'.$this->e['quoted'].'|'.$this->e['oneword'].')';
		$this->e['block']     = '(?:(?P<id>'.$this->e['id'].')(?P<glue>'.$this->e['glue'].')(?P<key>'.$this->e['key'].')';
		$this->e['block']    .= '|(?P<value>'.$this->e['quoted'].'))';
		$this->e['substrate'] = '(?P<sub_id>'.$this->e['id'].')(?P<sub_glue>'.$this->e['glue'].')(?P<sub_key>'.$this->e['key'].')';
		$this->e['substrate'].= '|(?P<sub_value>'.$this->e['quoted'].')';
		$this->e['sub_block'] = '(?P<sub_block>\((?:'.$this->e['substrate'].')?\))';
		$this->e['enzyme']    = '(?:'.$this->e['block'].$this->e['sub_block'].'?'.$this->e['template'].'?)';

		//pathway = enzyme|enzyme|...|enzyme
		$this->e['rest']      = '(?:\|(?P<rest>.+))';
		$this->e['pathway1']  = '^'.$this->e['enzyme'].$this->e['rest'].'?$';  //if processing pathway, match /enzyme|rest*/
		$this->e['pathway2']  = '^(?:\|'.$this->e['enzyme'].')+$';             //if accepting  pathway, match /(|head)+/     (against |pathway)

		$this->e['before']    = '(?P<before>.*?)';
		$this->e['statement'] = '\{\[(?P<statement>.*?)\]\}';
		$this->e['after']     = '(?P<after>.*)';
		$this->e['content']   = '^'.$this->e['before'].$this->e['statement'].$this->e['after'].'$';
		
		
		$this->post_key = array(
			  'id'               => 'ID'
			  
			, 'name'             => 'post_name'
			, 'password'         => 'post_password'
			, 'date'             => 'post_date'
			, 'date_gmt'         => 'post_date_gmt'
			, 'modified'         => 'post_modified'
			, 'modified_gmt'     => 'post_modified_gmt'
			, 'content'          => 'post_content'
			, 'content_filtered' => 'post_content_filtered'
			, 'title'            => 'post_title'
			, 'excerpt'          => 'post_excerpt'
			, 'status'           => 'post_status'
			, 'type'             => 'post_type'
			, 'parent'           => 'post_parent'
			, 'mime_type'        => 'post_mime_type'
			
			, 'comment_status'   => 'comment_status'
			, 'comment_count'    => 'comment_count'
			, 'ping_status'      => 'ping_status'
			, 'menu_order'       => 'menu_order'
			, 'to_ping'          => 'to_ping'
			, 'pinged'           => 'pinged'
			, 'guid'             => 'guid'
		);
		
		$this->user_key = array(
			  'id'               => 'ID'
			  
			, 'login'            => 'user_login'
			, 'pass'             => 'user_pass'
			, 'nicename'         => 'user_nicename'
			, 'email'            => 'user_email'
			, 'url'              => 'user_url'
			, 'registered'       => 'user_registered'
			, 'activation_key'   => 'user_activation_key'
			, 'status'           => 'user_status'
			
			, 'display_name'     => 'display_name'
		);
	}

	function apply_merging() 
    {
		switch( $this->merging ) 
        {
			case '':
				break;
			case 'append':
				$this->enzyme = $this->pathway . $this->enzyme;
				break;
			case 'prepend':
				$this->enzyme = $this->enzyme . $this->pathway;
				break;
			default:
				if( is_callable( $this->merging ) ) 
                {
					$this->enzyme = call_user_func( $this->merging, $this );
				}
		}
	}

	function do_inclusion() 
    {
		if( '' != $this->matches['template'] ) 
        {
			$file_path = ABSPATH . $this->templates_path . $this->matches['template'];
			if( file_exists( $file_path ) ) 
            {
				ob_start();
				include( $file_path ); // include the requested template in the local scope
				$this->enzyme = ob_get_contents();
				ob_end_clean();
			}
		}
	}

	function do_evaluation() 
    {
		if( '' != $this->enzyme ) 
        {
			ob_start();
			$this->enzyme = eval( $this->enzyme ); // evaluate the requested block in the local scope
			$this->pathway = ob_get_contents();
			ob_end_clean();
		}
	}

	function build_pathway() 
    {
		if( '' != $this->matches['template'] ) 
        {
			if( '/' == $this->matches['tempType'] ) 
            {
				// slash template
				$this->apply_merging();
				$this->do_inclusion();
			}
			else 
            {
				// backslash template
				$this->do_inclusion();
				$this->apply_merging();
			}
		}
		else 
        {
			$this->apply_merging();
		}
		$this->pathway = $this->enzyme;
	}
	
	function elaborate( $substrate ) 
    {
		if( '' == $substrate ) 
        {
			return array( '' );
		}
		$substrate1 = explode( "\n", $substrate );
		foreach( $substrate1 as $i => $subject ) 
        {
			if( '' == $subject ) continue;
			if( preg_match( "/^(.+?)=>(.*(?:{$this->e['glue']}).+)$/", $subject, $sub ) ) 
            {
				$key = trim( $sub[1] );
				$subject = trim( $sub[2] );
			}
			else 
            {
				$key = $i;
			}
			$enzymes = new Enzymes(); 
			$substrate2[$key] = $enzymes->metabolism( "{[$subject]}", $this->post );
		}
		return $substrate2;
	}

	function get_id( $id ) 
    {
		if( intval( $id ) ) 
        {
			$post_id = $id;
		}
		elseif( '' == $id ) 
        {
			$post_id = $this->post->ID;
		}
		else 
        {
			global $wpdb;
			$name = substr( $id, 1 );
			$post_id = $wpdb->get_var("SELECT ID FROM $wpdb->posts WHERE post_name = '$name'");
		}
		return $post_id;
	}
	
	function get_userdata( $user_id, $key ) 
    {
		//the get_userdata function in the WP API retrieves custom fields too, this one does not
		global $wpdb;
		return $wpdb->get_var("SELECT $key FROM $wpdb->users WHERE ID = $user_id");
	}
	
	function unquote( $key ) 
    {
		if( preg_match( '/'.$this->e['quoted'].'/', $key ) ) 
        {
			$key = substr( $key, 1, -1 );                // unwrap from quotes
			$key = preg_replace( '/\\\\=/', '=', $key ); // unescape escaped quotes
		}
		return $key;
	}
	
	function post_value( $id, $glue, $key ) 
    {
		$value = '';
		switch( $glue ) 
        {
			case '.':
				$key = $this->unquote( $key );
				//$value = get_post_meta( $id, $key, true );
                $value = get_post_meta( $id, $key, false );
                $count = count($value);
                if ($count > 1)
                {
                    $value = serialize($value);
                }
                elseif ($count == 1)
                {
                    $value = $value[0];
                }
                else
                {
                    $value = '';
                }
			break;
			
			case ':':
				switch( $key ) 
                {
					case 'id':
						$value = $id;
						break;

					default:
						$post = get_post( $id );
						$key  = $this->post_key[$key];
						if( isset( $key ) && ( '' !== $key ) ) 
                        {
							$value = $post->$key;
						}
				}
			break;
		}
		return $value;
	}
	
	function author_value( $id, $glue, $key ) 
    {
		$post = get_post( $id );
		$author_id = $post->post_author;
		switch( $glue ) 
        {
			case '.':
				$key = $this->unquote( $key );
				$value = get_user_meta( $author_id, $key, true );
			break;
			
			case ':':
				switch( $key ) 
                {
					case 'id':
						$value = $author_id;
					break;

					default:
						$key = $this->user_key[$key];
						if( isset( $key ) && ( '' !== $key ) ) 
                        {
							$value = $this->get_userdata( $author_id, $key );
						}						
				}
			break;
		}
		return $value;
	}
	
	function item( $id, $glue, $key = null ) 
    {
		if( is_null( $key ) ) 
        {
			$key = $glue;
			$glue = '.';
		}
		if( '' == $key ) 
        {
			return '';
		}
		$entity = 'post'; 
		if( preg_match( '/(@[\w\-]+)?~(\w+)/', $id, $sub ) ) 
        {
			$id = $sub[1];
			$entity = $sub[2]; 
		}
		$id = $this->get_id( $id );
		
		switch( $entity ) 
        {
			case 'post':
				$value = $this->post_value( $id, $glue, $key );
			break;
		
			case 'author':
				$value = $this->author_value( $id, $glue, $key );
			break;
			
			default:
				$value = '';
		}
		return $value;
	}

	function catalyze( $matches ) 
    {
		$this->matches = $matches;
		if( '' == $matches['sub_block'] ) 
        { 
			// transclusion
			$this->substrate = '';
			$this->enzyme = '' == $matches['value'] 
                ? $this->item( $matches['id'], $matches['glue'], $matches['key'] )
				: $this->unquote( $matches['value'] );
			$this->merging = 'append';
			$this->build_pathway();
		}
		else { 
			// execution
			$this->substrate = '' == $matches['sub_value'] 
                ? $this->item( $matches['sub_id'], $matches['sub_glue'], $matches['sub_key'] )
				: $this->unquote( $matches['sub_value'] );
			$this->enzyme = $this->item( $matches['id'], $matches['glue'], $matches['key'] );
			$this->merging = '';
			$this->do_evaluation();
			$this->build_pathway();
		}
	}

	function cb_strip_blanks( $matches ) 
    {
		list( $all, $before, $quoted, $after ) = $matches;
		$outside = $quoted ? $before : $after;
		//for some reason IE introduces C2 (hex) chars when writing a post
		$clean = preg_replace( '/(?:\s|\xc2)+/', '', $outside ) . $quoted;
		return $clean;
	}

	function metabolism( $content, $post = '' ) 
    {
		if( ! preg_match( '/'.$this->e['content'].'/s', $content, $matchesOut ) ) 
        {
            return $content;
        }
        $this->content = '';
        if( ! is_object( $post ) ) {
            global $post;
        }
        $this->post = $post;
        do 
        {
            if( '{' == substr( $matchesOut['before'], -1 ) ) 
            { //not to be processed
                $result = '['.$matchesOut['statement'].']}';
            }
            else 
            {
                $sttmnt = $matchesOut['statement'];
                // erase tags
                $sttmnt = strip_tags( $sttmnt );
                // erase blanks (except inside quoted strings)
                $sttmnt = preg_replace_callback( 
                    '/(.*?)('.$this->e['quoted'].')|(.+)/s', array( $this, 'cb_strip_blanks' ), $sttmnt 
                );
                // erase comments
                $sttmnt = preg_replace( '/'.$this->e['comment'].'/', '', $sttmnt );

                if( ! preg_match( '/'.$this->e['pathway2'].'/', '|'.$sttmnt ) ) 
                { //not a pathway
                    $result = '{['.$matchesOut['statement'].']}';
                }
                else 
                { // process statement
                    $this->pathway = '';
                    $matchesIn['rest'] = $sttmnt;
                    while( preg_match( '/'.$this->e['pathway1'].'/', $matchesIn['rest'], $matchesIn ) ) 
                    {
                        $this->catalyze( $matchesIn );
                    }
                    $result = $this->pathway;
                }
            }
            $this->content .= $matchesOut['before'].$result;
            $after = $matchesOut['after']; // save tail, if next match fails
        } 
        while( preg_match( '/'.$this->e['content'].'/s', $matchesOut['after'], $matchesOut ) );

        return $this->content.$after;
	}
}

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

?>