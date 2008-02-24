=== Enzymes ===
Contributors: aercolino
Donate link: http://noteslog.com/
Tags: enzymes, custom fields, properties, transclusion, inclusion, evaluation, content, retrieve, post, page, author
Requires at least: 1.2
Tested up to: 2.3
Stable tag: 2.1

Retrieve properties and custom fields of posts, pages, and authors, right into the visual editor of posts and pages, and everywhere else.


== Description ==

__Simple transclusion__
<blockquote>The weather in Barcelona was always {[ @post-from-barcelona.report ]} and we stayed many hours outdoor.</blockquote>
becomes
<blockquote>The weather in Barcelona was always warm and sunny (Feb, 2008) and we stayed many hours outdoor.</blockquote>

__Simple evaluation__
<blockquote>The weather in Barcelona was always {[ @post-from-barcelona.report | 1.marker( =yellow= ) ]} and we stayed many hours outdoor.</blockquote>
becomes
<blockquote>The weather in Barcelona was always <span style="background-color:yellow;">warm and sunny (Feb, 2008)</span> and we stayed many hours outdoor.</blockquote>


__Preconditions__
*   you have installed Enzymes
*   you have a post with the slug <u>post-from-barcelona</u>
*   in that post you have a custom field with the key <u>report</u> and the value 
    `warm and sunny (Feb, 2008)`
*   in the post with the id 1 you have a custom field with the key <u>marker</u> and the value
    `return '<span style="background-color:' . $this->substrate . ';">' . $this->pathway . '</span>';`


<h3>
<a href="http://noteslog.com/enzymes/" alt="a full manual with examples">__Enzymes Manual__</a>
</h3>


== Installation ==

1. Copy to your plugins folder
1. Activate Enzymes

