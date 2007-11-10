<pre class="chili-<?php 
	$tmp = preg_split( '/(?:\r?\n)+/', $this->enzyme );
	if( count( $tmp ) > 28 ) echo 'clip';
	else echo 'all'; 
?>"><code class="html"><?php
$out = preg_replace( '/\[(css|html|javascript|php)\]/', '[($1)]', $this->enzyme );
$out = preg_replace( '/(\<\?php\s)(.*?)(\?\>)/is', '[html]$1[php]$2[html]$3', $out );
$out = preg_replace( '/(\<(style)\b.*?\>)(.*?)(\<\/\2\>)/is', '$1[css]$3[html]$4', $out );
$out = preg_replace( '/(\<(script)\b.*?\>)(.*?)(\<\/\2\>)/is', '$1[javascript]$3[html]$4', $out );
$out = htmlentities( $out );
$out = preg_replace( '/\[(css|html|javascript|php)\]/', '</code><code class="$1">', $out );
$out = preg_replace( '/\[\((css|html|javascript|php)\)\]/', '[$1]', $out );
echo $out;
?></code></pre>