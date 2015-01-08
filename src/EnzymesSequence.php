<?php

class EnzymesSequence {
    protected $store = array();

    public
    function push() {
        $items = func_get_args();
        array_splice($this->store, count($this->store), 0, $items);
        $result = count($this->store);
        return $result;
    }

    public
    function pop( $last = 1 ) {
        if (empty($this->store)) {
            return null;
        }
        $last = max(array($last, 1));
        $result = array_splice($this->store, - $last);
        return $result;
    }

    public
    function peek( $last = 1 ) {
        if (empty($this->store)) {
            return null;
        }
        $last = max(array($last, 1));
        $result = array_slice($this->store, - $last);
        return $result;
    }

    public
    function replace( $item, $last = 1 ) {
        $last = max(array($last, 1));
        $items = is_array($item) ? $item : array($item);
        $result = array_splice($this->store, - $last, count($this->store), $items);
        return $result;
    }
}
