<?php

class SequenceTest
        extends WP_UnitTestCase
{

    function test_peek_for_empty_store_is_null()
    {
        $s = new EnzymesSequence();
        $this->assertEquals(null, $s->peek());
        $this->assertEquals(null, $s->peek(2));
    }

    function test_pop_for_empty_store_is_null()
    {
        $s = new EnzymesSequence();
        $this->assertEquals(null, $s->pop());
        $this->assertEquals(null, $s->pop(2));
    }

    function test_push() {
        $s = new EnzymesSequence();
        $this->assertEquals(1, $s->push('hello'));
        $this->assertEquals(2, $s->push('world'));
    }

    function test_peek() {
        $s = new EnzymesSequence();
        $s->push('hello');
        $this->assertEquals(array('hello'), $s->peek());

        $s->push('world');
        $this->assertEquals(array('world'), $s->peek());

        $peek = $s->peek(2);
        $this->assertEquals(2, count($peek));
        $this->assertEquals(array('hello', 'world'), $peek);
    }

    function test_pop() {
        $s = new EnzymesSequence();
        $s->push('hello');
        $s->push('world');
        $this->assertEquals(array('world'), $s->pop());

        $s->push('world 2');
        $pop = $s->pop(2);
        $this->assertEquals(2, count($pop));
        $this->assertEquals(array('hello', 'world 2'), $pop);

        $this->assertEquals(1, $s->push('first'));
    }

    function test_replace() {
        $s = new EnzymesSequence();
        $s->push('hello');
        $s->push('world');
        $replace = $s->replace('world 2');
        $this->assertEquals(array('world'), $replace);
        $this->assertEquals(array('world 2'), $s->peek());

        $replace = $s->replace(array('hello 3', 'world 3'), 2);
        $this->assertEquals(array('hello', 'world 2'), $replace);
        $this->assertEquals(array('hello 3', 'world 3'), $s->peek(2));
    }

}
