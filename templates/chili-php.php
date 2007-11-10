<?php
	if( ! function_exists( "php_format" ) ) {
	function php_format( $matches ) {
		list( $all, $before, $code ) = $matches;
		if( ('' == $before) && ('' == $code) ) 
			return '' == $all ? '' : '<code class="php">'. htmlentities( $all ).'</code>';
		else 
			return htmlentities( $before ).'<code class="php">'. htmlentities( $code ).'</code>';
	} }
?>
<pre class="chili-<?php 
	$tmp = preg_split( '/[\r\n]+/', $this->enzyme );
	if( count( $tmp ) > 28 ) echo 'clip';
	else echo 'all'; 
?>"><?php
	echo preg_replace_callback( '/(?:(.*?)(\<\?php.*?\?\>))|(?:.*)/si', "php_format", $this->enzyme ); 
?></pre>