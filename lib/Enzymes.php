<?php

require_once 'Ando/Regex.php';

class Enzymes
{
	protected $templates_path;
	protected $new_content     = '';  // the content of the post, modified by Enzymes
	protected $sequence_output = '';  // the output of the pathway (sequence of enzymes)
	protected $enzyme_output   = '';  // the value of an enzyme (transclusion or execution expression)

	protected $post      = '';  // the post which the content belongs to
	protected $substrate = '';  // a custom field passed as input to an enzyme
	protected $merging   = '';  // the function used to merge the pathway and the enzyme
	protected $matches;         // array of matches for the current enzyme

	protected $post_key = null;
	protected $user_key = null;

	public function __construct() {
		$this->templates_path = WP_CONTENT_DIR . '/plugins/enzymes/templates/';

		$oneword = new Ando_Regex('(?:(?:\w|-|~)+)');
		$glue = new Ando_Regex('(?:\.|\:)');
		$quoted = new Ando_Regex('(?:=[^=\\\\]*(?:\\\\.[^=\\\\]*)*=)');
		$template = new Ando_Regex('(?:(?<tempType>\/|\\\\)(?<template>(?:[^\|])+))');
		$comment = new Ando_Regex('(?<comment>\/\*.*?\*\/)');
		$id1 = new Ando_Regex('(?:\d+|@(?:\w|-)+)');
		$id2 = new Ando_Regex('(?:~\w+)');
		$id = new Ando_Regex('(?:$id1$id2?|$id1?$id2|)');
		$id->interpolate(array(
			'id1' => $id1,
			'id2' => $id2,
		));
		$key = new Ando_Regex('(?:$quoted|$oneword)');
		$key->interpolate(array(
			'quoted'  => $quoted,
			'oneword' => $oneword,
		));
		$block = new Ando_Regex('(?:(?<id>$id)(?<glue>$glue)(?<key>$key)|(?<value>$quoted))');
		$block->interpolate(array(
			'id'     => $id,
			'glue'   => $glue,
			'key'    => $key,
			'quoted' => $quoted,
		));
		$substrate = new Ando_Regex('(?<sub_id>$id)(?<sub_glue>$glue)(?<sub_key>$key)|(?<sub_value>$quoted)');
		$substrate->interpolate(array(
			'id'     => $id,
			'glue'   => $glue,
			'key'    => $key,
			'quoted' => $quoted,
		));
		$sub_block = new Ando_Regex('(?<sub_block>\((?:$substrate)?\))');
		$sub_block->interpolate(array(
			'substrate' => $substrate,
		));
		$enzyme = new Ando_Regex('(?:$block$sub_block?$template?)');
		$enzyme->interpolate(array(
			'block'     => $block,
			'sub_block' => $sub_block,
			'template'  => $template,
		));
		//pathway = enzyme|enzyme|...|enzyme
		$rest = new Ando_Regex('(?:\|(?<rest>.+))');
		$pathway1 = new Ando_Regex('^$enzyme$rest?$');
		$pathway1->interpolate(array(
			'enzyme' => $enzyme,
			'rest'   => $rest,
		));
		$pathway2 = new Ando_Regex('^(?:\|$enzyme)+$');
		$pathway2->interpolate(array(
			'enzyme' => $enzyme,
		));
		$before = new Ando_Regex('(?<before>.*?)');
		$statement = new Ando_Regex('\{\[(?<statement>.*?)\]\}');
		$after = new Ando_Regex('(?<after>.*)');
		$content = new Ando_Regex('^$before$statement$after$', '@@s');
		$content->interpolate(array(
			'before'    => $before,
			'statement' => $statement,
			'after'     => $after,
		));
		$each = new Ando_Regex('/^(.+?)=>(.*(?:$glue).+)$/');
		$each->interpolate(array(
			'glue' => $glue,
		));
		$maybe_quoted = new Ando_Regex('(.*?)($quoted)|(.+)', '@@s');
		$maybe_quoted->interpolate(array(
			'quoted' => $quoted,
		));
		$escaped_quote = new Ando_Regex('\\\\=');
		$maybe_id = new Ando_Regex('(@[\w\-]+)?~(\w+)');
		$blank = new Ando_Regex('(?:\s|\xc2)+');

		// these need to be wrapped
		$this->e_maybe_id = $maybe_id->wrapper_set('@@');
		$this->e_each = $each->wrapper_set('@@');
		$this->e_quoted = $quoted->wrapper_set('@@');
		$this->e_escaped_quote = $escaped_quote->wrapper_set('@@');
		$this->e_blank = $blank->wrapper_set('@@');
		$this->e_comment = $comment->wrapper_set('@@');
		$this->e_pathway1 = $pathway1->wrapper_set('@@');
		$this->e_pathway2 = $pathway2->wrapper_set('@@');

		// these were already wrapped
		$this->e_content = $content;
		$this->e_maybe_quoted = $maybe_quoted;


		$this->post_key = array(
			'id'               => 'ID',

			'name'             => 'post_name',
			'password'         => 'post_password',
			'date'             => 'post_date',
			'date_gmt'         => 'post_date_gmt',
			'modified'         => 'post_modified',
			'modified_gmt'     => 'post_modified_gmt',
			'content'          => 'post_content',
			'content_filtered' => 'post_content_filtered',
			'title'            => 'post_title',
			'excerpt'          => 'post_excerpt',
			'status'           => 'post_status',
			'type'             => 'post_type',
			'parent'           => 'post_parent',
			'mime_type'        => 'post_mime_type',

			'comment_status'   => 'comment_status',
			'comment_count'    => 'comment_count',
			'ping_status'      => 'ping_status',
			'menu_order'       => 'menu_order',
			'to_ping'          => 'to_ping',
			'pinged'           => 'pinged',
			'guid'             => 'guid',
		);

		$this->user_key = array(
			'id'               => 'ID',

			'login'            => 'user_login',
			'pass'             => 'user_pass',
			'nicename'         => 'user_nicename',
			'email'            => 'user_email',
			'url'              => 'user_url',
			'registered'       => 'user_registered',
			'activation_key'   => 'user_activation_key',
			'status'           => 'user_status',

			'display_name'     => 'display_name',
		);
	}

	protected function default_pairs(&$actual, $expected) {
		$actual = array_merge($expected, $actual);
	}

	protected function apply_merging()
	{
		switch( $this->merging )
		{
			case '':
				break;
			case 'append':
				$this->enzyme_output = $this->sequence_output . $this->enzyme_output;
				break;
			case 'prepend':
				$this->enzyme_output = $this->enzyme_output . $this->sequence_output;
				break;
			default:
				if( is_callable( $this->merging ) )
				{
					$this->enzyme_output = call_user_func( $this->merging, $this );
				}
		}
	}

	protected function do_inclusion()
	{
		$this->default_pairs($this->matches, array(
			'template' => '',
		));
		if( '' != $this->matches['template'] )
		{
			$file_path = ABSPATH . $this->templates_path . $this->matches['template'];
			if( file_exists( $file_path ) )
			{
				ob_start();
				include( $file_path ); // include the requested template in the local scope
				$this->enzyme_output = ob_get_contents();
				ob_end_clean();
			}
		}
	}

	protected function do_evaluation()
	{
		if( '' != $this->enzyme_output )
		{
			ob_start();
			$this->enzyme_output = eval( $this->enzyme_output ); // evaluate the requested block in the local scope
			$this->sequence_output = ob_get_contents();
			ob_end_clean();
		}
	}

	protected function build_sequence_output()
	{
		$this->default_pairs($this->matches, array(
			'template' => '',
			'tempType' => '',
		));
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
		$this->sequence_output = $this->enzyme_output;
	}

	protected function elaborate( $substrate )
	{
		if( '' == $substrate )
		{
			return array( '' );
		}
		$substrate1 = explode( "\n", $substrate );
		foreach( $substrate1 as $i => $subject )
		{
			if( '' == $subject ) continue;
			if( preg_match( $this->e_each, $subject, $sub ) )
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

	protected function get_id( $id )
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

	protected function get_userdata( $user_id, $key )
	{
		//the get_userdata function in the WP API retrieves custom fields too, this one does not
		global $wpdb;
		return $wpdb->get_var("SELECT $key FROM $wpdb->users WHERE ID = $user_id");
	}

	protected function unquote( $key )
	{
		if( preg_match( $this->e_quoted, $key ) )
		{
			$key = substr( $key, 1, -1 );  // unwrap from quotes
			$key = preg_replace( $this->e_escaped_quote, '=', $key );  // unescape escaped quotes
		}
		return $key;
	}

	protected function post_value( $id, $glue, $key )
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

	protected function author_value( $id, $glue, $key )
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

	protected function item( $id, $glue, $key = null )
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
		if( preg_match( $this->e_maybe_id, $id, $sub ) )
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

	protected function catalyze( $matches )
	{
		$this->default_pairs($matches, array(
			'sub_block' => '',
			'value'     => '',
			'id'        => '',
			'glue'      => '',
			'key'       => '',
			'sub_value' => '',
			'sub_id'    => '',
			'sub_glue'  => '',
			'sub_key'   => '',
		));
		$this->matches = $matches;
		if( '' == $matches['sub_block'] )
		{
			// transclusion
			$this->substrate = '';
			$this->enzyme_output = '' == $matches['value']
				? $this->item( $matches['id'], $matches['glue'], $matches['key'] )
				: $this->unquote( $matches['value'] );
			$this->merging = 'append';
			$this->build_sequence_output();
		}
		else {
			// execution
			$this->substrate = '' == $matches['sub_value']
				? $this->item( $matches['sub_id'], $matches['sub_glue'], $matches['sub_key'] )
				: $this->unquote( $matches['sub_value'] );
			$this->enzyme_output = $this->item( $matches['id'], $matches['glue'], $matches['key'] );
			$this->merging = '';
			$this->do_evaluation();
			$this->build_sequence_output();
		}
	}

	protected function cb_strip_blanks( $matches )
	{
		list( $all, $before, $quoted, $after ) = $matches;
		$outside = $quoted ? $before : $after;
		//for some reason IE introduces C2 (hex) chars when writing a post
		$clean = preg_replace( $this->e_blank, '', $outside ) . $quoted;
		return $clean;
	}

	public function metabolism( $content, $post = '' )
	{
		if( ! preg_match( $this->e_content, $content, $matchesOut ) )
		{
			return $content;
		}
		$this->new_content = '';
		if( ! is_object( $post ) ) {
			global $post;
		}
		$this->post = $post;
		do
		{
			$this->default_pairs($matchesOut, array(
				'before'    => '',
				'statement' => '',
				'after'     => '',
			));
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
					$this->e_maybe_quoted, array( $this, 'cb_strip_blanks' ), $sttmnt
				);
				// erase comments
				$sttmnt = preg_replace( $this->e_comment, '', $sttmnt );

				if( ! preg_match( $this->e_pathway2, '|'.$sttmnt ) )
				{ //not a pathway
					$result = '{['.$matchesOut['statement'].']}';
				}
				else
				{ // process statement
					$this->sequence_output = '';
					$matchesIn['rest'] = $sttmnt;
					while( preg_match( $this->e_pathway1, $matchesIn['rest'], $matchesIn ) )
					{
						$this->default_pairs($matchesIn, array(
							'rest' => '',
						));
						$this->catalyze( $matchesIn );
					}
					$result = $this->sequence_output;
				}
			}
			$this->new_content .= $matchesOut['before'].$result;
			$after = $matchesOut['after']; // save tail, if next match fails
		}
		while( preg_match( $this->e_content, $matchesOut['after'], $matchesOut ) );

		return $this->new_content.$after;
	}
}
