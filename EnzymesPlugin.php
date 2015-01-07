<?php
require_once 'lib/Enzymes3.php';

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
    function uninstall()
    {

    }

    //------------------------------------------------------------------------------------------------------------------

    static protected
    function add_roles_and_capabilities()
    {
        $caps = array_keys(Enzymes3::capabilities());
        $no_role_capabilities = array_fill_keys($caps, false);
//@formatter:off
        remove_role('enzymes.User');
        $user_role = add_role(
            'enzymes.User', __('Enzymes User'), array_merge($no_role_capabilities, array(
                'enzymes.inject'                       => true,
                'enzymes.use_own_attributes'           => true,
                'enzymes.use_own_custom_fields'        => true,
                'enzymes.create_static_custom_fields'  => true,
        )));

        remove_role('enzymes.PrivilegedUser');
        $privileged_user_role = add_role(
            'enzymes.PrivilegedUser', __('Enzymes Privileged User'), array_merge($user_role->capabilities, array(
                'enzymes.use_others_custom_fields'     => true,
        )));

        remove_role('enzymes.TrustedUser');
        $trusted_user_role = add_role(
            'enzymes.TrustedUser', __('Enzymes Trusted User'), array_merge($privileged_user_role->capabilities, array(
                'enzymes.share_static_custom_fields'   => true,
        )));

        remove_role('enzymes.Coder');
        $coder_role = add_role(
            'enzymes.Coder', __('Enzymes Coder'), array_merge($trusted_user_role->capabilities, array(
                'enzymes.create_dynamic_custom_fields' => true,
        )));

        remove_role('enzymes.TrustedCoder');
        $trusted_coder_role = add_role(
            'enzymes.TrustedCoder', __('Enzymes Trusted Coder'), array_merge($coder_role->capabilities, array(
                'enzymes.share_dynamic_custom_fields'  => true,
        )));
//@formatter:on

        global $wp_roles;
        /* @var $wp_roles WP_Roles */
        foreach ($caps as $cap) {
            $wp_roles->add_cap('administrator', $cap);
        }
    }

    static protected
    function remove_roles_and_capabilities()
    {
        global $wp_roles;
        /* @var $wp_roles WP_Roles */

        foreach ($wp_roles->roles as $name => $role) {
            if ( 0 === strpos($name, 'enzymes.') ) {
                remove_role($name);
            }
        }

        $caps = array_keys(Enzymes3::capabilities());
        foreach ($caps as $cap) {
            $wp_roles->remove_cap('administrator', $cap);
        }
    }

}
