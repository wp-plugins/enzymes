<?php
require_once 'Ando/Regex.php';
require_once 'Sequence.php';

class Enzymes3
{
    public $debug_on = false;

    public
    function debug_print( $something )
    {
        if ( ! $this->debug_on ) {
            return;
        }
        fwrite(STDERR, "\n" . print_r($something, true) . "\n");
    }

    /**
     * The post which the content belongs to.
     *
     * @var WP_Post
     */
    protected $post;

    /**
     * The content of the post, modified by Enzymes.
     *
     * @var string
     */
    protected $new_content;

    /**
     * Regular expression for matching "{[ .. ]}".
     *
     * @var Ando_Regex
     */
    protected $e_injection;

    /**
     * Regular expression for matching "(|enzyme)+".
     *
     * @var Ando_Regex
     */
    protected $e_sequence_valid;

    /**
     * Regular expression for matching "enzyme(rest?)".
     *
     * @var Ando_Regex
     */
    protected $e_sequence_start;

    /**
     * Regular expression for matching "= .. =".
     *
     * @var Ando_Regex
     */
    protected $e_string;

    /**
     * Due to the fact that ending empty groups are not returned by preg matching,
     * we count the expected matches for e_string and adjust the actual $matches.
     *
     * @var integer
     */
    protected $count_matches_for_e_string;

    /**
     * Regular expression for matching PHP multiple lines comment.
     *
     * @var Ando_Regex
     */
    protected $e_comment;

    /**
     * Regular expression for matching spaces and space-like characters.
     *
     * @var Ando_Regex
     */
    protected $e_blank;

    /**
     * Regular expression for matching "\{\[", "\]\}".
     *
     * @var Ando_Regex
     */
    protected $e_escaped_injection_delimiter;

    /**
     * Regular expression for matching "\=".
     *
     * @var Ando_Regex
     */
    protected $e_escaped_string_delimiter;

    /**
     * Sequence of catalyzed enzymes, which are meant to be used as arguments for other enzymes.
     *
     * @var Sequence
     */
    protected $catalyzed;

    /**
     * Grammar (top down).
     * ---
     * injection := "{[" sequence "]}"
     *   sequence := enzyme ("|" enzyme)*
     *   enzyme   := literal | transclusion | execution
     *
     * literal := number | str_literal
     *   number  := \d+(\.\d+)?
     *   str_literal := string
     *   string  := "=" <a string where "=", "|", "]}", "\"  are escaped by a prefixed "\"> "="
     *
     * transclusion := post_item | post_attr | author_item | author_attr
     *   post_item   := post "." field
     *   post_attr   := post ":" field
     *   author_item := post "/author." field
     *   author_attr := post "/author:" field
     *   post        := \d+ | "@" slug | ""
     *   slug        := [\w+~-]+
     *   field       := [\w-]+ | string
     *
     * execution := ("array" | "hash" | item) "(" \d* ")"
     * ---
     *
     * These (key, value) pairs follow the pattern: "'rule_left' => '(?<rule_left>rule_right)';".
     *
     * @var Ando_Regex[]
     */
    protected $grammar;

    /**
     * Init the grammar.
     */
    protected
    function init_grammar()
    {
        /**
         * Notice that $grammar rules are sorted bottom up here to allow complete interpolation.
         */
        $grammar = array(
                'number'       => '(?<number>\d+(\.\d+)?)',
                'string'       => '(?<string>' . Ando_Regex::pattern_quoted_string('=', '=') . ')',
                // @=[^=\\]*(?:\\.[^=\\]*)*=@
                'str_literal'  => '(?<str_literal>$string)',
                'literal'      => '(?<literal>$number|$str_literal)',

                'slug'         => '(?<slug>[\w+~-]+)',
                'post'         => '(?<post>\d+|@$slug|)',
                'field'        => '(?<field>[\w-]+|$string)',
                'post_item'    => '(?<post_item>$post\.$field)',
                'author_item'  => '(?<author_item>$post/author\.$field)',
                'item'         => '(?<item>$post_item|$author_item)',
                'post_attr'    => '(?<post_attr>$post:$field)',
                'author_attr'  => '(?<author_attr>$post/author:$field)',
                'attr'         => '(?<attr>$post_attr|$author_attr)',
                'transclusion' => '(?<transclusion>$item|$attr)',

                'execution'    => '(?<execution>(?:\barray\b|\bhash\b|$item)\((?<num_args>\d*)\))',

                'enzyme'       => '(?<enzyme>(?:$execution|$transclusion|$literal))',
                'sequence'     => '(?<sequence>$enzyme(\|$enzyme)*)',
                'injection'    => '(?<injection>{[$sequence]})',
        );
        $result = array();
        foreach ($grammar as $symbol => $rule) {
            $regex = new Ando_Regex($rule);
            $result[$symbol] = $regex->interpolate($result);
        }
        $this->grammar = $result;
    }

    /**
     * Init the regular expression for matching the injection of a sequence.
     */
    protected
    function init_e_injection()
    {
        $before = new Ando_Regex('(?<before>.*?)');
        $could_be_injection = new Ando_Regex('\{\[(?<could_be_sequence>.*?)\]\}');
        $after = new Ando_Regex('(?<after>.*)');
        $content = new Ando_Regex('^$before$could_be_injection$after$', '@@s');
        $content->interpolate(array(
                                      'before'             => $before,
                                      'could_be_injection' => $could_be_injection,
                                      'after'              => $after,
                              ));
        $this->e_injection = $content;
    }

    /**
     * Init the regular expression for matching a valid sequence.
     */
    protected
    function init_e_sequence_valid()
    {
        // Notice that sequence_valid matches all the enzymes of the sequence at once.
        $sequence_valid = new Ando_Regex(Ando_Regex::option_same_name() . '^(?:\|$enzyme)+$', '@@');
        $sequence_valid->interpolate(array(
                                             'enzyme' => $this->grammar['enzyme'],
                                     ));
        $this->e_sequence_valid = $sequence_valid;
    }

    /**
     * Init the regular expression for matching the start of a sequence.
     */
    protected
    function init_e_sequence_start()
    {
        $rest = new Ando_Regex('(?:\|(?<rest>.+))');
        $sequence_start = new Ando_Regex(Ando_Regex::option_same_name() . '^$enzyme$rest?$', '@@');
        $sequence_start->interpolate(array(
                                             'enzyme' => $this->grammar['enzyme'],
                                             'rest'   => $rest,
                                     ));
        $this->e_sequence_start = $sequence_start;
    }

    /**
     * Init the regular expression for matching strings.
     */
    protected
    function init_e_string()
    {
        $maybe_quoted = new Ando_Regex('(?<before_string>.*?)$string|(?<anything_else>.+)', '@@s');
        $maybe_quoted->interpolate(array(
                                           'string' => $this->grammar['string'],
                                   ));
        $this->e_string = $maybe_quoted;
    }

    /**
     * Init regular expressions.
     */
    protected
    function init_expressions()
    {
        $this->init_e_injection();
        $this->init_e_sequence_valid();
        $this->init_e_sequence_start();
        $this->init_e_string();

        $this->e_comment = new Ando_Regex('\/\*.*?\*\/', '@@s');
        // for some reason WP introduces some C2 (hex) chars when writing a post...
        $this->e_blank = new Ando_Regex('(?:\s|\xc2)+', '@@');
        $this->e_escaped_injection_delimiter = new Ando_Regex('\\\\([{[\]}])', '@@');
        $this->e_escaped_string_delimiter = new Ando_Regex('\\\\=', '@@');
    }

    public
    function __construct()
    {
        $this->init_grammar();
        $this->init_expressions();
        $this->add_roles_and_capabilities();
    }

    /**
     * @param array $actual
     */
    protected
    function default_empty( array &$actual )
    {
        $keys = func_get_args();
        array_shift($keys);
        $default = array_fill_keys($keys, '');
        $actual = array_merge($default, $actual);
    }

    /**
     * @param string $code
     * @param array  $arguments
     *
     * @return array
     */
    protected
    function safe_eval( $code, array $arguments = array() )
    {
        // use an expression like this inside a custom field value:
        // list($some, $appropriate, $variable, $name) = $arguments;
        ob_start();
        $result = eval($code);
        $output = ob_get_contents();
        ob_end_clean();
        return array($result, $output);
    }

    /**
     * @param array $matches
     *
     * @return null|WP_Post
     */
    protected
    function wp_post( array $matches )
    {
        $this->default_empty($matches, 'post', 'slug');
        extract($matches);
        /* @var $post string */
        /* @var $slug string */
        switch (true) {
            case ($post == ''):
                $result = $this->post;
                break;
            case ($post[0] == '@'):
                $result = get_page_by_path($slug, OBJECT, 'post');
                break;
            case (is_numeric($post)):
                $result = get_post($post);
                break;
            default:
                $result = null;
                break;
        }
        return $result;
    }

    /**
     * @param string $string
     *
     * @return mixed|string
     */
    protected
    function unquote( $string )
    {
        $result = substr($string, 1, -1);  // unwrap from quotes
        $result = str_replace('\\=', '=', $result);  // revert escaped quotes
        return $result;
    }

    /**
     * @param WP_Post $post_object
     * @param array   $matches
     *
     * @return string|array
     */
    protected
    function wp_post_field( $post_object, array $matches )
    {
        $this->default_empty($matches, 'field', 'string');
        extract($matches);
        /* @var $field string */
        /* @var $string string */
        if ( $string ) {
            $field = $this->unquote($field);
        }
        $values = get_post_meta($post_object->ID, $field);
        $result = count($values) == 1
                ? $values[0]
                : (count($values) == 0
                        ? null
                        : $values);
        return $result;
    }

    /**
     * @param WP_Post $post_object
     * @param array   $matches
     *
     * @return mixed
     */
    protected
    function wp_post_attribute( $post_object, array $matches )
    {
        $this->default_empty($matches, 'field', 'string');
        extract($matches);
        /* @var $field string */
        /* @var $string string */
        if ( $string ) {
            $field = $this->unquote($field);
        }
        if ( ! property_exists($post_object, $field) ) {
            return "($field)";
        }
        $result = $post_object->$field;
        return $result;
    }

    /**
     * @param WP_User $user_object
     * @param array   $matches
     *
     * @return string|array
     */
    protected
    function wp_user_field( $user_object, array $matches )
    {
        $this->default_empty($matches, 'field', 'string');
        extract($matches);
        /* @var $field string */
        /* @var $string string */
        if ( $string ) {
            $field = $this->unquote($field);
        }
        $values = get_user_meta($user_object->ID, $field);
        $result = count($values) == 1
                ? $values[0]
                : (count($values) == 0
                        ? null
                        : $values);
        return $result;
    }

    /**
     * @param WP_User $user_object
     * @param array   $matches
     *
     * @return mixed
     */
    protected
    function wp_user_attribute( $user_object, array $matches )
    {
        $this->default_empty($matches, 'field', 'string');
        extract($matches);
        /* @var $field string */
        /* @var $string string */
        if ( $string ) {
            $field = $this->unquote($field);
        }
        $result = @$user_object->$field;
        return $result;
    }

    /**
     * @param WP_Post $post_object
     *
     * @return WP_User
     */
    protected
    function wp_author( $post_object )
    {
        $id = $post_object->post_author;
        $result = get_user_by('id', $id);
        return $result;
    }

    protected
    function execute_code( $code, $arguments, $post_object )
    {
        $current_user = wp_get_current_user();
        if ( author_can($post_object, 'create_php_enzymes') &&
             ($current_user->ID == $post_object->post_author || author_can($post_object, 'share_php_enzymes'))
        ) {
            list($result,) = $this->safe_eval($code, $arguments);
        } else {
            $result = null;
        }
        return $result;
    }

    /**
     * @param string  $post_item
     * @param integer $num_args
     *
     * @return mixed
     * @throws Ando_Exception
     */
    protected
    function execute_post_item( $post_item, $num_args )
    {
        $this->debug_print('executing post_item');
        // match again to be able to access groups by name...
        $expression = $this->grammar['post_item']->wrapper_set('@@')
                                                 ->expression(true);
        preg_match($expression, $post_item, $matches);
        $post_object = $this->wp_post($matches);
        $code = $this->wp_post_field($post_object, $matches);
        $arguments = $num_args > 0
                ? $this->catalyzed->pop($num_args)
                : array();
        $result = $this->execute_code($code, $arguments, $post_object);
        return $result;
    }

    /**
     * @param string  $author_item
     * @param integer $num_args
     *
     * @return mixed
     * @throws Ando_Exception
     */
    protected
    function execute_author_item( $author_item, $num_args )
    {
        $this->debug_print('executing author_item');
        $expression = $this->grammar['author_item']->wrapper_set('@@')
                                                   ->expression(true);
        preg_match($expression, $author_item, $matches);
        $post_object = $this->wp_post($matches);
        $user_object = $this->wp_author($post_object);
        $code = $this->wp_user_field($user_object, $matches);
        $arguments = $num_args > 0
                ? $this->catalyzed->pop($num_args)
                : array();
        $result = $this->execute_code($code, $arguments, $post_object);
        return $result;
    }

    /**
     * @param array $matches
     *
     * @return array|null
     */
    protected
    function do_execution( array $matches )
    {
        $this->default_empty($matches, 'execution', 'post_item', 'author_item', 'num_args');
        extract($matches);
        /* @var $execution string */
        /* @var $post_item string */
        /* @var $author_item string */
        /* @var $num_args string */
        $num_args = (int) $num_args;
        switch (true) {
            case (strpos($execution, 'array(') === 0 && $num_args > 0):
                $result = $this->catalyzed->pop($num_args);
                break;
            case (strpos($execution, 'hash(') === 0 && $num_args > 0):
                $result = array();
                $arguments = $this->catalyzed->pop(2 * $num_args);
                for ($i = 0, $i_top = 2 * $num_args; $i < $i_top; $i += 2) {
                    $key = $arguments[$i];
                    $value = $arguments[$i + 1];
                    $result[$key] = $value;
                }
                break;
            case ($post_item != ''):
                $result = $this->execute_post_item($post_item, $num_args);
                break;
            case ($author_item != ''):
                $result = $this->execute_author_item($author_item, $num_args);
                break;
            default:
                $result = null;
                break;
        }
        return $result;
    }

    protected
    function transclude_code( $code, $post_object )
    {
        $current_user = wp_get_current_user();
        if ( author_can($post_object, 'create_php_enzymes') &&
             ($current_user->ID == $post_object->post_author || author_can($post_object, 'share_php_enzymes'))
        ) {
            list(, $output) = $this->safe_eval(" ?>$code<?php ");
        } elseif ( author_can($post_object, 'create_html_enzymes') ) {
            $output = $code;
        } else {
            $output = '';
        }
        return $output;
    }

    /**
     * @param string  $post_item
     * @param WP_Post $post_object
     *
     * @return string
     * @throws Ando_Exception
     */
    protected
    function transclude_post_item( $post_item, $post_object )
    {
        $this->debug_print('transcluding post_item');
        $expression = $this->grammar['post_item']->wrapper_set('@@')
                                                 ->expression(true);
        preg_match($expression, $post_item, $matches);
        $code = $this->wp_post_field($post_object, $matches);
        $output = $this->transclude_code($code, $post_object);
        return $output;
    }

    /**
     * @param string  $post_attr
     * @param WP_Post $post_object
     *
     * @return mixed
     * @throws Ando_Exception
     */
    protected
    function transclude_post_attr( $post_attr, $post_object )
    {
        $this->debug_print('transcluding post_attr');
        $expression = $this->grammar['post_attr']->wrapper_set('@@')
                                                 ->expression(true);
        preg_match($expression, $post_attr, $matches);
        $result = $this->wp_post_attribute($post_object, $matches);
        return $result;
    }

    /**
     * @param string  $author_item
     * @param WP_Post $post_object
     *
     * @return string
     * @throws Ando_Exception
     */
    protected
    function transclude_author_item( $author_item, $post_object )
    {
        $this->debug_print('transcluding author_item');
        $expression = $this->grammar['author_item']->wrapper_set('@@')
                                                   ->expression(true);
        preg_match($expression, $author_item, $matches);
        $user_object = $this->wp_author($post_object);
        $code = $this->wp_user_field($user_object, $matches);
        $output = $this->transclude_code($code, $post_object);
        return $output;
    }

    /**
     * @param string  $author_attr
     * @param WP_Post $post_object
     *
     * @return mixed
     * @throws Ando_Exception
     */
    protected
    function transclude_author_attr( $author_attr, $post_object )
    {
        $this->debug_print('transcluding author_attr');
        $expression = $this->grammar['author_attr']->wrapper_set('@@')
                                                   ->expression(true);
        preg_match($expression, $author_attr, $matches);
        $user_object = $this->wp_author($post_object);
        $result = $this->wp_user_attribute($user_object, $matches);
        return $result;
    }

    /**
     * @param array $matches
     *
     * @return null|string
     */
    protected
    function do_transclusion( array $matches )
    {
        $this->default_empty($matches, 'post_item', 'post_attr', 'author_item', 'author_attr');
        extract($matches);
        /* @var $post_item string */
        /* @var $post_attr string */
        /* @var $author_item string */
        /* @var $author_attr string */
        $post_object = $this->wp_post($matches);
        switch (true) {
            case ($post_item != ''):
                $output = $this->transclude_post_item($post_item, $post_object);
                break;
            case ($post_attr != ''):
                $output = $this->transclude_post_attr($post_attr, $post_object);
                break;
            case ($author_item != ''):
                $output = $this->transclude_author_item($author_item, $post_object);
                break;
            case ($author_attr != ''):
                $output = $this->transclude_author_attr($author_attr, $post_object);
                break;
            default:
                $output = null;
                break;
        }
        return $output;
    }

    /**
     * @param array $matches
     *
     * @return string
     */
    protected
    function strip_blanks( array $matches )
    {
        $this->default_empty($matches, 'before_string', 'string', 'anything_else');
        extract($matches);
        /* @var $before_string string */
        /* @var $string string */
        /* @var $anything_else string */
        $outside = $string
                ? $before_string
                : $anything_else;
        $result = preg_replace($this->e_blank, '', $outside) . $string;
        return $result;
    }

    protected
    function clean_up( $sequence )
    {
        $result = $sequence;

        // erase comments
        $result = preg_replace($this->e_comment, '', $result);

        // erase blanks (except inside strings)
        $result = preg_replace_callback($this->e_string, array($this, 'strip_blanks'), $result);

        // erase backslashes from escaped injection delimiters
        $result = preg_replace($this->e_escaped_injection_delimiter, '$1', $result);

        return $result;
    }

    /**
     * @param string $could_be_sequence
     *
     * @return array|null|string
     */
    protected
    function process( $could_be_sequence )
    {
        $sequence = $this->clean_up($could_be_sequence);
        $there_are_only_chained_enzymes = preg_match($this->e_sequence_valid, '|' . $sequence);
//        $this->debug_print($this->e_sequence_valid . '');
        if ( ! $there_are_only_chained_enzymes ) {
            $result = '{[' . $could_be_sequence . ']}';  // skip this injection like if it had been escaped...
        } else {
            $this->catalyzed = new Sequence();
            $rest = $sequence;
            while (preg_match($this->e_sequence_start, $rest, $matches)) {
                $this->default_empty($matches, 'execution', 'transclusion', 'literal', 'str_literal', 'number', 'rest');
                extract($matches);
                /* @var $execution string */
                /* @var $transclusion string */
                /* @var $literal string */
                /* @var $str_literal string */
                /* @var $number string */
                /* @var $rest string */
                switch (true) {
                    case $execution != '':
                        $argument = $this->do_execution($matches);
                        break;
                    case $transclusion != '':
                        $argument = $this->do_transclusion($matches);
                        break;
                    case $literal != '':
                        $argument = $str_literal
                                ? $this->unquote($str_literal)
                                : floatval($number);
                        break;
                    default:
                        $argument = null;
                        break;
                }
                $this->catalyzed->push($argument);
            }
            list($result) = $this->catalyzed->peek();
        }
        return $result;
    }

    /**
     * @param string $content
     * @param array  $matches
     *
     * @return bool
     */
    protected
    function there_is_an_injection( $content, &$matches )
    {
        $result = false !== strpos($content, '{[') && preg_match($this->e_injection, $content, $matches);
        return $result;
    }

    /**
     * @param string       $content
     * @param null|WP_Post $default_post
     *
     * @return array|null|string
     */
    public
    function metabolize( $content, $default_post = null )
    {
        $this->post = is_object($default_post)
                ? $default_post
                : get_post();
        if ( is_null($this->post) ) {
            return $content;
        }
        if ( ! author_can($this->post, 'inject_enzymes') ) {
            return $content;
        }
        if ( ! $this->there_is_an_injection($content, $matches) ) {
            return $content;
        }
        $this->new_content = '';
        do {
            $this->default_empty($matches, 'before', 'could_be_sequence', 'after');
            extract($matches);
            /* @var $before string */
            /* @var $could_be_sequence string */
            /* @var $after string */
            $escaped_injection = '{' == substr($before, -1);  // "{{[ .. ]}"
            if ( $escaped_injection ) {
                $result = '[' . $could_be_sequence . ']}';  // consume one brace of the pair
            } else {
                $result = $this->process($could_be_sequence);
            }
            $this->new_content .= $before . $result;
        } while ($this->there_is_an_injection($after, $matches));
        $result = $this->new_content . $after;

        return $result;
    }

    protected
    function add_roles_and_capabilities()
    {
        $capabilities = array(
                'inject_enzymes'      => 'it allows a post author to inject enzymes',
                'create_html_enzymes' => 'it allows her to use her non_evaluated enzymes',
                'create_php_enzymes'  => 'it allows her to use her evaluated enzymes',
                'share_html_enzymes'  => 'it allows others to use her non_evaluated enzymes',
                'share_php_enzymes'   => 'it allows others to use her evaluated enzymes',
        );

        remove_role('enzymes_user');
        add_role('enzymes_user', __('Enzymes User'), array('inject_enzymes' => true));

        remove_role('client_enzymes_coder');
        add_role('client_enzymes_coder', __('Client Enzymes Coder'),
                 array('inject_enzymes' => true, 'create_html_enzymes' => true, 'share_html_enzymes' => true));

        remove_role('server_enzymes_coder');
        add_role('server_enzymes_coder', __('Server Enzymes Coder'),
                 array('inject_enzymes' => true, 'create_php_enzymes' => true, 'share_php_enzymes' => true));

        global $wp_roles;
        /* @var $wp_roles WP_Roles */
        $wp_roles->add_cap('administrator', 'inject_enzymes');
        $wp_roles->add_cap('administrator', 'create_php_enzymes');
        $wp_roles->add_cap('administrator', 'share_php_enzymes');
//        $admins = get_users(array('role' => 'administrator')); /* @var $admins WP_User[] */
//        foreach ($admins as $admin) {
//            $admin->add_role('server_enzymes_coder');
//        }
    }

//    protected
//    function is_trusted( $user_id )
//    {
//        $admin_id = 1;
//        $result = $user_id == $admin_id;
//        if ( $result ) {
//            return $result;
//        }
//
//        list($trusted_users) = trim(get_user_meta($admin_id, array('field' => 'enzymes-trusted-users')));
//        $result = strpos(" $trusted_users ", $user_id) !== false;
//        if ( $result ) {
//            return $result;
//        }
//
//        list($trusted_roles) = trim(get_user_meta($admin_id, array('field' => 'enzymes-trusted-roles')));
//        $trusted_roles = explode(' ', $trusted_users);
//        $user_roles = $this->wp_roles($user_id);
//
//    }
}
