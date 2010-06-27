=== Enzymes ===
Contributors: aercolino
Donate link: http://noteslog.com/
Tags: enzymes, custom fields, properties, transclusion, inclusion, evaluation, content, retrieve, post, page, author
Requires at least: 3.0
Tested up to: 3.0
Stable tag: 2.3

Retrieve properties and custom fields of posts, pages, and authors, right into the visual editor of posts and pages, and everywhere else.

== Description ==

While editing the content (the visual editor is supported), Enzymes let's you retrieve properties and custom fields of posts, pages, and authors, and have them appear right there (transclusion). 

If you want something to appear elsewhere, outside the content, you can use Enzymes directly from WordPress theme files. 

You can also format and modify any transcluded content on the fly, using reusable code snippets stored inside files or custom fields.

Enzymes makes it very easy to re-use parts of your content around your blog, just by referencing it.

Here are two little samples, whose preconditions are these:
* you have a post with the slug `postcard-from-barcelona`, with a custom field with the key `report` and the value `warm and sunny (Feb, 2008)`
* in the post with the id 1 you have a custom field with the key <u>marker</u> and the value `return '<span style="background-color:' . $this->substrate . ';">' . $this->pathway . '</span>';`


= Simple transclusion: a custom field of a post is made appear in another post =
<blockquote>The weather in Barcelona was always {[ @postcard-from-barcelona.report ]} and we stayed outdoor most of the day.</blockquote>
is sent to the browser as
<blockquote>The weather in Barcelona was always warm and sunny (Feb, 2008) and we stayed outdoor most of the day.</blockquote>


= Simple evaluation: a custom field is transcluded and highlighed with a yellow marker =
<blockquote>The weather in Barcelona was always {[ @postcard-from-barcelona.report | 1.marker( =yellow= ) ]} and we stayed outdoor most of the day.</blockquote>
is sent to the browser as
<blockquote>The weather in Barcelona was always &lt;span style="background-color:yellow;">warm and sunny (Feb, 2008)&lt;/span> and we stayed outdoor most of the day.</blockquote>


= Enzymes Manual =
I've set up a page with [all you need to know about Enzymes](http://noteslog.com/enzymes/ "manual and examples").

== Installation ==

1. Copy to your plugins folder
1. Activate Enzymes

== Frequently Asked Questions ==

none

== Screenshots ==

none