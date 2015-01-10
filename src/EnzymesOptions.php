<?php

class EnzymesOptions
{
    const PREFIX = 'Enzymes3.';

    /**
     * Returns the site/user option name.
     *
     * @param string $username
     *
     * @return string
     */
    protected
    function name( $username = null )
    {
        $result = self::PREFIX . ($username
                        ? $username
                        : 'global_options');
        return $result;
    }

    /**
     * Removes the site/user option.
     *
     * @param string $username
     */
    public
    function remove( $username = null )
    {
        if ( is_numeric($username) ) {
            $user = new WP_User($username);
            $username = $user->user_login;
        }
        $name = $this->name($username);
        delete_option($name);
    }

    /**
     * Removes site option and each user's ones.
     */
    public
    function remove_all()
    {
        global $wpdb;
        $prefix = self::PREFIX;
        $wpdb->query("DELETE FROM `$wpdb->options` WHERE `option_name` LIKE '{$prefix}%'");
    }

    /**
     * Retrieves the site/user option value.
     *
     * @param string $username
     *
     * @return mixed
     */
    public
    function get( $username = null )
    {
        $name = $this->name($username);
        $result = get_option($name, array());
        return $result;
    }

    /**
     * Replaces the site/user option with the given $value.
     * The site option is set with the autoload flag to yes.
     * The user options are set with the autoload flag to no.
     *
     * @param mixed  $value
     * @param string $username
     */
    public
    function set( $value, $username = null )
    {
        $name = $this->name($username);
        delete_option($name);
        $autoload = $username
                ? 'no'
                : 'yes';
        add_option($name, $value, null, $autoload);
    }

    /**
     * Retrieves the values of the given option $keys
     *
     * @param array  $keys
     * @param string $username
     *
     * @return array
     */
    public
    function keysGet( $keys, $username = null )
    {
        $result = array_fill_keys($keys, null);
        $data = $this->get($username);
        $result = array_merge($result, $data);
        return $result;
    }

    /**
     * Sets the given $values to their option keys
     *
     * @param array  $values
     * @param string $username
     */
    public
    function keysSet( $values, $username = null )
    {
        $data = $this->get($username);
        $data = array_merge($data, $values);
        $this->set($data, $username);
    }
}
