<?php 
if( ! function_exists( 'format_code' ) ) {

	function snippet_wrap( $class, $code ) {
		return '' == $code ? '' : '<code class="'.$class.'">'.htmlentities( $code ).'</code>';
	}

	function snippet_php( $open, $code, $close ) {
		return snippet_wrap( 'html', $open ).snippet_wrap( 'php', $code ).snippet_wrap( 'html', $close );
	}

	function php_inside_html( $matches ) {
		list( $all, $before, $open, $code, $close ) = $matches;

		if( '' == $open ) 
			return snippet_wrap( 'html', $all );
		else 
			return snippet_wrap( 'html', $before ).snippet_php( $open, $code, $close );
	}

	function php_inside_css( $matches ) {
		list( $all, $before, $open, $code, $close ) = $matches;

		if( '' == $open ) 
			return snippet_wrap( 'css', $all );
		else 
			return snippet_wrap( 'css', $before ).snippet_php( $open, $code, $close );
	}

	function php_inside_javascript( $matches ) {
		list( $all, $before, $open, $code, $close ) = $matches;

		if( '' == $open ) 
			return snippet_wrap( 'javascript', $all );
		else 
			return snippet_wrap( 'javascript', $before ).snippet_php( $open, $code, $close );
	}

	function php_inside( $class, $code ) {
		return preg_replace_callback( '/(?:(.*?)(\<\?php\s)(.*?)(\?\>))|(?:.+)/si', "php_inside_$class", $code );
	}

	function snippet_web( $class, $open, $code, $close ) {
		return snippet_wrap( 'html', $open ).php_inside( $class, $code ).snippet_wrap( 'html', $close );
	}

	function format_code( $matches ) {
		list( $all, $before, $open, $tag, $code, $close ) = $matches;

		if( '' == $open ) {
			return php_inside( 'html', $all );
		}
		else {
			switch( $tag ) {
				case 'script': $class = 'javascript'; break;
				case 'style' : $class = 'css';        break;
				default      : $class = 'html';
			}
			return php_inside( 'html', $before ).snippet_web( $class, $open, $code, $close );
		}
	}

	function format_clean( $out ) {
		do {
			$len = strlen( $out );
			$out = preg_replace( '/(\<code class="(\w+)"\>[^<]*)\<\/code\>\<code class="\2"\>/is', '$1', $out );
		} while( $len != strlen( $out ) );
		return $out;
	}
}

?>
<pre class="chili-<?php 
	$tmp = preg_split( '/(?:\r?\n)+/', $this->enzyme );
	if( count( $tmp ) > 28 ) echo 'clip';
	else echo 'all'; 
?>"><?php
	$out = preg_replace_callback( '/(?:(.*?)(\<(script|style)\b.*?\>)(.*?)(\<\/\3\>))|(?:.+)/is', 'format_code', $this->enzyme );
	echo format_clean( $out );
?></pre>


