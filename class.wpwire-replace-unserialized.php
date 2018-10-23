<?php

class Wpwire_Replace_Unserialized {
    public $search;
    public $replace;

    public function __construct($search, $replace) {
        $this->search = $search;
        $this->replace = $replace;
    }

    public function poly($val) {
        if (is_array($val)) {
            foreach ($val as $idx => $v) {
                $val[$idx] = $this->poly($v);
            }
            return $arr;
        } elseif (is_int($val)) {
            return $val;
        } elseif (is_string($val)) {
            return str_replace($this->search, $this->replace, $val);
        } elseif (is_bool($val)) {
            return $val;
        } elseif (is_float($val)) {
            return $val;
        } elseif ($val === null) {
            return null;
        } elseif (is_object($val)) {
            // Hopefully stdobject
            foreach ($val as $key => $v) {
                $val->$$key = $this->poly($v);
            }
        } else {
            throw new Exception("Unrecognized type: ". $val);
        }
    }
}