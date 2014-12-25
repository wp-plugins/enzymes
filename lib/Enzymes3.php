<?php

require_once 'Ando/Regex.php';

class Enzymes3
{
    protected $post = '';  // the post which the content belongs to
    protected $new_content = '';  // the content of the post, modified by Enzymes

    protected $e_comment = '';
    protected $e_blank = '';
    protected $e_escaped_injection_delimiter = '';
    protected $e_escaped_string_delimiter = '';
    protected $e_maybe_id = '';

    protected $find_injection = '';
    protected $find_sequence_valid = '';
    protected $find_sequence_start = '';
    protected $find_string = '';

    /**
     * Grammar (top down).
     * ---
     * injection  := "{[" sequence "]}"
     *   sequence := enzyme ("|" enzyme)*
     *   enzyme   := literal | transclusion | execution
     *
     * literal   := number | string
     *   number  := \d+(\.\d+)?
     *   string  := "=" <a string where "=", "|", "]}", "\"  are escaped by a prefixed "\"> "="
     *
     * transclusion := item | post "~author:" attribute | post ":" attribute
     *   item       := post "." field
     *   post       := \d+ | "@" slug | ""
     *   slug       := [\w+~-]+
     *   field      := [\w-]+ | string
     *   attribute  := \w+
     *
     * execution := ("array" | "hash" | item) "(" \d* ")"
     * ---
     *
     * These (key, value) pairs follow the pattern: "'rule_left' => '(?<rule_left>rule_right)';".
     *
     * @var Ando_Regex[]
     */
    protected $grammar;

    protected
    function init_grammar()
    {
        /**
         * Notice that $grammar rules are sorted bottom up here to allow complete interpolation.
         */
        $grammar = array(
                'number'       => '(?<number>\d+(\.\d+)?)',
                'string'       => '(?<string>' . Ando_Regex::pattern_quoted_string('=', '=') . ')',
                'literal'      => '(?<literal>$number|$string)',

                'slug'         => '(?<slug>[\w+~-]+)',
                'attribute'    => '(?<attribute>\w+)',
                'post'         => '(?<post>\d+|@$slug|)',
                'field'        => '(?<field>[\w-]+|$string)',
                'item'         => '(?<item>$post\.$field)',
                'transclusion' => '(?<transclusion>$item|$post~author:$attribute|$post:$attribute)',

                'execution'    => '(?<execution>(?:\barray\b|\bhash\b|$item)\((?<num_args>\d*)\))',

                'enzyme'       => '(?<enzyme>(?:$literal|$transclusion|$execution))',
                'sequence'     => '(?<sequence>$enzyme(\|$enzyme)*)',
                'injection'    => '(?<injection>{[$sequence]})',
        );
        $result = array();
        foreach ($grammar as $symbol => $rule) {
            $regex = new Ando_Regex($rule);
            $result[$symbol] = $regex->interpolate($grammar);
        }
        $this->grammar = $result;
    }

    protected
    function init_find_injection()
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
        $this->find_injection = $content;
    }

    protected
    function init_find_sequence_valid()
    {
        // Notice that sequence_valid matches all the enzymes of the sequence at once.
        $sequence_valid = new Ando_Regex('^(?:\|$enzyme)+$', '@@');
        $sequence_valid->interpolate(array(
                                             'enzyme' => $this->grammar['enzyme'],
                                     ));
        $this->find_sequence_valid = $sequence_valid;
    }

    protected
    function init_find_sequence_start()
    {
        $rest = new Ando_Regex('(?:\|(?<rest>.+))');
        $sequence_start = new Ando_Regex('^$enzyme$rest?$');
        $sequence_start->interpolate(array(
                                             'enzyme' => $this->grammar['enzyme'],
                                             'rest'   => $rest,
                                     ));
        $this->find_sequence_start = $sequence_start->wrapper_set('@@');
    }

    protected
    function init_find_string()
    {
        $maybe_quoted = new Ando_Regex('(.*?)($quoted)|(.+)', '@@s');
        $maybe_quoted->interpolate(array(
                                           'quoted' => $this->grammar['string'],
                                   ));
        $this->find_string = $maybe_quoted;
    }

    protected
    function init_expressions()
    {
        $this->e_comment = new Ando_Regex('(\/\*.*?\*\/)', '@@');
        // for some reason WP introduces some C2 (hex) chars when writing a post...
        $this->e_blank = new Ando_Regex('(?:\s|\xc2)+', '@@');
        $this->e_escaped_injection_delimiter = new Ando_Regex('\\\\([{[\]}])', '@@');
        $this->e_escaped_string_delimiter = new Ando_Regex('\\\\=', '@@');
        $this->e_maybe_id = new Ando_Regex('(@[\w\-]+)?~(\w+)', '//');
    }

    public
    function __construct()
    {
        $this->init_grammar();
        $this->init_find_injection();
        $this->init_find_sequence_valid();
        $this->init_find_sequence_start();
        $this->init_find_string();
        $this->init_expressions();
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
     * @return WP_Post
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
    function wp_custom_field( $post_object, array $matches )
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

    /**
     * @param array $matches
     *
     * @return array|null
     */
    protected
    function do_execution( array $matches )
    {
        $this->default_empty($matches, 'execution', 'item', 'post', 'field', 'num_args');
        extract($matches);
        /* @var $execution string */
        /* @var $item string */
        /* @var $post string */
        /* @var $field string */
        /* @var $num_args string */
        $num_args = (int) $num_args;
        switch (true) {
            case (strpos($execution, 'array(') === 0):
                if ( 0 == $num_args ) {
                    break;
                }
                $result = $this->catalyzed->pop($num_args);
                break;
            case (strpos($execution, 'hash(') === 0):
                if ( 0 == $num_args ) {
                    break;
                }
                $result = array();
                $arguments = $this->catalyzed->pop(2 * $num_args);
                for ($i = 0, $i_top = 2 * $num_args; $i < $i_top; $i += 2) {
                    $key = $arguments[$i];
                    $value = $arguments[$i + 1];
                    $result[$key] = $value;
                }
                break;
            case ($item != ''):
                $post_object = $this->wp_post($post);
                $code = $this->wp_custom_field($post_object, $field);
                // We allow PHP execution by default, and optionally some HTML code properly unwrapped off PHP tags.
                $arguments = $num_args > 0
                        ? $this->catalyzed->pop($num_args)
                        : array();
                list($result,) = $this->safe_eval($code, $arguments);
                break;
            default:
                $result = null;
                break;
        }
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
        $this->default_empty($matches, 'transclusion', 'item', 'post', 'field', 'attribute');
        extract($matches);
        /* @var $transclusion string */
        /* @var $item string */
        /* @var $post string */
        /* @var $field string */
        /* @var $attribute string */
        $post_object = $this->wp_post($post);
        switch (true) {
            case (strpos($transclusion, '~author:') !== false):
                $user_object = $this->wp_author($post_object);
                $output = @$user_object->$attribute;  // @link http://codex.wordpress.org/Function_Reference/get_userdata
                break;
            case ($attribute != ''):
                $output = @$post_object->$attribute;  // @link http://codex.wordpress.org/Class_Reference/WP_Post
                break;
            case ($item != ''):
                $code = $this->wp_custom_field($post_object, $field);
                // We allow HTML transclusion by default, and optionally some PHP code properly wrapped into PHP tags.
                list(, $output) = $this->safe_eval(" ?>$code<?php ");
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
        list(, $before_string, $string, $whatever) = $matches;
        $outside = $string
                ? $before_string
                : $whatever;
        $result = preg_replace($this->e_blank, '', $outside) . $string;
        return $result;
    }

    /**
     * @param string $content
     * @param array  $matches
     *
     * @return bool
     */
    protected
    function there_is_an_injection( $content, array &$matches )
    {
        $result = false !== strpos($content, '{[') && preg_match($this->find_injection, $content, $matches);
        return $result;
    }

    protected
    function clean_up( $sequence )
    {
        $result = $sequence;

        // erase comments
        $result = preg_replace($this->e_comment, '', $result);

        // erase blanks (except inside strings)
        $result = preg_replace_callback($this->find_string, array($this, 'strip_blanks'), $result);

        // erase backslashes from escaped injection delimiters
        $result = preg_replace($this->e_escaped_injection_delimiter, '$1', $result);

        return $result;
    }

    /**
     * Sequence of catalyzed enzymes, which are meant to be used as arguments for other enzymes.
     *
     * @var Sequence
     */
    protected $catalyzed;

    /**
     * @param string $could_be_sequence
     *
     * @return array|null|string
     */
    protected
    function process( $could_be_sequence )
    {
        $sequence = $this->clean_up($could_be_sequence);
        $there_are_only_chained_enzymes = preg_match($this->find_sequence_valid, '|' . $sequence);
        if ( ! $there_are_only_chained_enzymes ) {
            $result = '{[' . $sequence . ']}';  // like if it had been escaped...
        } else {
            $this->catalyzed = new Sequence();
            $rest = $sequence;
            while (preg_match($this->find_sequence_start, $rest, $matches)) {
                $this->default_empty($matches, 'execution', 'transclusion', 'literal', 'string', 'number', 'rest');
                extract($matches);
                /* @var $execution string */
                /* @var $transclusion string */
                /* @var $literal string */
                /* @var $string string */
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
                        $argument = $string
                                ? $this->unquote($string)
                                : floatval($number);
                        break;
                    default:
                        $argument = null;
                        break;
                }
                $this->catalyzed->push($argument);
            }
            $result = $this->catalyzed->peek();
        }
        return $result;
    }

    /**
     * @param string $content
     * @param null   $default_post
     *
     * @return array|null|string
     */
    public
    function metabolize( $content, $default_post = null )
    {
        if ( ! $this->there_is_an_injection($content, $matches) ) {
            return $content;
        }
        $this->post = is_object($default_post)
                ? $default_post
                : get_post();
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
}
