=== Enzymes ===
Contributors: aercolino
Donate link: http://noteslog.com/
Tags: enzymes, custom fields, properties, transclusion, inclusion, evaluation, content, retrieve, post, page, author
Requires at least: 1.2
Tested up to: 2.3
Stable tag: 2.1

Retrieve properties and custom fields of posts, pages, and authors, right into the visual editor of posts and pages, and everywhere else.

== Description ==

With Enzymes you can retrieve properties and custom fields of posts, pages, and authors, right into the visual editor of posts, pages, and everywhere else. It makes it very easy to re-use parts of your content around your blog, as soon as you can identify it.

= Simple transclusion =
<blockquote>The weather in Barcelona was always {[ @postcard-from-barcelona.report ]} and we stayed outdoor most of the day.</blockquote>
is sent to the browser as
<blockquote>The weather in Barcelona was always warm and sunny (Feb, 2008) and we stayed outdoor most of the day.</blockquote>

= Simple evaluation =
<blockquote>The weather in Barcelona was always {[ @postcard-from-barcelona.report | 1.marker( =yellow= ) ]} and we stayed outdoor most of the day.</blockquote>
is sent to the browser as
<blockquote>The weather in Barcelona was always &lt;span style="background-color:yellow;">warm and sunny (Feb, 2008)&lt;/span> and we stayed outdoor most of the day.</blockquote>


= Preconditions =
*   you have installed Enzymes
*   you have a post with the slug `postcard-from-barcelona`
*   in that post you have a custom field with the key `report` and the value `warm and sunny (Feb, 2008)`
*   in the post with the id 1 you have a custom field with the key <u>marker</u> and the value `return '<span style="background-color:' . $this->substrate . ';">' . $this->pathway . '</span>';`


= Enzymes Manual =
I've set up a page with [all you need to know about Enzymes](http://noteslog.com/enzymes/ "manual and examples").

== Installation ==

1. Copy to your plugins folder
1. Activate Enzymes

== Frequently Asked Questions ==

none

== Screenshots ==

none