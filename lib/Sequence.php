<?php

class Sequence {
    protected $store = array();

    public
    function push( $item ) {
        $items = is_array($item) ? $item : array($item);
        array_splice($this->store, count($this->store), 0, $items);
        $result = count($this->store);
        return $result;
    }

    public
    function pop( $last = 1 ) {
        $last = max(array($last, 1));
        $top = array_splice($this->store, - $last);
        $result = count($top) == 1 ? $top[0] : $top;
        return $result;
    }

    public
    function peek( $last = 1 ) {
        $last = max(array($last, 1));
        $top = array_slice($this->store, - $last);
        $result = $last == 1 ? $top[0] : $top;
        return $result;
    }

    public
    function replace( $item, $last = 1 ) {
        $last = max(array($last, 1));
        $items = is_array($item) ? $item : array($item);
        $top = array_splice($this->store, - $last, count($this->store), $items);
        $result = $last == 1 ? $top[0] : $top;
        return $result;
    }
}
