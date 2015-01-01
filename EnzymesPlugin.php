<?php

class EnzymesPlugin
{

    /**
     * Singleton
     *
     * @var Enzymes3
     */
    static protected $enzymes;

    /**
     * @return Enzymes3
     */
    static public
    function engine()
    {
        if (is_null(self::$enzymes)) {
            require_once 'lib/Enzymes3.php';
            self::$enzymes = new Enzymes3();
        }
        return self::$enzymes;
    }

    public
    function __construct()
    {
        $enzymes = self::engine();  // pointer to the singleton
        add_filter('wp_title', array($enzymes, 'metabolize'), 10, 2);
        add_filter('the_title', array($enzymes, 'metabolize'), 10, 2);
        add_filter('the_title_rss', array($enzymes, 'metabolize'), 10, 2);
        add_filter('the_excerpt', array($enzymes, 'metabolize'), 10, 2);
        add_filter('the_excerpt_rss', array($enzymes, 'metabolize'), 10, 2);
        add_filter('the_content', array($enzymes, 'metabolize'), 10, 2);
    }

}
