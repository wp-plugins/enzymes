=== Enzymes ===
Contributors: aercolino
Donate link: http://noteslog.com/
Tags: enzymes, custom fields, properties, transclusion, inclusion, evaluation, content, retrieve, post, page, author
Requires at least: 1.2
Tested up to: 2.3
Stable tag: 2.1

Retrieve properties and custom fields of posts, pages, and authors, right into the visual editor of posts and pages, and everywhere else.


== Description ==

<strong>Simple transclusion</strong>
<blockquote>The weather in Barcelona was always {[ @post-from-barcelona.report ]} and we stayed many hours outdoor.</blockquote>
gets converted into
<blockquote>The weather in Barcelona was always warm and sunny (Feb08) and we stayed many hours outdoor.</blockquote>

<strong>Simple evaluation</strong>
<blockquote>The weather in Barcelona was always {[ @post-from-barcelona.report | 1.marker( =yellow= ) ]} and we stayed many hours outdoor.</blockquote>
using Enzymes gets converted into
<blockquote>The weather in Barcelona was always <span style="background-color:yellow;">warm and sunny (Feb08)</span> and we stayed many hours outdoor.</blockquote>

Preconditions
<ul>
<li>you have installed Enzymes</li>
<li>you have a post with the slug <u>post-from-barcelona</u></li>
<li>in that post you have a custom field with the key <u>report</u> and the value <code>warm and sunny (Feb08)</code></li>
<li>in the post with the id 1 you have a custom field with the key <u>marker</u> and the value <code>return &apos;&lt;span style=&quot;background-color:&apos; . $this-&gt;substrate . &apos;;&quot;&gt;&apos; . $this-&gt;pathway . &apos;&lt;/span&gt;&apos;;</code></li>
</ul>

[Enzymes Manual](http://noteslog.com/enzymes/ "a full manual with examples")


== Installation ==

1. Copy to your plugins folder
2. Activate Enzymes

