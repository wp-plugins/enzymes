<?php

class EnzymesLegacy
{
    protected $db;
    protected $table_name;

    public
    function __construct()
    {
        global $wpdb;
        $this->db = $wpdb;

        $this->enzymes3_legacy = $this->db->prefix . 'enzymes3_legacy';
    }

    protected
    function posts_with_injections_into_the_title()
    {
        $query  = "SELECT ID, post_title AS content FROM {$this->db->posts} WHERE post_title LIKE '%{[%';";
        $result = $this->db->get_results($query);
        return $result;
    }

    protected
    function posts_with_injections_into_the_excerpt()
    {
        $query  = "SELECT ID, post_excerpt AS content FROM {$this->db->posts} WHERE post_excerpt LIKE '%{[%';";
        $result = $this->db->get_results($query);
        return $result;
    }

    protected
    function posts_with_injections_into_the_content()
    {
        $query  = "SELECT ID, post_content AS content FROM {$this->db->posts} WHERE post_content LIKE '%{[%';";
        $result = $this->db->get_results($query);
        return $result;
    }

    protected
    function legacy_data( $place, $posts )
    {
        $result = array();
        foreach ($posts as $post) {
            $offset = strpos($post->content, '{[');
            while (false !== $offset) {
                $result[] = array(
                    'post_id' => $post->ID,
                    'place'   => $place,
                    'offset'  => $offset,
                    'version' => '2.x',
                );
                $offset   = strpos($post->content, '{[', $offset + 1);
            }
        }
        return $result;
    }

    protected
    function remove_enzymes3_legacy_version_1() {
        $sql = "DROP TABLE IF EXISTS $this->enzymes3_legacy;";
        $this->db->query($sql);
        delete_option("enzymes3_legacy");
    }

    public
    function create_enzymes3_legacy_version_1()
    {
        $charset_collate = $this->db->get_charset_collate();

        $query = <<<END_QUERY
CREATE TABLE $this->enzymes3_legacy (
  ID BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
  post_id BIGINT(20) UNSIGNED NOT NULL DEFAULT '0',
  place VARCHAR(255) NOT NULL DEFAULT '',
  offset INT(11) NOT NULL DEFAULT '0',
  version VARCHAR(10) NOT NULL DEFAULT '0',
  PRIMARY KEY  (ID),
  UNIQUE KEY injection_id (post_id, place, offset)
) $charset_collate;
END_QUERY;

        $this->remove_enzymes3_legacy_version_1();

        require_once(ABSPATH . 'wp-admin/includes/upgrade.php');
        dbDelta($query);
        add_option("enzymes3_legacy", "version 1");
    }

    public
    function fill_enzymes3_legacy_version_1()
    {
        $rows = array();

        $posts = $this->posts_with_injections_into_the_title();
        $rows  = array_merge($rows, $this->legacy_data('post_title', $posts));

        $posts = $this->posts_with_injections_into_the_excerpt();
        $rows  = array_merge($rows, $this->legacy_data('post_excerpt', $posts));

        $posts = $this->posts_with_injections_into_the_content();
        $rows  = array_merge($rows, $this->legacy_data('post_content', $posts));

        foreach ($rows as $row) {
            $this->db->insert($this->enzymes3_legacy, $row);
        }
    }

    public
    function on_plugin_install()
    {

    }

    public
    function on_plugin_uninstall()
    {

    }

}