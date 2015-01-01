<?php

//fwrite(STDERR, "\n\n" . print_r($result, TRUE));

require_once 'lib/Enzymes3.php';

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
    function get_method($name) {
        if (is_null($this->reflection)) {
            $this->class = str_replace('Test', '', __CLASS__);
            $this->reflection = new \ReflectionClass($this->class);
        }
        if (! $this->reflection->hasMethod($name)) {
            return null;
        }
        $method = $this->reflection->getMethod($name);
        $method->setAccessible(true);
        return $method;
    }

    function call_method($name, $args = null, $object = null) {
        $method = $this->get_method($name);
        if (! $method instanceof ReflectionMethod) {
            throw new Exception(sprintf('"%s" is not a method of "%s".', $name, $this->class));
        }
        if (is_null($args)) {
            $args = array();
        }
        if (is_null($object)) {
            $object = new $this->class;
        }
        $result = $method->invokeArgs($object, $args);
        return $result;
    }

    function test_an_escaped_injection_is_ignored()
    {
        $enzymes = new Enzymes3();

        $content1 = 'This is something before {{[ whatever ]} and this is after.';
        $content2 = 'This is something before {[ whatever ]} and this is after.';
        $this->assertEquals($content2, $enzymes->metabolize($content1));
    }

    function test_content_with_no_injections_is_not_filtered()
    {
        $enzymes = new Enzymes3();

        $content = 'This is some content with no injections.';
        $this->assertEquals($content, $enzymes->metabolize($content));
    }

    function test_content_with_injections_is_filtered()
    {
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

    function test_default_empty() {
        // case when the initial array is not empty
        $values = array(
                'one' => 1,
                'three' => 3,
        );
        $this->call_method('default_empty', array(&$values, 'one', 'two'));

        $this->assertArrayHasKey('one', $values);
        $this->assertEquals(1, $values['one']);

        $this->assertArrayHasKey('two', $values);
        $this->assertEquals('', $values['two']);

        $this->assertArrayHasKey('three', $values);
        $this->assertEquals(3, $values['three']);

        // case when the initial array is empty
        $values = array();
        $this->call_method('default_empty', array(&$values, 'one', 'two'));

        $this->assertArrayHasKey('one', $values);
        $this->assertEquals('', $values['one']);

        $this->assertArrayHasKey('two', $values);
        $this->assertEquals('', $values['two']);
    }

    function test_safe_eval() {
        $code = 'list($name) = $arguments; echo $name; return "done";';
        $name = 'Andrea';
        list($result, $output) = $this->call_method('safe_eval', array($code, array($name)));
        $this->assertEquals('done', $result);
        $this->assertEquals('Andrea', $output);
    }

    function test_wp_post() {
        $global_post_id = $this->factory->post->create( array( 'post_title' => 'This is the global post.' ) );
        global $post;
        $post = get_post( $global_post_id );

        $target_post_id = $this->factory->post->create( array( 'post_title' => 'This is the target post.' ) );
        $target = get_post( $target_post_id );

        $enzymes = new Enzymes3();

        // this must return the global post
        $enzymes->metabolize('This post has a {[ fake ]} injection.');
        $result = $this->call_method('wp_post', array(array()), $enzymes);
        $this->assertEquals($global_post_id, $result->ID);

        // this must return the target post (default)
        $enzymes->metabolize('This post has a {[ fake ]} injection.', $target);
        $result = $this->call_method('wp_post', array(array()), $enzymes);
        $this->assertEquals($target_post_id, $result->ID);

        // this must return the target post (numeric)
        $enzymes->metabolize('This post has a {[ fake ]} injection.', $target);
        $result = $this->call_method('wp_post', array(array('post' => $global_post_id)), $enzymes);
        $this->assertEquals($global_post_id, $result->ID);

        // this must return the target post (slug)
        $enzymes->metabolize('This post has a {[ fake ]} injection.', $target);
        $result = $this->call_method('wp_post', array(array('post' => '@this-is-the-global-post', 'slug' => 'this-is-the-global-post')), $enzymes);
        $this->assertEquals($global_post_id, $result->ID);
    }

    function test_unquote() {
        $result = $this->call_method('unquote', array('=This is how you quote a \=string\= in Enzymes.='));
        $this->assertEquals('This is how you quote a =string= in Enzymes.', $result);
    }

    function test_wp_custom_field() {
        $post_id = $this->factory->post->create();
        add_post_meta($post_id, 'sample-name', 'sample-value');
        add_post_meta($post_id, 'sample name', 'sample value');
        $post = get_post($post_id);

        $result = $this->call_method('wp_custom_field', array($post, array('field' => 'sample-name', 'string' => '')));
        $this->assertEquals('sample-value', $result);

        $result = $this->call_method('wp_custom_field', array($post, array('field' => '=sample name=', 'string' => '=sample name=')));
        $this->assertEquals('sample value', $result);
    }

    function test_wp_author() {
        $user_id = $this->factory->user->create();
        $post_id = $this->factory->post->create( array( 'post_author' => $user_id ) );
        $post = get_post($post_id);
        $result = $this->call_method('wp_author', array($post));
        $this->assertEquals($user_id, $result->ID);
    }

    function test_strip_blanks() {
        // case with no strings
        $result = $this->call_method('strip_blanks', array(array('anything_else' => '123  .  custom-field
        (
        2
        )')));
        $this->assertEquals('123.custom-field(2)', $result);

        // case with a string
        $result = $this->call_method('strip_blanks', array(array('before_string' => '123  .  custom-field
        (
        2
        ) | ', 'string' => '=a string \=with\= spaces
        and new lines=')));
        $this->assertEquals('123.custom-field(2)|=a string \=with\= spaces
        and new lines=', $result);
    }

    function test_clean_up() {
        $result = $this->call_method('clean_up', array('
        /* this is how we pass indexed and associative arrays to a function */
        =one \{\[to\]\} three= | 1 | 2 | 3 | array(3) | hash(1) | 456.sum(1) /* here the post number 456 is supposed to contain
        a custom field whose name is "sum" and whose value should be some code that can access the $received argument
        array("one {[to]} three" => array(1, 2, 3)) with list($received) = $arguments. */'));
        $this->assertEquals('=one {[to]} three=|1|2|3|array(3)|hash(1)|456.sum(1)', $result);
    }

    function test_literal_integer_is_replaced_as_is() {
        $enzymes = new Enzymes3();

        $content1 = 'This is something before {[123]} and in between {[456]} but this is after.';
        $content2 = 'This is something before 123 and in between 456 but this is after.';
        $this->assertEquals($content2, $enzymes->metabolize($content1));
    }

    function test_literal_string_is_replaced_unquoted() {
        $enzymes = new Enzymes3();

        $content1 = 'This is something before {[ ="Hello World!"= ]} and in between {[ ="How are you today?"= ]} but this is after.';
        $content2 = 'This is something before "Hello World!" and in between "How are you today?" but this is after.';
        $this->assertEquals($content2, $enzymes->metabolize($content1));
    }

    function test_transcluded_from_current_post() {
        $post_id = $this->factory->post->create();
        add_post_meta($post_id, 'sample-name', 'sample-value');
        add_post_meta($post_id, 'sample name', 'sample value');
        $post = get_post($post_id);

        $enzymes = new Enzymes3();

        $content1 = 'Before "{[ .sample-name ]}" between "{[ .=sample name= ]}" and after.';
        $content2 = 'Before "sample-value" between "sample value" and after.';
        $this->assertEquals($content2, $enzymes->metabolize($content1, $post));
    }

    function test_a_literal_at_the_end_wins() {
    }

}
