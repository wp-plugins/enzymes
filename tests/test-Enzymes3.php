<?php
require_once dirname(__FILE__) . '/../vendor/Ando/ErrorFactory.php';

class Enzymes3Test
        extends WP_UnitTestCase
{
    /**
     * @var string
     */
    protected $class;

    /**
     * @var ReflectionClass
     */
    protected $reflection;

    /**
     * @param string $name
     *
     * @return null|ReflectionMethod
     */
    protected
    function get_method( $name )
    {
        if ( is_null($this->reflection) ) {
            $this->class      = str_replace('Test', '', __CLASS__);
            $this->reflection = new \ReflectionClass($this->class);
        }
        if ( ! $this->reflection->hasMethod($name) ) {
            return null;
        }
        $method = $this->reflection->getMethod($name);
        $method->setAccessible(true);
        return $method;
    }

    /**
     * @param      $name
     * @param null $args
     * @param null $object
     *
     * @return mixed
     * @throws Exception
     */
    protected
    function call_method( $name, $args = null, $object = null )
    {
        $method = $this->get_method($name);
        if ( ! $method instanceof ReflectionMethod ) {
            throw new Exception(sprintf('"%s" is not a method of "%s".', $name, $this->class));
        }
        if ( is_null($args) ) {
            $args = array();
        }
        if ( is_null($object) ) {
            $object = new $this->class;
        }
        $result = $method->invokeArgs($object, $args);
        return $result;
    }

    public
    function setUp()
    {
        parent::setUp();

        $admin_id = $this->factory->user->create(array(
                'role' => 'administrator'
        ));
        global $current_user;
        $current_user = new WP_User($admin_id);

        $global_post_id = $this->factory->post->create(array(
                'post_author' => $admin_id,
                'post_title'  => 'This is the global post.'
        ));
        global $post;
        $post = get_post($global_post_id);
    }

    public static
    function setUpBeforeClass()
    {
        if ( function_exists('xdebug_disable') ) {
            xdebug_disable();
        }
    }

    public static
    function tearDownAfterClass()
    {
        if ( function_exists('xdebug_enable') ) {
            xdebug_enable();
        }
    }

    public
    function test_an_escaped_injection_is_ignored()
    {
        $enzymes = new Enzymes3();

//        $enzymes->debug_on = true;
//        $enzymes->debug_print(get_post()->post_title);
//        $enzymes->debug_on = false;

        $content1 = 'This is something before {{[ whatever ]} and this is after.';
        $content2 = 'This is something before {[ whatever ]} and this is after.';
        $this->assertEquals($content2, $enzymes->metabolize($content1));
    }

    public
    function test_content_with_no_injections_is_not_filtered()
    {
        $enzymes = new Enzymes3();

        $content = 'This is some content with no injections.';
        $this->assertEquals($content, $enzymes->metabolize($content));
    }

    public
    function test_content_with_injections_is_filtered()
    {
        // compare with test_dangling_enzymes_are_ignored

        $mock = $this->getMockBuilder('Enzymes3')
                     ->setMethods(array('process'))
                //->disableOriginalConstructor()
                     ->getMock();
        $mock->expects($this->any())
             ->method('process')
             ->will($this->returnValue('"Hello, World!"'));

        $content1 = 'This is something before {[ whatever ]} and in between {[ whatever else ]} but this is after.';
        $content2 = 'This is something before "Hello, World!" and in between "Hello, World!" but this is after.';
        $this->assertEquals($content2, $mock->metabolize($content1));
    }

    public
    function test_dangling_enzymes_are_ignored()
    {
        // compare with test_content_with_injections_is_filtered

        $mock = $this->getMockBuilder('Enzymes3')
                     ->setMethods(array('process'))
                //->disableOriginalConstructor()
                     ->getMock();
        $mock->expects($this->any())
             ->method('process')
             ->will($this->returnValue('"Hello, World!"'));

        global $post;
        $post = null;

        $content1 = 'This is something before {[ whatever ]} and this is after.';
        $content2 = 'This is something before {[ whatever ]} and this is after.';
        $this->assertEquals($content2, $mock->metabolize($content1));
    }

    public
    function test_safe_eval_no_error()
    {
        $code = '
            list($name) = $arguments;
            echo $name;
            return $name;';
        $name = 'Andrea';
        list($result, $error) = $this->call_method('safe_eval', array($code, array($name)));
        $this->assertNull($error);
        $this->assertEquals($name, $result);
        $this->expectOutputString('');
    }

    public
    function test_safe_eval_E_WARNING()
    {
        $this->expectOutputString('');

        $code    = Ando_ErrorFactory::E_WARNING_code();
        $enzymes = new Enzymes3();
        list(, $error, $output) = $this->call_method('safe_eval', array($code, array()), $enzymes);

        $this->assertTrue(is_array($error));
        extract($error);
        /**
         * @var $type
         * @var $message
         */
        $this->assertEquals(E_WARNING, $type);
        $this->assertNotEmpty($message);

        $this->assertEmpty($output);
    }

    public
    function test_safe_eval_E_PARSE()
    {
        $this->expectOutputString('');

        $code    = Ando_ErrorFactory::E_PARSE_code();
        $enzymes = new Enzymes3();
        list(, $error, $output) = $this->call_method('safe_eval', array($code, array()), $enzymes);

        $this->assertTrue($error);
        $this->assertRegExp('@^Parse error:@m', $output);
    }

    public
    function test_safe_eval_E_NOTICE()
    {
        $this->expectOutputString('');

        $code    = Ando_ErrorFactory::E_NOTICE_code();
        $enzymes = new Enzymes3();
        list(, $error, $output) = $this->call_method('safe_eval', array($code, array()), $enzymes);

        $this->assertTrue(is_array($error));
        extract($error);
        /**
         * @var $type
         * @var $message
         */
        $this->assertEquals(E_NOTICE, $type);
        $this->assertNotEmpty($message);

        $this->assertEmpty($output);
    }

    public
    function test_safe_eval_E_COMPILE_WARNING()
    {
        $this->expectOutputString('');

        $code    = Ando_ErrorFactory::E_COMPILE_WARNING_code();
        $enzymes = new Enzymes3();
        list(, $error, $output) = $this->call_method('safe_eval', array($code, array()), $enzymes);

        $this->assertFalse(is_array($error));
        $this->assertRegExp('@^Warning:@m', $output);
    }

    public
    function test_safe_eval_E_USER_WARNING()
    {
        $this->expectOutputString('');

        $code    = Ando_ErrorFactory::E_USER_WARNING_code();
        $enzymes = new Enzymes3();
        list(, $error, $output) = $this->call_method('safe_eval', array($code, array()), $enzymes);

        $this->assertTrue(is_array($error));
        extract($error);
        /**
         * @var $type
         * @var $message
         */
        $this->assertEquals(E_USER_WARNING, $type);
        $this->assertNotEmpty($message);

        $this->assertEmpty($output);
    }

    public
    function test_safe_eval_E_USER_NOTICE()
    {
        $this->expectOutputString('');

        $code    = Ando_ErrorFactory::E_USER_NOTICE_code();
        $enzymes = new Enzymes3();
        list(, $error, $output) = $this->call_method('safe_eval', array($code, array()), $enzymes);

        $this->assertTrue(is_array($error));
        extract($error);
        /**
         * @var $type
         * @var $message
         */
        $this->assertEquals(E_USER_NOTICE, $type);
        $this->assertNotEmpty($message);

        $this->assertEmpty($output);
    }

    public
    function test_safe_eval_E_STRICT()
    {
        $this->expectOutputString('');

        $code    = Ando_ErrorFactory::E_STRICT_code();
        $enzymes = new Enzymes3();
        list(, $error, $output) = $this->call_method('safe_eval', array($code, array()), $enzymes);

        $this->assertTrue(is_array($error));
        extract($error);
        /**
         * @var $type
         * @var $message
         */
        $this->assertEquals(E_STRICT, $type);
        $this->assertNotEmpty($message);

        $this->assertEmpty($output);
    }

    public
    function test_safe_eval_E_DEPRECATED()
    {
        $this->expectOutputString('');

        $code    = Ando_ErrorFactory::E_DEPRECATED_code();
        $enzymes = new Enzymes3();
        list(, $error, $output) = $this->call_method('safe_eval', array($code, array()), $enzymes);

        $this->assertTrue(is_array($error));
        extract($error);
        /**
         * @var $type
         * @var $message
         */
        $this->assertEquals(E_DEPRECATED, $type);
        $this->assertNotEmpty($message);

        $this->assertEmpty($output);
    }

    public
    function test_safe_eval_E_USER_DEPRECATED()
    {
        $this->expectOutputString('');

        $code    = Ando_ErrorFactory::E_USER_DEPRECATED_code();
        $enzymes = new Enzymes3();
        list(, $error, $output) = $this->call_method('safe_eval', array($code, array()), $enzymes);

        $this->assertTrue(is_array($error));
        extract($error);
        /**
         * @var $type
         * @var $message
         */
        $this->assertEquals(E_USER_DEPRECATED, $type);
        $this->assertNotEmpty($message);

        $this->assertEmpty($output);
    }

    public
    function test_safe_eval_E_USER_ERROR()
    {
        $this->expectOutputString('');

        $code    = Ando_ErrorFactory::E_USER_ERROR_code();
        $enzymes = new Enzymes3();
        list(, $error, $output) = $this->call_method('safe_eval', array($code, array()), $enzymes);

        $this->assertTrue(is_array($error));
        extract($error);
        /**
         * @var $type
         * @var $message
         */
        $this->assertEquals(E_USER_ERROR, $type);
        $this->assertNotEmpty($message);

        $this->assertEmpty($output);
    }

    public
    function test_safe_eval_E_RECOVERABLE_ERROR()
    {
        $this->expectOutputString('');

        $code    = Ando_ErrorFactory::E_RECOVERABLE_ERROR_code();
        $enzymes = new Enzymes3();
        list(, $error, $output) = $this->call_method('safe_eval', array($code, array()), $enzymes);

        $this->assertTrue(is_array($error));
        extract($error);
        /**
         * @var $type
         * @var $message
         */
        $this->assertEquals(E_RECOVERABLE_ERROR, $type);
        $this->assertNotEmpty($message);

        $this->assertEmpty($output);
    }

    public
    function test_safe_eval_bubbling_exception()
    {
        $code    = '
            throw new Exception("What did you expect?");';
        $enzymes = new Enzymes3();
        list(, $error, $output) = $this->call_method('safe_eval', array($code, array()), $enzymes);
        $this->assertInstanceOf('Exception', $error);
        $this->assertEquals('What did you expect?', $error->getMessage());
        $this->assertEquals('', $output);
        $this->expectOutputString('');
    }

    public
    function test_wp_post()
    {
        global $post;

        $target_post_id = $this->factory->post->create(array('post_title' => 'This is the target post.'));

        $enzymes = new Enzymes3();

        // this must return the global post
        $enzymes->metabolize('This post has a {[ fake ]} injection.');
        $result = $this->call_method('wp_post', array(array()), $enzymes);
        $this->assertEquals($post->ID, $result->ID);

        // this must return the target post (default)
        $enzymes->metabolize('This post has a {[ fake ]} injection.', $target_post_id);
        $result = $this->call_method('wp_post', array(array()), $enzymes);
        $this->assertEquals($target_post_id, $result->ID);

        // this must return the target post (numeric)
        $enzymes->metabolize('This post has a {[ fake ]} injection.', $target_post_id);
        $result = $this->call_method('wp_post', array(array('post' => $post->ID)), $enzymes);
        $this->assertEquals($post->ID, $result->ID);

        // this must return the target post (slug)
        $enzymes->metabolize('This post has a {[ fake ]} injection.', $target_post_id);
        $result = $this->call_method('wp_post', array(
                array(
                        'post' => '@this-is-the-global-post',
                        'slug' => 'this-is-the-global-post',
                )
        ), $enzymes);
        $this->assertEquals($post->ID, $result->ID);
    }

    public
    function test_unquote()
    {
        $result = $this->call_method('unquote', array('=This is how you quote a \=string\= in Enzymes.='));
        $this->assertEquals('This is how you quote a =string= in Enzymes.', $result);
    }

    public
    function test_wp_post_field()
    {
        $post_id = $this->factory->post->create();
        add_post_meta($post_id, 'sample-name', 'sample-value');
        add_post_meta($post_id, 'sample name', 'sample value');
        $post = get_post($post_id);

        $result = $this->call_method('wp_post_field', array($post, array('field' => 'sample-name', 'string' => '')));
        $this->assertEquals('sample-value', $result);

        $result = $this->call_method('wp_post_field',
                array($post, array('field' => '=sample name=', 'string' => '=sample name=')));
        $this->assertEquals('sample value', $result);
    }

    public
    function test_wp_author()
    {
        $user_id = $this->factory->user->create();
        $post_id = $this->factory->post->create(array('post_author' => $user_id));
        $post    = get_post($post_id);
        $result  = $this->call_method('wp_author', array($post));
        $this->assertEquals($user_id, $result->ID);
    }

    public
    function test_strip_blanks()
    {
        // case with no strings
        $result = $this->call_method('strip_blanks', array(
                array(
                        'anything_else' => '123  .  custom-field
        (
        2
        )'
                )
        ));
        $this->assertEquals('123.custom-field(2)', $result);

        // case with a string
        $result = $this->call_method('strip_blanks', array(
                array(
                        'before_string' => '123  .  custom-field
        (
        2
        ) | ', 'string' => '=a string \=with\= spaces
        and new lines='
                )
        ));
        $this->assertEquals('123.custom-field(2)|=a string \=with\= spaces
        and new lines=', $result);
    }

    public
    function test_clean_up()
    {
        $result = $this->call_method('clean_up', array(
                '
        /* this is how we pass indexed and associative arrays to a function */
        =one \{\[to\]\} three= | 1 | 2 | 3 | array(3) | hash(1) | 456.sum(1) /* here the post number 456 is supposed to contain
        a custom field whose name is "sum" and whose value should be some code that can access the $received argument
        array("one {[to]} three" => array(1, 2, 3)) with list($received) = $arguments. */'
        ));
        $this->assertEquals('=one {[to]} three=|1|2|3|array(3)|hash(1)|456.sum(1)', $result);
    }

    public
    function test_literal_integer_is_replaced_as_is()
    {
        $enzymes = new Enzymes3();

        $content1 = 'This is something before {[123]} and in between {[456]} but this is after.';
        $content2 = 'This is something before 123 and in between 456 but this is after.';
        $this->assertEquals($content2, $enzymes->metabolize($content1));
    }

    public
    function test_literal_string_is_replaced_unquoted()
    {
        $enzymes = new Enzymes3();

        $content1 = 'This is something before {[ ="Hello World!"= ]} and in between {[ ="How are you today?"= ]} but this is after.';
        $content2 = 'This is something before "Hello World!" and in between "How are you today?" but this is after.';
        $this->assertEquals($content2, $enzymes->metabolize($content1));
    }

    public
    function test_transcluded_from_current_post()
    {
        $post_id = $this->factory->post->create();
        add_post_meta($post_id, 'sample-name', 'sample-value');
        add_post_meta($post_id, 'sample name', 'sample value');
        $post = get_post($post_id);

        $enzymes = new Enzymes3();

        $content1 = 'Before "{[ .sample-name ]}" between "{[ .=sample name= ]}" and after.';
        $content2 = 'Before "sample-value" between "sample value" and after.';
        $this->assertEquals($content2, $enzymes->metabolize($content1, $post));
    }

    public
    function test_a_final_literal_wins()
    {
        $post_id = $this->factory->post->create();
        add_post_meta($post_id, 'sample-name', 'sample-value');
        $post = get_post($post_id);

        $enzymes = new Enzymes3();

        $content1 = 'Before "{[ .sample-name | 123 ]}" and after.';
        $content2 = 'Before "123" and after.';
        $this->assertEquals($content2, $enzymes->metabolize($content1, $post));
    }

    public
    function test_transcluded_from_another_post()
    {
        $post_1_id = $this->factory->post->create();
        add_post_meta($post_1_id, 'sample-name', 'sample value 1');
        $post_1 = get_post($post_1_id);

        $post_2_id = $this->factory->post->create();
        add_post_meta($post_2_id, 'sample-name', 'sample value 2');

        $enzymes = new Enzymes3();

        $content1 = 'Before "{[ ' . $post_2_id . '.sample-name ]}" and after.';
        $content2 = 'Before "sample value 2" and after.';
        $this->assertEquals($content2, $enzymes->metabolize($content1, $post_1));
    }

    public
    function test_transcluded_from_another_post_by_slug()
    {
        $post_1_id = $this->factory->post->create();
        add_post_meta($post_1_id, 'sample-name', 'sample value 1');
        $post_1 = get_post($post_1_id);

        $post_2_id = $this->factory->post->create(array('post_title' => 'This is the target post.'));
        add_post_meta($post_2_id, 'sample-name', 'sample value 2');

        $enzymes = new Enzymes3();

        $content1 = 'Before "{[ @this-is-the-target-post.sample-name ]}" and after.';
        $content2 = 'Before "sample value 2" and after.';
        $this->assertEquals($content2, $enzymes->metabolize($content1, $post_1));
    }

    public
    function test_executed_with_no_arguments()
    {
        $post_id = $this->factory->post->create();
        add_post_meta($post_id, 'sample-name', '
        $a = 100;
        $b = 20;
        $c = 3;
        $result = $a + $b + $c;
        return $result;
        ');
        $post = get_post($post_id);

        $enzymes = new Enzymes3();

        $content1 = 'Before "{[ =whatever here= | .sample-name() ]}" and after.';
        $content2 = 'Before "123" and after.';
        $this->assertEquals($content2, $enzymes->metabolize($content1, $post));
    }

    public
    function test_executed_with_one_argument()
    {
        $post_id = $this->factory->post->create();
        add_post_meta($post_id, 'sample-name', '
        list($a) = $arguments;
        $b = 20;
        $c = 3;
        $result = $a + $b + $c;
        return $result;
        ');
        $post = get_post($post_id);

        $enzymes = new Enzymes3();

        $content1 = 'Before "{[ =whatever here= | 100 | .sample-name(1) ]}" and after.';
        $content2 = 'Before "123" and after.';
        $this->assertEquals($content2, $enzymes->metabolize($content1, $post));
    }

    public
    function test_executed_with_many_arguments()
    {
        $post_id = $this->factory->post->create();
        add_post_meta($post_id, 'sample-name', '
        list($a, $b, $c) = $arguments;
        $result = $a * $b - $c;
        return $result;
        ');
        $post = get_post($post_id);

        $enzymes = new Enzymes3();

        $content1 = 'Before "{[ =whatever here= | 100 | 20 | 3 | .sample-name(3) ]}" and after.';
        $content2 = 'Before "1997" and after.';
        $this->assertEquals($content2, $enzymes->metabolize($content1, $post));
    }

    public
    function test_executed_with_an_array_argument()
    {
        $post_id = $this->factory->post->create();
        add_post_meta($post_id, 'sample-name', '
        list($a, $bc) = $arguments;
        $result = $a * array_sum($bc);
        return $result;
        ');
        $post = get_post($post_id);

        $enzymes = new Enzymes3();

        $content1 = 'Before "{[ =whatever here= | 100 | 20 | 3 | array(2) | .sample-name(2) ]}" and after.';
        $content2 = 'Before "2300" and after.';
        $this->assertEquals($content2, $enzymes->metabolize($content1, $post));
    }

    public
    function test_executed_with_a_hash_argument()
    {
        $post_id = $this->factory->post->create();
        add_post_meta($post_id, 'sample-name', '
        list($hash) = $arguments;
        $result = $hash["a hundred"] * array_sum($hash["twenty and three"]);
        return $result;
        ');
        $post    = get_post($post_id);
        $enzymes = new Enzymes3();

        $content1 = 'Before "{[ =whatever here= | =a hundred= | 100 | =twenty and three= | 20 | 3 | array(2) | hash(2) | .sample-name(1) ]}" and after.';
        $content2 = 'Before "2300" and after.';
        $this->assertEquals($content2, $enzymes->metabolize($content1, $post));
    }

    public
    function test_author_properties()
    {
        /*
         * Post properties (should) come only from columns of the posts table.
         * Author properties come both from columns of the users table and key/value pairs in user_meta, but this fact
         * is automatically taken into account by the WordPress API (__get).
         *
         * This test is a bit "strange" because I had to put here the same code that is in the source to make it work.
         * Also notice that not all properties are sufficiently tested because $this->factory->user->create() doesn't
         * fill in all the columns and meta keys. Additionally there could be some properties like 'roles' which go on
         * another route.
         */
        $enzymes = new Enzymes3();

        $attrs       = array(
            // Properties extracted from the columns of the user table.
            'ID',
            'user_login',
            'user_pass',
            'user_nicename',
            'user_email',
            'user_url',
            'user_registered',
            'user_activation_key',
            'user_status',
            'display_name',
            // Properties extracted from the meta_key values of admin rows in the usermeta table.
            'nickname',
            'first_name',
            'last_name',
            'description',
            'rich_editing',
            'comment_shortcuts',
            'admin_color',
            'use_ssl',
            'show_admin_bar_front',
            'wp_capabilities',
            'wp_user_level',
            'dismissed_wp_pointers',
            'show_welcome_panel',
            'wp_dashboard_quick_press_last_post_id',
            'session_tokens',
            'closedpostboxes_dashboard',
            'metaboxhidden_dashboard',
            'wp_user-settings',
            'wp_user-settings-time',
            'closedpostboxes_post',
            'metaboxhidden_post',
        );
        $attrs_seq   = ' /author:' . implode(' | /author:', $attrs);
        $attrs_count = count($attrs);

        // This role is not really needed for attributes, but it makes my test easier to write.
        $user = $this->factory->user->create_and_get(array('role' => EnzymesCapabilities::Coder));
        $data = array();
        foreach ($attrs as $key) {
            $data[$key] = $user->$key;
        }
        $data = "(" . implode(")(", $data) . ")";

        $post_id = $this->factory->post->create(array('post_author' => $user->ID));
        $code    = '
        list($data) = $arguments;
        $result = "(" . implode(")(", $data) . ")";
        return $result;
        ';
        add_post_meta($post_id, 'implode', $code);
        $post = get_post($post_id);

        $content1 = "Before \"{[ $attrs_seq | array($attrs_count) | .implode(1) ]}\" and after.";
        $content2 = "Before \"$data\" and after.";
        $this->assertEquals($content2, $enzymes->metabolize($content1, $post));
    }

    public
    function test_transcluded_author_from_current_post()
    {
        $user_id = $this->factory->user->create(array('role' => EnzymesCapabilities::User));
        add_user_meta($user_id, 'sample-name', 'sample-value');
        add_user_meta($user_id, 'sample name', 'sample value');
        $post_id = $this->factory->post->create(array('post_author' => $user_id));
        $post    = get_post($post_id);

        $enzymes = new Enzymes3();

        $content1 = 'Before "{[ /author.sample-name ]}" between "{[ /author.=sample name= ]}" and after.';
        $content2 = 'Before "sample-value" between "sample value" and after.';
        $this->assertEquals($content2, $enzymes->metabolize($content1, $post));
    }

    public
    function test_transcluded_author_from_another_post()
    {
        $user_1_id = $this->factory->user->create(array('role' => EnzymesCapabilities::PrivilegedUser));
        add_user_meta($user_1_id, 'sample-name', 'sample value 1');
        $post_1_id = $this->factory->post->create(array('post_author' => $user_1_id));
        $post_1    = get_post($post_1_id);

        $user_2_id = $this->factory->user->create(array('role' => EnzymesCapabilities::TrustedUser));
        add_user_meta($user_2_id, 'sample-name', 'sample value 2');
        $post_2_id = $this->factory->post->create(array('post_author' => $user_2_id));

        $enzymes = new Enzymes3();

        $content1 = 'Before "{[ ' . $post_2_id . '/author.sample-name ]}" and after.';
        $content2 = 'Before "sample value 2" and after.';
        $this->assertEquals($content2, $enzymes->metabolize($content1, $post_1));
    }

    public
    function test_transcluded_author_from_another_post_by_slug()
    {
        $user_1_id = $this->factory->user->create(array('role' => EnzymesCapabilities::PrivilegedUser));
        add_user_meta($user_1_id, 'sample-name', 'sample value 1');
        $post_1_id = $this->factory->post->create(array('post_author' => $user_1_id));
        $post_1    = get_post($post_1_id);

        $user_2_id = $this->factory->user->create(array('role' => EnzymesCapabilities::TrustedUser));
        add_user_meta($user_2_id, 'sample-name', 'sample value 2');
        $post_2_id = $this->factory->post->create(array(
                'post_author' => $user_2_id,
                'post_title'  => 'This is the target post.'
        ));

        $enzymes = new Enzymes3();

        $content1 = 'Before "{[ @this-is-the-target-post/author.sample-name ]}" and after.';
        $content2 = 'Before "sample value 2" and after.';
        $this->assertEquals($content2, $enzymes->metabolize($content1, $post_1));
    }

    public
    function test_properties()
    {
        /*
         * Post properties (should) come only from columns of the posts table.
         * Author properties come both from columns of the users table and key/value pairs in user_meta, but this fact
         * is automatically taken into account by the WordPress API (__get).
         *
         * This test is a bit "strange" because I had to put here the same code that is in the source to make it work.
         */
        $enzymes = new Enzymes3();

        $attrs       = array(
            // Properties extracted from the columns of the  table.
            'ID',
            'post_author',
            'post_name',
            'post_type',
            'post_title',
            'post_date',
            'post_date_gmt',
            'post_content',
            'post_excerpt',
            'post_status',
            'comment_status',
            'ping_status',
            'post_password',
            'post_parent',
            'post_modified',
            'post_modified_gmt',
            'comment_count',
            'menu_order',
            // Properties not documented at http://codex.wordpress.org/Class_Reference/WP_Post
            'to_ping',
            'pinged',
            'post_content_filtered',
            'guid',
            'post_mime_type',
        );
        $attrs_seq   = ':' . implode(' | :', $attrs);
        $attrs_count = count($attrs);

        $post = $this->factory->post->create_and_get();
        $data = array();
        foreach ($attrs as $key) {
            $data[$key] = $post->$key;
        }
        $data = "(" . implode(")(", $data) . ")";

        $post_id = $post->ID;
        $code    = '
        list($data) = $arguments;
        $result = "(" . implode(")(", $data) . ")";
        return $result;
        ';
        add_post_meta($post_id, 'implode', $code);

        $content1 = "Before \"{[ $attrs_seq | array($attrs_count) | .implode(1) ]}\" and after.";
        $content2 = "Before \"$data\" and after.";
        $this->assertEquals($content2, $enzymes->metabolize($content1, $post));
    }

    public
    function test_executed_author_with_no_arguments()
    {
        $user_id = $this->factory->user->create(array('role' => EnzymesCapabilities::Coder));
        add_user_meta($user_id, 'sample-name', '
        $a = 100;
        $b = 20;
        $c = 3;
        $result = $a + $b + $c;
        return $result;
        ');
        $post_id = $this->factory->post->create(array('post_author' => $user_id));
        $post    = get_post($post_id);

        $enzymes = new Enzymes3();

        $content1 = 'Before "{[ =whatever here= | /author.sample-name() ]}" and after.';
        $content2 = 'Before "123" and after.';
        $this->assertEquals($content2, $enzymes->metabolize($content1, $post));
    }

    public
    function test_executed_author_with_one_argument()
    {
        $user_id = $this->factory->user->create(array('role' => EnzymesCapabilities::Coder));
        add_user_meta($user_id, 'sample-name', '
        list($a) = $arguments;
        $b = 20;
        $c = 3;
        $result = $a + $b + $c;
        return $result;
        ');
        $post_id = $this->factory->post->create(array('post_author' => $user_id));
        $post    = get_post($post_id);

        $enzymes = new Enzymes3();

        $content1 = 'Before "{[ =whatever here= | 100 | /author.sample-name(1) ]}" and after.';
        $content2 = 'Before "123" and after.';
        $this->assertEquals($content2, $enzymes->metabolize($content1, $post));
    }

    public
    function test_executed_author_with_many_arguments()
    {
        $user_id = $this->factory->user->create(array('role' => EnzymesCapabilities::Coder));
        add_user_meta($user_id, 'sample-name', '
        list($a, $b, $c) = $arguments;
        $result = $a * $b - $c;
        return $result;
        ');
        $post_id = $this->factory->post->create(array('post_author' => $user_id));
        $post    = get_post($post_id);

        $enzymes = new Enzymes3();

        $content1 = 'Before "{[ =whatever here= | 100 | 20 | 3 | /author.sample-name(3) ]}" and after.';
        $content2 = 'Before "1997" and after.';
        $this->assertEquals($content2, $enzymes->metabolize($content1, $post));
    }

    public
    function test_executed_author_with_an_array_argument()
    {
        $user_id = $this->factory->user->create(array('role' => EnzymesCapabilities::Coder));
        add_user_meta($user_id, 'sample-name', '
        list($a, $bc) = $arguments;
        $result = $a * array_sum($bc);
        return $result;
        ');
        $post_id = $this->factory->post->create(array('post_author' => $user_id));
        $post    = get_post($post_id);

        $enzymes = new Enzymes3();

        $content1 = 'Before "{[ =whatever here= | 100 | 20 | 3 | array(2) | /author.sample-name(2) ]}" and after.';
        $content2 = 'Before "2300" and after.';
        $this->assertEquals($content2, $enzymes->metabolize($content1, $post));
    }

    public
    function test_executed_author_with_a_hash_argument()
    {
        $user_id = $this->factory->user->create(array('role' => EnzymesCapabilities::Coder));
        add_user_meta($user_id, 'sample-name', '
        list($hash) = $arguments;
        $result = $hash["a hundred"] * array_sum($hash["twenty and three"]);
        return $result;
        ');
        $post_id = $this->factory->post->create(array('post_author' => $user_id));
        $post    = get_post($post_id);

        $enzymes = new Enzymes3();

        $content1 = 'Before "{[ =whatever here= | =a hundred= | 100 | =twenty and three= | 20 | 3 | array(2) | hash(2) | /author.sample-name(1) ]}" and after.';
        $content2 = 'Before "2300" and after.';
        $this->assertEquals($content2, $enzymes->metabolize($content1, $post));
    }

}
