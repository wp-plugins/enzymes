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

//		$this->e = array();

		// oneword: '(?:(?:\w|-|~)+)';
		$oneword = Ando_Regex::def('(?:(?:\w|-|~)+)');

		// glue: '(?:\.|\:)';
		$glue = Ando_Regex::def('(?:\.|\:)');
		
		// quoted: '(?:=[^=\\\\]*(?:\\\\.[^=\\\\]*)*=)';
		$quoted = Ando_Regex::def('(?:=[^=\\\\]*(?:\\\\.[^=\\\\]*)*=)');
		
		// template: '(?:(?<tempType>\/|\\\\)(?<template>(?:[^\|])+))';
		$template = Ando_Regex::def('(?:(?<tempType>\/|\\\\)(?<template>(?:[^\|])+))');
		
		// comment: '(?<comment>\/\*.*?\*\/)';
		$comment = Ando_Regex::def('(?<comment>\/\*.*?\*\/)');

		// id1: '(?:\d+|@(?:\w|-)+)';
		$id1 = Ando_Regex::def('(?:\d+|@(?:\w|-)+)');
		// id2: '(?:~\w+)';
		$id2 = Ando_Regex::def('(?:~\w+)');
		// id: '(?:'.$this->e['id1'].$this->e['id2'].'?|'.$this->e['id1'].'?'.$this->e['id2'].'|)'; //'\d*';
		$id = Ando_Regex::def('(?:$id1$id2?|$id1?$id2|)')->interpolate(array(
			'id1' => $id1,
			'id2' => $id2,
		));

		// key: '(?:'.$this->e['quoted'].'|'.$this->e['oneword'].')';
		$key = Ando_Regex::def('(?:$quoted|$oneword)')->interpolate(array(
			'quoted'  => $quoted,
			'oneword' => $oneword,
		));

		// block: '(?:(?<id>'.$this->e['id'].')(?<glue>'.$this->e['glue'].')(?<key>'.$this->e['key'].')|(?<value>'.$this->e['quoted'].'))';
		$block = Ando_Regex::def('(?:(?<id>$id)(?<glue>$glue)(?<key>$key)|(?<value>$quoted))')->interpolate(array(
			'id'     => $id,
			'glue'   => $glue,
			'key'    => $key,
			'quoted' => $quoted,
		));

		// substrate: '(?<sub_id>'.$this->e['id'].')(?<sub_glue>'.$this->e['glue'].')(?<sub_key>'.$this->e['key'].')|(?<sub_value>'.$this->e['quoted'].')';
		$substrate = Ando_Regex::def('(?<sub_id>$id)(?<sub_glue>$glue)(?<sub_key>$key)|(?<sub_value>$quoted)')->interpolate(array(
			'id'     => $id,
			'glue'   => $glue,
			'key'    => $key,
			'quoted' => $quoted,
		));

		// sub_block: '(?<sub_block>\((?:$substrate)?\))';
		$sub_block = Ando_Regex::def('(?<sub_block>\((?:$substrate)?\))')->interpolate(array(
			'substrate' => $substrate,
		));

		// enzyme: '(?:'.$this->e['block'].$this->e['sub_block'].'?$template?)';
		$enzyme = Ando_Regex::def('(?:$block$sub_block?$template?)')->interpolate(array(
			'block'     => $block,
			'sub_block' => $sub_block,
			'template'  => $template,
		));
		//pathway = enzyme|enzyme|...|enzyme
		// rest: '(?:\|(?<rest>.+))';
		$rest = Ando_Regex::def('(?:\|(?<rest>.+))');
		// pathway1: '^'.$this->e['enzyme'].$this->e['rest'].'?$';  //if processing pathway, match /enzyme|rest*/
		$pathway1 = Ando_Regex::def('^$enzyme$rest?$')->interpolate(array(
			'enzyme' => $enzyme,
			'rest'   => $rest,
		));
		// pathway2: '^(?:\|$enzyme)+$';             //if accepting  pathway, match /(|head)+/     (against |pathway)
		$pathway2 = Ando_Regex::def('^(?:\|$enzyme)+$')->interpolate(array(
			'enzyme' => $enzyme,
		));

		// before: '(?<before>.*?)';
		$before = Ando_Regex::def('(?<before>.*?)');
		// statement: '\{\[(?<statement>.*?)\]\}';
		$statement = Ando_Regex::def('\{\[(?<statement>.*?)\]\}');
		// after: '(?<after>.*)';
		$after = Ando_Regex::def('(?<after>.*)');
		// content: '^'.$this->e['before'].$this->e['statement'].$this->e['after'].'$';
		$content = Ando_Regex::def('^$before$statement$after$', '@@s')->interpolate(array(
			'before'    => $before,
			'statement' => $statement,
			'after'     => $after,
		));

		$each = Ando_Regex::def('/^(.+?)=>(.*(?:$glue).+)$/')->interpolate(array(
			'glue' => $glue,
		));
		$maybe_quoted = Ando_Regex::def('(.*?)($quoted)|(.+)', '@@s')->interpolate(array(
			'quoted' => $quoted,
		));
		$escaped_quote = Ando_Regex::def('\\\\=');
		$maybe_id = Ando_Regex::def('(@[\w\-]+)?~(\w+)');
		$blank = Ando_Regex::def('(?:\s|\xc2)+');


		$this->e_maybe_id = $maybe_id;
		$this->e_each = $each;
		$this->e_quoted = $quoted;
		$this->e_content = $content;
		$this->e_maybe_quoted = $maybe_quoted;
		$this->e_escaped_quote = $escaped_quote;
		$this->e_blank = $blank;
		$this->e_comment = $comment;
		$this->e_pathway1 = $pathway1;
		$this->e_pathway2 = $pathway2;

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
