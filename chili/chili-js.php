<pre class="chili-<?php 
	$tmp = preg_split( '/(?:\r?\n)+/', $this->result );
	if( count( $tmp ) > 28 ) echo 'clip';
	else echo 'all'; 
?>"><code class="javascript"><?php 
	echo htmlentities( $this->result ); 
?></code></pre>