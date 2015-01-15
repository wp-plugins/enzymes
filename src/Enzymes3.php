<?php
require_once dirname(ENZYMES_FILENAME) . '/vendor/Ando/Regex.php';
require_once 'EnzymesSequence.php';
require_once 'EnzymesCapabilities.php';
require_once 'EnzymesOptions.php';

class Enzymes3
{
    /**
     *  When calling the engine directly, for forcing the global post, use one of the following:
     * - EnzymesPlugin::engine()->metabolize($content);
     * - EnzymesPlugin::engine()->metabolize($content, null);
     * - EnzymesPlugin::engine()->metabolize($content, Enzymes3::GLOBAL_POST);
     */
    const GLOBAL_POST = null;

    /**
     * When calling the engine directly, for forcing no post at all, use one of the following:
     * - EnzymesPlugin::engine()->metabolize($content, Enzymes3::NO_POST);
     */
    const NO_POST = -1;

    /**
     * When calling the engine directly, ID of the user to consider the author after forcing no post.
     */
    const NO_POST_AUTHOR = 1;

    /**
     * @var EnzymesOptions
     */
    protected $options;

    /**
     * Current sequence.
     *
     * @var string
     */
    protected $current_sequence;

    /**
     * Current enzyme.
     *
     * @var string
     */
    protected $current_enzyme;

    /**
     * The post which the content belongs to.
     * It can be null if the developer forced no post with ->metabolize($content, Enzymes3::NO_POST).
     *
     * @var WP_Post
     */
    protected $injection_post;

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
     * transclusion := item | attr
     *   item        := post_item | author_item
     *   attr        := post_attr | author_attr
     *   post_item   := post "." field
     *   author_item := post "/author." field
     *   post_attr   := post ":" field
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
     * Last error in eval.
     *
     * @var
     */
    protected $last_eval_error;

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
        $result  = array();
        foreach ($grammar as $symbol => $rule) {
            $regex           = new Ando_Regex($rule);
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
        $before             = new Ando_Regex('(?<before>.*?)');
        $could_be_injection = new Ando_Regex('\{\[(?<could_be_sequence>.*?)\]\}');
        $after              = new Ando_Regex('(?<after>.*)');
        $content            = new Ando_Regex('^$before$could_be_injection$after$', '@@s');
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
        $rest           = new Ando_Regex('(?:\|(?<rest>.+))');
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
        $this->e_unbreakable_space           = new Ando_Regex('\xC2\xA0', '@@');
        $this->e_all_spaces                  = new Ando_Regex('(?:\s|\xC2\xA0)+', '@@');
        $this->e_escaped_injection_delimiter = new Ando_Regex('\\\\([{[\]}])', '@@');
        $this->e_escaped_string_delimiter    = new Ando_Regex('\\\\=', '@@');
    }

    /**
     * Bootstrap the engine.
     */
    public
    function __construct()
    {
        $this->init_grammar();
        $this->init_expressions();
        $this->options = new EnzymesOptions();
    }

    /**
     * Convert a grammar rule to a usable regex.
     *
     * @param string $rule
     * @param bool   $same_name
     *
     * @return string
     * @throws Ando_Exception
     */
    protected
    function grammar_rule( $rule, $same_name = true )
    {
        $result = $this->grammar[$rule]->wrapper_set('@@')
                                       ->expression(true);
        if ( $same_name ) {
            $result = substr_replace($result, Ando_Regex::option_same_name(), 1, 0);
        }
        return $result;
    }

    /**
     * Echo a script HTML tag to write data to the javascript console of the browser.
     *
     * @param mixed $data
     */
    protected
    function console_log( $data )
    {
        $json   = json_encode((is_array($data) || is_object($data))
                ? $data
                : trim($data));
        $output = "<script>if(window.console){if(window.console.log){window.console.log($json);}}</script>";
        echo $output;
    }

    /**
     * Handle an error in eval.
     *
     * @param $type
     * @param $message
     * @param $file
     * @param $line
     *
     * @return bool
     */
    protected
    function set_last_eval_error( $type, $message, $file, $line )
    {
        $this->last_eval_error = array('type' => $type, 'message' => $message, 'file' => $file, 'line' => $line);
        return true;
    }

    /**
     * Check the syntax of a code snippet.
     *
     * @param $code
     *
     * @return mixed|null|string
     */
    protected
    function php_lint( $code )
    {
        $result = null;
        if ( ! function_exists('shell_exec') ) {
            return $result;
        }
        $temp     = tmpfile();
        $meta     = stream_get_meta_data($temp);
        $filename = $meta['uri'];
        fwrite($temp, "<?php $code");
        $result = shell_exec("php -n -l $filename");  // -n = no ini, -l = only lint
        fclose($temp);

        $result = trim($result);
        $result = str_replace($filename, 'enzyme code', $result);
        $result = str_replace("\nErrors parsing enzyme code", '', $result);
        return $result;
    }

    /**
     * Evaluate code, putting arguments in the execution context ($this is always available).
     * Return an indexed array with the PHP returned value and possible error.
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
        $error        = $output = $exception = null;
        $previous_ini = array();

        $previous_ini['scream.enabled'] = ini_set('scream.enabled', false);
        set_error_handler(array($this, 'set_last_eval_error'), E_ALL);
        ob_start();
        try {
            $this->last_eval_error = null;
            // ---------------------------------------------------------------------------------------------------------
            $result = @eval($code);
            // ---------------------------------------------------------------------------------------------------------
            $error                 = $this->last_eval_error;
            $this->last_eval_error = null;
        } catch ( Exception $e ) {
            $result    = false;  // Let's force the same error treatment
            $exception = $e;     // but remember the exception now.
        }
        $output = ob_get_clean();
        restore_error_handler();
        ini_set('scream.enabled', $previous_ini['scream.enabled']);

        if ( false === $result ) {
            if ( null === $error && null === $exception ) {
                $error = $this->php_lint($code);
            }
            if ( null === $error ) {
                $error = $exception;
            }
        }
        // Notice that error can be null, array, string, or an Exception descendant.
        return array($result, $error, $output);
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
        $post = @$matches['post'];
        $slug = @$matches['slug'];
        switch (true) {
            case ($post == ''):
                $result = $this->injection_post;
                break;
            case ($post[0] == '@'):
                // We can't use the following API call because we want all post types.
                //$result = get_page_by_path($slug, OBJECT, 'post');
                global $wpdb;
                /* @var $wpdb wpdb */
                $post_id = $wpdb->get_var("SELECT `ID` FROM $wpdb->posts WHERE `post_name` = '$slug' LIMIT 1");
                $result  = get_post($post_id);
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
        $field  = @$matches['field'];
        $string = @$matches['string'];
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
        $field  = @$matches['field'];
        $string = @$matches['string'];
        if ( $string ) {
            $field = $this->unquote($field);
        }
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
        $field  = @$matches['field'];
        $string = @$matches['string'];
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
        $field  = @$matches['field'];
        $string = @$matches['string'];
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
        $id     = $post_object->post_author;
        $result = get_user_by('id', $id);
        return $result;
    }

    /**
     * True if the current post's author can exercise the capability.
     *
     * @param string $capability
     *
     * @return bool
     */
    protected
    function injection_author_can( $capability )
    {
        if ( is_null($this->injection_post) ) {
            $result = user_can(self::NO_POST_AUTHOR, $capability);
        } else {
            $result = author_can($this->injection_post, $capability);
        }
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
    function injection_author_owns( $post_object )
    {
        if ( is_null($this->injection_post) ) {
            $result = self::NO_POST_AUTHOR == $post_object->post_author;
        } else {
            $result = $this->injection_post->post_author == $post_object->post_author;
        }
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
             ($this->injection_author_owns($post_object) ||
              author_can($post_object, EnzymesCapabilities::share_dynamic_custom_fields) &&
              $this->injection_author_can(EnzymesCapabilities::use_others_custom_fields))
        ) {
            list($result, $error, $output) = $this->safe_eval($code, $arguments);
            if ( $error ) {
                $this->console_log(__('ENZYMES ERROR'));
                $this->console_log(sprintf(__('post: %1$s - enzyme: %3$s - injection: {[%2$s]}'),
                        $this->injection_post->ID, $this->current_sequence, $this->current_enzyme));
                $this->console_log($error);
                $result = null;
            }
            if ( $output ) {
                $this->console_log(__('ENZYMES OUTPUT'));
                $this->console_log(sprintf(__('post: %1$s - enzyme: %3$s - injection: {[%2$s]}'),
                        $this->injection_post->ID, $this->current_sequence, $this->current_enzyme));
                $this->console_log($output);
            }
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
        preg_match($this->grammar_rule('post_item'), $post_item, $matches);
        $post_object = $this->wp_post($matches);
        if ( ! $post_object instanceof WP_Post ) {
            return null;
        }
        $code      = $this->wp_post_field($post_object, $matches);
        $arguments = $num_args > 0
                ? $this->catalyzed->pop($num_args)
                : array();
        $result    = $this->execute_code($code, $arguments, $post_object);
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
        preg_match($this->grammar_rule('author_item'), $author_item, $matches);
        $post_object = $this->wp_post($matches);
        if ( ! $post_object instanceof WP_Post ) {
            return null;
        }
        $user_object = $this->wp_author($post_object);
        $code        = $this->wp_user_field($user_object, $matches);
        $arguments   = $num_args > 0
                ? $this->catalyzed->pop($num_args)
                : array();
        $result      = $this->execute_code($code, $arguments, $post_object);
        return $result;
    }

    /**
     * Execute the matched enzyme.
     *
     * @param string $execution
     *
     * @return array|null
     */
    protected
    function do_execution( $execution )
    {
        $this->current_enzyme = $execution;
        preg_match($this->grammar_rule('execution'), $execution, $matches);
        $post_item   = @$matches['post_item'];
        $author_item = @$matches['author_item'];
        $num_args    = (int) @$matches['num_args'];
        switch (true) {
            case (strpos($execution, 'array(') === 0 && $num_args > 0):
                $result = $this->catalyzed->pop($num_args);
                break;
            case (strpos($execution, 'hash(') === 0 && $num_args > 0):
                $result    = array();
                $arguments = $this->catalyzed->pop(2 * $num_args);
                for ($i = 0, $i_top = 2 * $num_args; $i < $i_top; $i += 2) {
                    $key          = $arguments[$i];
                    $value        = $arguments[$i + 1];
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
        if ( author_can($post_object, EnzymesCapabilities::create_static_custom_fields) &&
             ($this->injection_author_owns($post_object) ||
              author_can($post_object, EnzymesCapabilities::share_static_custom_fields) &&
              $this->injection_author_can(EnzymesCapabilities::use_others_custom_fields))
        ) {
            $result = $code;
        } else {
            $result = '';
        }
        return $result;
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
        preg_match($this->grammar_rule('post_item'), $post_item, $matches);
        $code   = $this->wp_post_field($post_object, $matches);
        $result = $this->transclude_code($code, $post_object);
        return $result;
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
        preg_match($this->grammar_rule('author_item'), $author_item, $matches);
        $user_object = $this->wp_author($post_object);
        $code        = $this->wp_user_field($user_object, $matches);
        $result      = $this->transclude_code($code, $post_object);
        return $result;
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
        $same_author = $this->injection_author_owns($post_object);
        if ( $same_author && author_can($post_object, EnzymesCapabilities::use_own_attributes) ||
             ! $same_author &&
             $this->injection_author_can(EnzymesCapabilities::use_others_attributes)
        ) {
            preg_match($this->grammar_rule('post_attr'), $post_attr, $matches);
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
        $same_author = $this->injection_author_owns($post_object);
        if ( $same_author && author_can($post_object, EnzymesCapabilities::use_own_attributes) ||
             ! $same_author &&
             $this->injection_author_can(EnzymesCapabilities::use_others_attributes)
        ) {
            preg_match($this->grammar_rule('author_attr'), $author_attr, $matches);
            $user_object = $this->wp_author($post_object);
            $result      = $this->wp_user_attribute($user_object, $matches);
        } else {
            $result = null;
        }
        return $result;
    }

    /**
     * Transclude the matched enzyme.
     *
     * @param string $transclusion
     *
     * @return null|string
     */
    protected
    function do_transclusion( $transclusion )
    {
        $this->current_enzyme = $transclusion;
        preg_match($this->grammar_rule('transclusion'), $transclusion, $matches);
        $post_item   = @$matches['post_item'];
        $post_attr   = @$matches['post_attr'];
        $author_item = @$matches['author_item'];
        $author_attr = @$matches['author_attr'];
        $post_object = $this->wp_post($matches);
        if ( ! $post_object instanceof WP_Post ) {
            return null;
        }
        switch (true) {
            case ($post_item != ''):
                $result = $this->transclude_post_item($post_item, $post_object);
                break;
            case ($post_attr != ''):
                $result = $this->transclude_post_attr($post_attr, $post_object);
                break;
            case ($author_item != ''):
                $result = $this->transclude_author_item($author_item, $post_object);
                break;
            case ($author_attr != ''):
                $result = $this->transclude_author_attr($author_attr, $post_object);
                break;
            default:
                $result = null;
                break;
        }
        return $result;
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
        $before_string = @$matches['before_string'];
        $string        = @$matches['string'];
        $anything_else = @$matches['anything_else'];
        $outside       = $string
                ? $before_string
                : $anything_else;
        $result        = preg_replace($this->e_all_spaces, '', $outside) .
                         preg_replace($this->e_unbreakable_space, ' ',
                                 $string);  // normal spaces are meaningful in $string
        return $result;
    }

    /**
     * Remove noise from a sequence.
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
     * Detect the version of the injection post.
     *
     * @return int
     */
    protected
    function default_version()
    {
        // The injection_post at this point of the execution can be null only in version 3 because
        // in version 2 it follows a different path, i.e. it would go straight into the Enzymes class
        // by means of a call to the global metabolize() function. --
        if ( is_null($this->injection_post) ) {
            return 3;
        }
        // By looking at these dates we can only assume a default version, because another one
        // could have been forced by the user right into an injection. --
        $result = $this->injection_post->post_date_gmt <= EnzymesPlugin::activated_on()
                ? 2
                : 3;
        return $result;
    }

    /**
     * Detect the version of an injection with a sequence.
     * Remove the forced version from the sequence, if that was the case.
     *
     * @param string $sequence
     *
     * @return int
     */
    protected
    function sequence_version( &$sequence )
    {
        $result          = $this->default_version();
        $forced_2_prefix = '=enzymes 2=|';
        $forced_3_prefix = '=enzymes 3=|';
        switch (true) {
            case (0 === strpos($sequence, $forced_2_prefix)):
                $sequence = substr($sequence, strlen($forced_2_prefix));
                $result   = 2;
                break;
            case (0 === strpos($sequence, $forced_3_prefix)):
                $sequence = substr($sequence, strlen($forced_3_prefix));
                $result   = 3;
                break;
        }
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
        $sequence                       = $this->clean_up($could_be_sequence);
        $there_are_only_chained_enzymes = preg_match($this->e_sequence_valid, '|' . $sequence);
        if ( ! $there_are_only_chained_enzymes ) {
            $result = '{[' . $could_be_sequence . ']}';  // skip this injection AS IS
        } elseif ( $this->sequence_version($sequence) == 2 ) {
            $result = '{[' . $sequence . ']}';           // skip this injection
            // after stripping out the forced version from $sequence, it any
        } else {
            $this->current_sequence = $could_be_sequence;
            $this->catalyzed        = new EnzymesSequence();
            $rest                   = $sequence;
            while (preg_match($this->e_sequence_start, $rest, $matches)) {
                $execution    = @$matches['execution'];
                $transclusion = @$matches['transclusion'];
                $literal      = @$matches['literal'];
                $str_literal  = @$matches['str_literal'];
                $number       = @$matches['number'];
                $rest         = @$matches['rest'];
                switch (true) {
                    case $execution != '':
                        $argument = $this->do_execution($execution);
                        break;
                    case $transclusion != '':
                        $argument = $this->do_transclusion($transclusion);
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
     * Get the post the injection belongs to.
     * It can be null when forced to NO_POST.
     *
     * @param $args
     *
     * @return array
     */
    protected
    function get_injection_post( $args )
    {
        list(, $post) = array_pad($args, 2, null);
        if ( $post instanceof WP_Post ) {
            return array(true, $post);
        }
        if ( $post == self::NO_POST ) {
            return array(true, null);
        }
        // Some filters of ours do not pass the 2nd argument, while others pass a post ID, but
        // 'wp_title' pass a string separator, so we fix this occurrence.
        $post_id = current_filter() == 'wp_title'
                ? null
                : $post;
        $post    = get_post($post_id);
        if ( is_null($post) ) {
            // Consider this an error, because the developer didn't force no post.
            return array(false, null);
        }
        return array(true, $post);
    }

    /**
     * Process the injected sequences in the content we are filtering.
     *
     * @param string $content
     *
     * @return array|null|string
     */
    public
    function metabolize( $content )
    {
        $args = func_get_args();
        list($continue, $this->injection_post) = $this->get_injection_post($args);
        if ( ! $continue ) {
            return $content;
        }
        if ( ! $this->injection_author_can(EnzymesCapabilities::inject) ) {
            return $content;
        }
        if ( ! $this->there_is_an_injection($content, $matches) ) {
            return $content;
        }
        $this->new_content = '';
        do {
            $before            = @$matches['before'];
            $could_be_sequence = @$matches['could_be_sequence'];
            $after             = @$matches['after'];
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

    // -----------------------------------------------------------------------------------------------------------------

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
}
