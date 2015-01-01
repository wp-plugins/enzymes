<?php

class EnzymesPluginTest
        extends WP_UnitTestCase
{
    // NOTE: The tests/bootstrap.php file loads the plugin into WordPress.

    function test_plugin_hooks_into_wordpress()
    {
        $events = array(
                'wp_title',
                'the_title',
                'the_title_rss',
                'the_excerpt',
                'the_excerpt_rss',
                'the_content'
        );
        $enzymes = EnzymesPlugin::engine();
        foreach ($events as $event) {
            $this->assertEquals(10, has_filter($event, array($enzymes, 'metabolize')),
                                "Enzymes didn't attach to '$event'.");
        }
    }

}
