<?php
require_once 'Enzymes3.php';

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
        if ( is_null(self::$enzymes) ) {
            self::$enzymes = new Enzymes3();
        }
        return self::$enzymes;
    }

    public
    function __construct()
    {
        register_activation_hook(ENZYMES_FILENAME, array('EnzymesPlugin', 'on_activation'));
        register_deactivation_hook(ENZYMES_FILENAME, array('EnzymesPlugin', 'on_deactivation'));

        add_action('init', array('EnzymesPlugin', 'on_init'), 10, 2);
    }

    static public
    function on_init()
    {
        $enzymes = self::engine();  // pointer to the singleton
        add_filter('wp_title', array($enzymes, 'metabolize'), 10, 2);
        add_filter('the_title', array($enzymes, 'metabolize'), 10, 2);
        add_filter('the_title_rss', array($enzymes, 'metabolize'), 10, 2);
        add_filter('the_excerpt', array($enzymes, 'metabolize'), 10, 2);
        add_filter('the_excerpt_rss', array($enzymes, 'metabolize'), 10, 2);
        add_filter('the_content', array($enzymes, 'metabolize'), 10, 2);
    }

    /**
     * Callback used when the plugin is activated by the user.
     *
     * @return boolean
     */
    static public
    function on_activation()
    {
        self::add_plugin_options();
        self::add_roles_and_capabilities();
        return true;
    }

    /**
     * Callback used when the plugin is deactivated by the user.
     *
     * @return boolean
     */
    static public
    function on_deactivation()
    {
        self::remove_roles_and_capabilities();
        return true;
    }

    /**
     * Uninstalls this plugin, cleaning up all data.
     * This is called from uninstall.php without instantiating an object of this class.
     *
     */
    static public
    function on_uninstall()
    {

    }

    //------------------------------------------------------------------------------------------------------------------


    static protected
    function save_activation_time()
    {
//        self::engine()
    }

    static protected
    function delete_activation_time()
    {

    }

    static protected
    function add_roles_and_capabilities()
    {
        self::remove_roles_and_capabilities();
//@formatter:off
        add_role(EnzymesCapabilities::User,           __('Enzymes User'),            EnzymesCapabilities::for_User() );
        add_role(EnzymesCapabilities::PrivilegedUser, __('Enzymes Privileged User'), EnzymesCapabilities::for_PrivilegedUser() );
        add_role(EnzymesCapabilities::TrustedUser,    __('Enzymes Trusted User'),    EnzymesCapabilities::for_TrustedUser() );
        add_role(EnzymesCapabilities::Coder,          __('Enzymes Coder'),           EnzymesCapabilities::for_Coder() );
        add_role(EnzymesCapabilities::TrustedCoder,   __('Enzymes Trusted Coder'),   EnzymesCapabilities::for_TrustedCoder() );
//@formatter:on

        global $wp_roles;
        /* @var $wp_roles WP_Roles */

        foreach (EnzymesCapabilities::all() as $cap) {
            $wp_roles->add_cap('administrator', $cap);
        }
    }

    /**
     * Remove roles and capabilities by prefix.
     */
    static protected
    function remove_roles_and_capabilities()
    {
        global $wp_roles;
        /* @var $wp_roles WP_Roles */

        foreach ($wp_roles->roles as $name => $role) {
            if ( 0 === strpos($name, Enzymes3::PREFIX) ) {
                remove_role($name);
            }
        }

        foreach (EnzymesCapabilities::all() as $cap) {
            if ( 0 === strpos($cap, Enzymes3::PREFIX) ) {
                $wp_roles->remove_cap('administrator', $cap);
            }
        }
    }

}
