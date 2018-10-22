<?php

class Wpwire_Select {
    public $table;
    public $cols;

    public function __construct($table) {
        $this->table = $table;
    }

    public function toSql() {
        $sql = "select \n  ";
        $sql .= implode(",\n  ", $this->cols);
        $sql .= "\nfrom ".$this->table;
        return $sql;
    }
}
