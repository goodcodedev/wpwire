<?php

/**
 * Utility class to aid in parsing
 */
class Wpwire_Parser {
    public $string;
    public $pos;
    public $len;

    public function __construct($string) {
        $this->string = $string;
        $this->pos = 0;
        $this->len = strlen($string);
    }

    public function parse($string) {
        if ($this->pos >= $this->len) {
            return false;
        }
        $len = strlen($string);
        if (substr_compare($this->string, $string, $this->pos, $len) == 0) {
            $this->pos += $len;
            return true;
        }
        return false;
    }

    public function parseOneOf($array) {
        foreach ($array as $string) {
            if ($this->parse($string)) {
                return true;
            }
        }
        return false;
    }

    public function parseChar($char) {
        if ($this->pos < $this->len && $this->string[$this->pos] == $char) {
            $this->pos++;
            return true;
        } else {
            return false;
        }
    }

    public function skipWhite() {
        while ($this->pos < $this->len) {
            $char = $this->string[$this->pos];
            if ($char == ' ') {
                $this->pos++;
            } else if ($char == "\n") {
                $this->pos++;
            } else if ($char == "\t") {
                $this->pos++;
            } else {
                break;
            }
        }
    }

    public function parseInt() {
        $parsed = 0;
        while ($this->pos < $this->len) {
            $char = $this->string[$this->pos];
            if (is_numeric($char)) {
                $parsed = ($parsed * 10) + ((int) $char);
                $this->pos++;
            } else {
                break;
            }
        }
        return $parsed;
    }

    public function parseUntilChar($char) {
        $pos = strpos($this->string, $char, $this->pos);
        if ($pos === false) {
            return '';
        } else {
            $start = $this->pos;
            $len = $pos - $start;
            $this->pos += $len;
            return substr($this->string, $start, $len);
        }
    }

    public function parseSingleQuoted() {
        if ($this->parseChar("'")) {
            $start = $this->pos;
            $end = false;
            while ($this->pos < $this->len) {
                if ($this->parse("\\'")) {
                    continue;
                }
                if ($this->parse("'")) {
                    $end = $this->pos - 1;
                    break;
                }
                $this->pos++;
            }
            if ($end !== false) {
                return substr($this->string, $start, $end - $start);
            }
        }
        return false;
    }
}