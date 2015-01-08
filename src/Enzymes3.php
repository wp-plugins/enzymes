<?php
require_once dirname(ENZYMES_FILENAME) . '/vendor/Ando/Regex.php';
require_once 'EnzymesSequence.php';
require_once 'EnzymesCapabilities.php';
require_once 'EnzymesOptions.php';

class Enzymes3
{
    const PREFIX = 'enzymes.';

    /**
     * @var EnzymesOptions
     */
    protected $options;

    /**
     * @var bool
     */
    public $debug_on = false;

    /**
     * @param mixed $something
     */
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
    protected $current_post;

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
     * Regular expression for matching unbreakable space characters (\xC2\xA0).
     * These characters appear sporadically and unpredictably into WordPress.
     *
     * @var Ando_Regex
     */
    protected $e_unbreakable_space;

    /**
     * Regular expression for matching spaces and unbreakable space characters.
     *
     * @var Ando_Regex
     */
    protected $e_all_spaces;

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
     * @var EnzymesSequence
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
        $this->e_unbreakable_space = new Ando_Regex('\xC2\xA0', '@@');
        $this->e_all_spaces = new Ando_Regex('(?:\s|\xC2\xA0)+', '@@');
        $this->e_escaped_injection_delimiter = new Ando_Regex('\\\\([{[\]}])', '@@');
        $this->e_escaped_string_delimiter = new Ando_Regex('\\\\=', '@@');
    }

    public
    function __construct()
    {
        $this->init_grammar();
        $this->init_expressions();
        $this->options = new EnzymesOptions(self::PREFIX);
    }

    /**
     * Set to the empty string all keys passed as additional arguments.
     *
     * @param array $hash
     */
    protected
    function default_empty( array &$hash )
    {
        $keys = func_get_args();
        array_shift($keys);
        $default = array_fill_keys($keys, '');
        $hash = array_merge($default, $hash);
    }

    /**
     * Evaluate code, putting arguments in the execution context ($this is always available).
     * Return an indexed array with the PHP returned value (result) and output buffering contents (output).
     *
     * Inside the code, the arguments can easily be accessed with an expression like this:
     *   list($some, $variables) = $arguments;
     *
     * @param string $code
     * @param array  $arguments
     *
     * @return array
     */
    protected
    function safe_eval( $code, array $arguments = array() )
    {
        ob_start();
        $result = eval($code);
        $output = ob_get_contents();
        ob_end_clean();
        return array($result, $output);
    }

    /**
     * Get the matched post object.
     *
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
                $result = $this->current_post;
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
     * Unwrap an enzymes string from its quotes, while also un-escaping escaped quotes.
     *
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
     * Get the matched custom field from the post object.
     *
     * @param WP_Post $post_object
     * @param array   $matches
     *
     * @return mixed
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
     * Get the matched attribute from the post object.
     *
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
//        if ( ! property_exists($post_object, $field) ) {
//            return "($field)";
//        }
        $result = @$post_object->$field;
        return $result;
    }

    /**
     * Get the matched custom field from the user object.
     *
     * @param WP_User $user_object
     * @param array   $matches
     *
     * @return mixed
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
     * Get the matched attribute from the user object.
     *
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
     * Get the author of the post.
     *
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
     * True if the post belongs to the current post's author.
     *
     * @param WP_Post $post_object
     *
     * @return bool
     */
    protected
    function belongs_to_current_author( $post_object )
    {
        $result = $this->current_post->post_author == $post_object->post_author;
        return $result;
    }

    /**
     * Execute code according to authors capabilities.
     *
     * @param string  $code
     * @param array   $arguments
     * @param WP_Post $post_object
     *
     * @return null
     */
    protected
    function execute_code( $code, $arguments, $post_object )
    {
        if ( author_can($post_object, EnzymesCapabilities::create_dynamic_custom_fields) &&
             ($this->belongs_to_current_author($post_object) ||
              author_can($post_object, EnzymesCapabilities::share_dynamic_custom_fields) &&
              author_can($this->current_post, EnzymesCapabilities::use_others_custom_fields))
        ) {
            list($result,) = $this->safe_eval($code, $arguments);
        } else {
            $result = null;
        }
        return $result;
    }

    /**
     * Execute a custom field from a post.
     *
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
     * Execute a custom field from a user.
     *
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
     * Execute the matched enzyme.
     *
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

    /**
     * Transclude code according to authors capabilities.
     *
     * @param string  $code
     * @param WP_Post $post_object
     *
     * @return string
     */
    protected
    function transclude_code( $code, $post_object )
    {
        if ( author_can($post_object, EnzymesCapabilities::create_dynamic_custom_fields) &&
             ($this->belongs_to_current_author($post_object) ||
              author_can($post_object, EnzymesCapabilities::share_dynamic_custom_fields) &&
              author_can($this->current_post, EnzymesCapabilities::use_others_custom_fields))
        ) {
            list(, $output) = $this->safe_eval(" ?>$code<?php ");
        } elseif ( author_can($post_object, EnzymesCapabilities::create_static_custom_fields) &&
                   ($this->belongs_to_current_author($post_object) ||
                    author_can($post_object, EnzymesCapabilities::share_static_custom_fields) &&
                    author_can($this->current_post, EnzymesCapabilities::use_others_custom_fields))
        ) {
            $output = $code;
        } else {
            $output = '';
        }
        return $output;
    }

    /**
     * Transclude a custom field from a post.
     *
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
     * Transclude a custom field from a user.
     *
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
     * Transclude an attribute from a post.
     *
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
        $same_author = $this->belongs_to_current_author($post_object);
        if ( $same_author && author_can($post_object, EnzymesCapabilities::use_own_attributes) ||
             ! $same_author && author_can($this->current_post, EnzymesCapabilities::use_others_attributes)
        ) {
            $expression = $this->grammar['post_attr']->wrapper_set('@@')
                                                     ->expression(true);
            preg_match($expression, $post_attr, $matches);
            $result = $this->wp_post_attribute($post_object, $matches);
        } else {
            $result = null;
        }
        return $result;
    }

    /**
     * Transclude an attribute from a user.
     *
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
        $same_author = $this->belongs_to_current_author($post_object);
        if ( $same_author && author_can($post_object, EnzymesCapabilities::use_own_attributes) ||
             ! $same_author && author_can($this->current_post, EnzymesCapabilities::use_others_attributes)
        ) {
            $expression = $this->grammar['author_attr']->wrapper_set('@@')
                                                       ->expression(true);
            preg_match($expression, $author_attr, $matches);
            $user_object = $this->wp_author($post_object);
            $result = $this->wp_user_attribute($user_object, $matches);
        } else {
            $result = null;
        }
        return $result;
    }

    /**
     * Transclude the matched enzyme.
     *
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
     * Remove white space from the matched sequence.
     *
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
        $result = preg_replace($this->e_all_spaces, '', $outside) .
                  preg_replace($this->e_unbreakable_space, ' ', $string);  // normal spaces are meaningful in $string
        return $result;
    }

    /**
     * Remove noise the matched sequence.
     *
     * @param string $sequence
     *
     * @return string
     */
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
     * Process the enzymes in the matched sequence.
     *
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
            $this->catalyzed = new EnzymesSequence();
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
     * True if there is an injected sequence.
     *
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
     * Process the injected sequences in the content we are filtering.
     *
     * @param string       $content
     * @param null|WP_Post $default_post
     *
     * @return array|null|string
     */
    public
    function metabolize( $content, $default_post = null )
    {
        $this->current_post = is_object($default_post)
                ? $default_post
                : get_post();
        if ( is_null($this->current_post) ) {
            return $content;
        }
        if ( ! author_can($this->current_post, EnzymesCapabilities::inject) ) {
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
}
