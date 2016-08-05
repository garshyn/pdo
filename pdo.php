<?php

/*!
 * [PDO]
 * http://garshyn.com
 * Version 0.0.1
 * Created on 2016-07-24
 * Updated on 2016-07-24
 * Copyright 2016, Andrew Garshyn
 * Released under the MIT license
 */


class pdoLog {
    private $messages;
    private $enabled;
    protected static $_instance;
    private function __construct() {
        $this->messages = array();
        $this->enabled = true;
    }
    private function __clone() {}

    // static methods
    public static function getInstance() {
        if (null === self::$_instance) {
            self::$_instance = new self();
        }

        return self::$_instance;
    }
    public static function all() {
        $self = self::getInstance();
        if($self->enabled) {
            return $self->get_all_messages();
        }
        else {
            return array();
        }
    }
    public static function debug($enabled = true) {
        if($enabled) {
            return self::getInstance()->get_all_messages();
        }
        else {
            return array();
        }
    }
    public static function add($message) {
        self::getInstance()->add_message($message);
    }

    // instance methods
    protected function enable($enabled = true) {
        $this->enabled = $enabled ? true : false;
    }
    protected function disable() {
        $this->enable(false);
    }
    protected function add_message($message) {
        $this->messages[] = $message;
    }
    protected function get_all_messages() {
        return $this->messages;
    }
}


class pdoDb {

    private $connection;
    private $log;

    function __construct($host, $db, $user, $password = '') {
        try {
            $this->connection = new PDO("mysql:dbname=$db;host=$host", $user, $password);
            // $this->connection->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
            $this->log = pdoLog::getInstance();
        } catch (PDOException $e) {
            echo 'Failed to connect to database: ' . $e->getMessage();
        }
    }

    function getConnection() {
        return $this->connection;
    }

    function prepare($sql) {
        return $this->connection->prepare($sql);
    }

    function fetchAll($sql, $params = array()) {
        // $sql_to_log = str_replace(array("\r\n"), ' ', $sql);
        // $this->log->add($sql_to_log);
        $this->log->add($sql);


        $stmt = $this->prepare($sql);
        $stmt->setFetchMode(PDO::FETCH_ASSOC);
        $stmt->execute();
        return $stmt->fetchAll();
    }

    function select($sql, $params = array()) {
        $sql_to_log = str_replace(array("\r\n"), ' ', $sql);
        $this->log->add($sql_to_log);

        $stmt = $this->prepare($sql);
        $stmt->setFetchMode(PDO::FETCH_ASSOC);
        $stmt->execute();
        return $stmt->fetchAll();
    }

    function fetchOne($sql) {
        $sql_to_log = str_replace(array("\r\n"), ' ', $sql);
        $this->log->add($sql_to_log);

        $stmt = $this->prepare($sql);
        $stmt->setFetchMode(PDO::FETCH_ASSOC);
        $stmt->execute();
        return $stmt->fetch();
    }

    function query($sql) {
        $sql_to_log = str_replace(array("\r\n"), ' ', $sql);
        $this->log->add($sql_to_log);
        return $this->connection->query($sql);
    }

    function sql($sql) {
        return $this->connection->query($sql);
    }

    function lastInsertId() {
        return $this->connection->lastInsertId();
    }

    function escape($value) {
        return $this->toVal($value);
    }

    function toKey($key) {
        return '`' . $key . '`'; // TODO: escape key
    }

    function toVal($value) {
        if(is_array($value)) {
            if(count($value) == 1 && array_key_exists('raw', $value)) {
                return $value['raw'];
            }
            $array = array();
            foreach($value as $val) {
                $array[] = $this->toVal($val);
            }
            return join(',', $array);
        }
        if(is_numeric($value)) {
            return $value;
        }
        if(is_string($value)) {
            return $this->connection->quote($value);
        }
        return 'NULL';
    }
}

class pdoAssembler {
    protected $db;
    protected $table;
    protected $cmd;
    protected $where;
    protected $orWhere;
    protected $group;
    protected $order;
    function __construct($tableName, $db) {
        $this->db = $db;
        $this->table = $this->db->toKey($tableName);
        $this->cmd = '';
    }

    function select($fields = '*') {
        $this->_reset();
        if(is_array($fields)) {
            $keys = array();
            foreach($fields as $field) {
                $keys[] = $this->_key($field);
            }
            $sqlFields = join(', ', $keys);
        }
        else {
            $sqlFields = '*';
        }
        $this->cmd = "SELECT $sqlFields FROM {$this->table}";
        return $this;
    }

    function update($params) {
        $this->_reset();
        $parts = array();
        foreach($params as $key => $value) {
            $sqlKey = $this->_key($key);
            $sqlVal = $this->_val($value);
            $parts[] = "$sqlKey = $sqlVal";
        }
        $this->cmd = "UPDATE {$this->table} SET " . join(', ', $parts);
        return $this;
    }

    function insert($params) {
        $this->_reset();
        $keys = array();
        $values = array();
        if(!array_key_exists('id', $params)) {
            $keys[] = $this->_key('id');
            $values[] = 'NULL';
        }
        foreach($params as $key => $value) {
            $keys[] = $this->_key($key);
            $values[] = $this->_val($value);
        }
        $this->cmd = "INSERT INTO {$this->table} (" . join(', ', $keys) . ") VALUES (" . join(', ', $values) . ")";
        return $this;
    }

    function delete($params = array(), $deleteAll = false) {
        $this->_reset();
        if($params) {
            $this->where($params);
        }
        else {
            if(!$deleteAll) {
                // allow delete all records only if special parameter is set.
                $this->cmd = '';
                return $this;
            }
        }
        $this->cmd = "DELETE FROM {$this->table}";
        return $this;
    }

    /**
     *  $params @array
     * items: 
     * + field => value
     * + field => array('>=' => value)
     * - field => array('in' => array)
     * - field => array('between' => array)
     */
    function where($params) {
        if(is_array($params)) {
            foreach($params as $key => $value) {
                $sqlKey = $this->_key($key);
                if(is_array($value)) {
                    // foreach($value as $condition => $values) {
                    //     $sqlValue = $this->db->toVal($values);
                    // }
                    switch($value[0]) {
                        case '<':
                        case '>':
                        case '<>':
                        case '<=':
                        case '>=':
                            $sqlValue = $this->_val($value[1]);
                            // $this->where[] = $sqlKey . ' ' . $value[0] . ' ' . $sqlValue;
                            $this->where[] = "$sqlKey {$value[0]} $sqlValue";
                            break;
                        case 'between':
                            $sqlValue1 = $this->_val($value[1][0]);
                            $sqlValue2 = $this->_val($value[1][1]);
                            $this->where[] = "$sqlKey BETWEEN $sqlValue1 AND $sqlValue2";
                            break;
                        case 'in':
                            $vals = array();
                            foreach($value[1] as $val) {
                                $vals[] = $this->_val($val);
                            }
                            $this->where[] = "$sqlKey IN (" . join(', ', $vals) . ')';
                            break;
                        default:
                            break;
                    }
                }
                else {
                    $sqlValue = $this->_val($value);
                    $this->where[] = $sqlKey . ' = ' . $sqlValue;
                }
            }
        }
        else {
            // raw
        }
        return $this;
    }

    function orWhere() {

    }

    function andWhere() {

    }

    function join() {

    }

    function order($params) {
        $this->order[] = $this->_key($params[0]) . ' ' . $params[1];
        return $this;
    }

    function group() {

    }

    function limit($offset, $limit) {
        $this->offset = (int) $offset;
        $this->limit = (int) $limit;
        return $this;
    }

    function exec() {

    }

    function sql() {
        $sql = $this->cmd;
        if($this->where) {
            $sql .= " WHERE " . join(' AND ', $this->where);
        }
        if($this->order) {
            $sql .= " ORDER BY " . join(', ', $this->order);
        }
        if($this->limit) {
            $sql .= " LIMIT " . $this->offset . ', ' . $this->limit;
        }
        return $sql;
    }

    // runner
    function run($sql = '') {
        $_sql = $sql ? $sql : $this->sql();
        return $this->db->query($_sql);
    }

    protected function _val($val) {
        return $this->db->toVal($val); 
    }

    protected function _key($key) {
        return $this->db->toKey($key);
    }
    protected function _reset() {
        $this->fields = '*';
        $this->where = array();
        $this->orWhere = array();
        $this->group = array();
        $this->order = array();
        $this->offset = 0;
        $this->limit = 0;
    }
}

class pdoTable extends pdoAssembler {
    private $insertId;
    private $runQueries = true;

    function findAll($where = array()) {
        $sql = $this->select()->where($where)->sql();
        $this->records = $this->db->fetchAll($sql);
        return $this->records;
    }

    function find($id) {
        $sql = $this->select()->where(array('id' => $id))->sql();
        $this->record = $this->db->fetchOne($sql);
        return $this->record;
    }

    function findBy($where) {

    }

    function findFull() {

    }

    function createOnly($data) {
        return $this->insert($data)->run();
    }

    function create($data) {
        $this->result = $this->createOnly($data);
        if($this->result) {
            $this->insert_id = $this->db->lastInsertId();
            $this->record = $this->find($this->insert_id);
            return $this->record;
        }
        return null;
    }

    function updateAll($params, $where = array()) {
        return $this->update($params)->where($where)->run();
    }

}
