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
gets converted into
<blockquote>The weather in Barcelona was always warm and sunny (Feb, 2008) and we stayed many hours outdoor.</blockquote>

__Simple evaluation__
<blockquote>The weather in Barcelona was always {[ @post-from-barcelona.report | 1.marker( =yellow= ) ]} and we stayed many hours outdoor.</blockquote>
using Enzymes gets converted into
<blockquote>The weather in Barcelona was always <span style="background-color:yellow;">warm and sunny (Feb, 2008)</span> and we stayed many hours outdoor.</blockquote>

__Preconditions__
<ul>
<li>you have installed Enzymes</li>
<li>you have a post with the slug <u>post-from-barcelona</u></li>
<li>in that post you have a custom field with the key <u>report</u> and the value `warm and sunny (Feb, 2008)`</li>
<li>in the post with the id 1 you have a custom field with the key <u>marker</u> and the value `return '<span style="background-color:' . $this->substrate . ';">' . $this->pathway . '</span>';`</li>
</ul>

## [Enzymes Manual](http://noteslog.com/enzymes/ "a full manual with examples")


== Installation ==

1. Copy to your plugins folder
1. Activate Enzymes

