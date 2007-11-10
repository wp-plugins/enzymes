<?php 
if( ! function_exists( 'web_snippets' ) ) {
	function php_inside( $before, $body, $after ) {
		return $before . '[php]' . $body . '[html]' . $after;
	}
	function web_snippets( $matches ) {
		$php_snippet = '/(\<\?php\s)(.*?)(\?\>)/is';
		if( '' == $matches[6] ) {
			if    ( $matches[3] == 'script' ) $resume = '[javascript]';
			elseif( $matches[3] == 'style' )  $resume = '[css]';
			else                              $resume = '';
			$replacement = ''
				. preg_replace( $php_snippet.'e', "php_inside( '$1', '$2', '$3' )", $matches[1] )
				. $matches[2]
				. $resume
				. preg_replace( $php_snippet.'e', "'[html]' . php_inside( '$1', '$2', '$3' ) . '$resume'", $matches[4] )
				. '[html]'
				. $matches[5];
		}
		else {
			$replacement = ''
				. preg_replace( $php_snippet.'e', "php_inside( '$1', '$2', '$3' )", $matches[6] );
		}
		return $replacement;
	}
}
?>
<pre class="chili-<?php 
	$tmp = preg_split( '/(?:\r?\n)+/', $this->enzyme );
	if( count( $tmp ) > 28 ) echo 'clip';
	else echo 'all'; 
?>"><code class="html"><?php
	$out = preg_replace( '/\[(css|html|javascript|php)\]/', '[($1)]', $this->enzyme );
	$out = preg_replace_callback( '/(?:(.*?)(\<(script|style)\b.*?\>)(.*?)(\<\/\3\>))|(.+)/is', 'web_snippets', $out );
	$out = htmlentities( $out );
	$out = preg_replace( '/\[(css|html|javascript|php)\]/', '</code><code class="$1">', $out );
	$out = preg_replace( '/\[\((css|html|javascript|php)\)\]/', '[$1]', $out );
	echo $out;
?></code></pre>