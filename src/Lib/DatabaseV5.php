<?php
/**
 * Desc:
 * Created by PhpStorm.
 * User: xuanskyer | <furthestworld@iloucd.com>
 * Date: 2016-12-13 10:09:52
 */
namespace Lib;

use \PDO;
use \Exception;

class DatabaseV5 {

    static    $option   = [];
    protected $db_config;
    protected $options  = [];
    protected $params   = [];
    protected $last_sql = "";
    protected $exp      = [
        'eq'          => '=',
        'neq'         => '<>',
        'gt'          => '>',
        'egt'         => '>=',
        'lt'          => '<',
        'elt'         => '<=',
        'notlike'     => 'NOT LIKE',
        'like'        => 'LIKE',
        'in'          => 'IN',
        'notin'       => 'NOT IN',
        'not in'      => 'NOT IN',
        'between'     => 'BETWEEN',
        'not between' => 'NOT BETWEEN',
        'notbetween'  => 'NOT BETWEEN'
    ];
    protected $dsn, $pdo;
    // 链操作方法列表
    protected $methods = ['from', 'data', 'field', 'table', 'order', 'alias', 'having', 'group', 'lock', 'distinct', 'auto'];

    public function __construct($options = []) {
        $default_options = [
            'dbtype'     => 'mysql',
            'host'       => '127.0.0.1',
            'port'       => 3306,
            'dbname'     => 'test',
            'username'   => 'root',
            'password'   => '',
            'charset'    => 'utf8',
            'timeout'    => 3,
            'presistent' => false, //长连接
        ];

        self::$option    = array_merge($default_options, $options);
        $this->db_config = self::$option;
        $this->dsn       = $this->createDsn(self::$option);

    }

    private function createDsn($options) {
        return $options['dbtype'] . ':host=' . $options['host'] . ';dbname=' . $options['dbname'] . ';port=' . $options['port'];
    }


    public function query($query, $data = []) {
        $this->last_sql = $this->setLastSql($query, $data);
        if (PHP_SAPI == 'cli') {
            try {
                @$this->connection()->getAttribute(PDO::ATTR_SERVER_INFO);
            } catch (\PDOException $e) {
                if ($e->getCode() != 'HY000' || !stristr($e->getMessage(), 'server has gone away')) {
                    throw $e;
                }
                $this->reconnect();
            }
        }

        $stmt = $this->connection()->prepare($query);
        $stmt->execute($data);
        $this->options = $this->params = [];
        return $stmt;
    }

    public function reconnect() {
        $this->pdo = null;
        return $this->connect();
    }

    protected function connection() {
        return $this->pdo instanceof \PDO ? $this->pdo : $this->connect();
    }

    public function connect() {
        try {
            $option                       = self::$option['charset'] ? [PDO::MYSQL_ATTR_INIT_COMMAND => 'SET NAMES ' . self::$option['charset']] : null;
            $option[PDO::ATTR_TIMEOUT]    = self::$option['timeout'];
            $option[PDO::ATTR_PERSISTENT] = isset(self::$option['presistent']) ? self::$option['presistent'] : false;
            $this->pdo                    = new \PDO(
                $this->dsn,
                self::$option['username'],
                self::$option['password'],
                $option
            );
            $this->pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        } catch (Exception $e) {
            throw new Exception($e);
        }
        return $this->pdo;
    }

    public function insert($data = []) {
        $this->options['type'] = 'INSERT';
        return $this->save($data);
    }

    /**
     * @node_name 批量新增操作事务保存
     * @param array $data
     * @return bool
     */
    public function batchInsert($data = []) {
        $res        = false;
        $shift_data = array_shift($data);
        if (is_array($shift_data) && !empty($shift_data)) {

            $keys        = array_keys($shift_data);
            $fields      = '`' . implode('`, `', $keys) . '`';
            $placeholder = substr(str_repeat('?,', count($keys)), 0, -1);
            $query       = "INSERT INTO `" . $this->options['table'] . "`($fields) VALUES($placeholder)";
            try {
                self::beginTransaction(); //开启事务
                $this->query($query, array_values($shift_data));

                foreach ($data as $one) {
                    $this->query($query, array_values($one));
                }
                self::commit();
                $res = true;
            } catch (PDOException $e) {
                if (self::inTransaction()) {
                    self::rollBack();
                }
            }
        }
        return $res;
    }

    public function select($sql = null, $data = []) {
        $sql  = $sql ? $sql : $this->getQuery();
        $data = empty($data) ? $this->params : $data;
        $stmt = $this->query($sql, $data);

        $result = $stmt->fetchAll(PDO::FETCH_ASSOC);
        return $result;
    }

    private function setLastSql($string, $data) {
        $indexed = $data == array_values($data);
        foreach ($data as $k => $v) {
            if (is_string($v)) $v = "'$v'";
            if ($indexed) $string = preg_replace('/\?/', $v, $string, 1);
            else $string = str_replace(":$k", $v, $string);
        }
        return $string;
    }

    public function getLastSql() {
        return $this->last_sql;
    }

    public function fetch($sql = null) {
        $sql = $sql ? $sql : $this->getQuery();

        $stmt   = $this->query($sql, $this->params);
        $result = $stmt->fetch(PDO::FETCH_ASSOC);
        empty($result) && $result = [];
        return $result;
    }


    public function update($data = []) {
        $this->options['type'] = 'UPDATE';
        return $this->save($data);
    }

    public function delete() {
        $this->options['type'] = 'DELETE';
        return $this->save();
    }

    public function fetchOne($sql = null) {
        $this->options['limit'] = 1;
        $sql                    = $sql ? $sql : $this->getQuery();

        $stmt   = $this->query($sql, $this->params);
        $result = $stmt->fetch(PDO::FETCH_NUM);

        if (isset($result[0])) return $result[0];
        return null;
    }

    public function save($data = []) {
        if (!empty($data)) $this->data($data);
        if (!isset($this->options['type'])) {
            $this->options['type'] = isset($this->options['where']) ? 'UPDATE' : 'INSERT';
        }

        switch ($this->options['type']) {
            case 'INSERT':
                $keys        = array_keys($this->options['data']);
                $fields      = '`' . implode('`, `', $keys) . '`';
                $placeholder = substr(str_repeat('?,', count($keys)), 0, -1);
                $query       = "INSERT INTO `" . $this->options['table'] . "`($fields) VALUES($placeholder)";

                return $this->query($query, array_values($data)) ? ($this->pdo->lastInsertId() ? $this->pdo->lastInsertId() : true) : false;
                break;
            case 'UPDATE':
                $update_field = [];
                $this->params = array_merge(array_values($this->options['data']), $this->params);
                foreach ($this->options['data'] as $key => $value) {
                    $update_field[] = "`$key`= ?";
                }
                $query = "UPDATE `" . $this->options['table'] . "` SET " . implode(",", $update_field) . " WHERE " . implode(" AND ", $this->options['where']);
                $this->query($query, $this->params);
                break;
            case 'DELETE':
                $query = "DELETE FROM `" . $this->options['table'] . "` WHERE " . implode(" AND ", $this->options['where']);
                $this->query($query, $this->params);
                break;
            default:
                # code...
                break;
        }
        return true;
    }

    private function getQuery() {
        $sql = "SELECT ";
        //parse field
        if (isset($this->options['field'])) {
            $sql .= " " . $this->options['field'] . " ";
        } else {
            $sql .= " * ";
        }
        //parse table
        if (isset($this->options['table'])) {
            $sql .= " FROM " . $this->options['table'] . " ";
        }
        //parse join
        if (isset($this->options['join'])) {
            $sql .= $this->options['join'] . " ";
        }
        //parse where
        if (isset($this->options['where'])) {
            $sql .= " WHERE " . implode(" AND ", $this->options['where']) . " ";
        }
        //parse group
        if (isset($this->options['group']) && !empty($this->options['group'])) {
            $sql .= " GROUP BY " . $this->options['group'] . " ";
        }
        //parse having
        if (isset($this->options['having'])) {
            $sql .= " HAVING " . $this->options['having'] . " ";
        }
        //parse order
        if (isset($this->options['order'])) {
            $sql .= " ORDER BY " . $this->options['order'] . " ";
        }
        //parse limit
        if (isset($this->options['limit'])) {
            $sql .= " LIMIT " . $this->options['limit'];
        }
        return $sql;
    }

    public function __call($method, $args) {
        if (in_array(strtolower($method), $this->methods, true)) {
            $this->options[strtolower($method)] = $args[0];
            return $this;
        } elseif (in_array(strtolower($method), ['count', 'sum', 'min', 'max', 'avg'], true)) {
            $field                  = (isset($args[0]) && !empty($args[0])) ? $args[0] : '*';
            $as                     = '_' . strtolower($method);
            $this->options['field'] = strtoupper($method) . '(' . $field . ') AS ' . $as;
            return $this->fetchOne();
        } else {
            return null;
        }
    }

    public function addParams($params) {
        if (is_null($params)) {
            return;
        }

        if (!is_array($params)) {
            $params = [$params];
        }

        $this->params = array_merge($this->params, $params);
    }

    /**
     * Add statement for where - ... WHERE [?] ...
     *
     * Examples:
     * $sql->where(array('uid'=>3, 'pid'=>2));
     * $sql->where("user_id = ?", $user_id);
     * $sql->where("u.registered > ? AND (u.is_active = ? OR u.column IS NOT NULL)", array($registered, 1));
     *
     * @node_name
     * @return $this
     */
    public function where() {
        $args      = func_get_args();
        $statement = $params = null;
        $query_w   = [];

        if (func_num_args() == 1 && is_array($args[0])) {
            foreach ($args[0] as $k => $v) {
                $query_w[] = "`$k` = ?";
            }
            $statement = implode(" AND ", $query_w);
            $params    = array_values($args[0]);
        } else {
            $statement = array_shift($args);

            $params = isset($args[0]) && is_array($args[0]) ? $args[0] : $args;
        }
        if (!empty($statement)) {
            $this->options['where'][] = $statement;
            $this->addParams($params);
        }
        return $this;
    }

    public function limit($offset, $length = null) {
        $this->options['limit'] = is_null($length) ? $offset : $offset . ',' . $length;
        return $this;
    }

    /**
     * where分析
     * @access protected
     * @param mixed $where
     * @return string
     */
    public function parseWhere($where) {
        $whereStr = '';
        if (is_string($where)) {
            // 直接使用字符串条件
            $whereStr = $where;
        } else { // 使用数组表达式
            $operate = isset($where['_logic']) ? strtoupper($where['_logic']) : '';
            if (in_array($operate, ['AND', 'OR', 'XOR'])) {
                // 定义逻辑运算规则 例如 OR XOR AND NOT
                $operate = ' ' . $operate . ' ';
                unset($where['_logic']);
            } else {
                // 默认进行 AND 运算
                $operate = ' AND ';
            }
            foreach ($where as $key => $val) {
                if (is_numeric($key)) {
                    $key = '_complex';
                }
                if (0 === strpos($key, '_')) {
                    // 解析特殊条件表达式
                    $whereStr .= $this->parseThinkWhere($key, $val);
                } else {
                    // 查询字段的安全过滤
                    // if(!preg_match('/^[A-Z_\|\&\-.a-z0-9\(\)\,]+$/',trim($key))){
                    //     E(L('_EXPRESS_ERROR_').':'.$key);
                    // }
                    // 多条件支持
                    $multi = is_array($val) && isset($val['_multi']);
                    $key   = trim($key);
                    if (strpos($key, '|')) { // 支持 name|title|nickname 方式定义查询字段
                        $array = explode('|', $key);
                        $str   = [];
                        foreach ($array as $m => $k) {
                            $v     = $multi ? $val[$m] : $val;
                            $str[] = $this->parseWhereItem($this->parseKey($k), $v);
                        }
                        $whereStr .= '( ' . implode(' OR ', $str) . ' )';
                    } elseif (strpos($key, '&')) {
                        $array = explode('&', $key);
                        $str   = [];
                        foreach ($array as $m => $k) {
                            $v     = $multi ? $val[$m] : $val;
                            $str[] = '(' . $this->parseWhereItem($this->parseKey($k), $v) . ')';
                        }
                        $whereStr .= '( ' . implode(' AND ', $str) . ' )';
                    } else {
                        $whereStr .= $this->parseWhereItem($this->parseKey($key), $val);
                    }
                }
                $whereStr .= $operate;
            }
            $whereStr = substr($whereStr, 0, -strlen($operate));
        }
        return empty($whereStr) ? '' : ' ' . $whereStr;
    }

    // where子单元分析
    protected function parseWhereItem($key, $val) {
        $whereStr = '';

        if (is_array($val)) {
            if (is_string($val[0])) {
                $exp = strtolower($val[0]);
                if (preg_match('/^(eq|neq|gt|egt|lt|elt)$/', $exp)) { // 比较运算
                    $whereStr .= $key . ' ' . $this->exp[$exp] . ' ' . $this->parseValue($val[1]);
                } elseif (preg_match('/^(notlike|like)$/', $exp)) {// 模糊查找
                    if (is_array($val[1])) {
                        $likeLogic = isset($val[2]) ? strtoupper($val[2]) : 'OR';
                        if (in_array($likeLogic, ['AND', 'OR', 'XOR'])) {
                            $like = [];
                            foreach ($val[1] as $item) {
                                $like[] = $key . ' ' . $this->exp[$exp] . ' ' . $this->parseValue($item);
                            }
                            $whereStr .= '(' . implode(' ' . $likeLogic . ' ', $like) . ')';
                        }
                    } else {
                        $whereStr .= $key . ' ' . $this->exp[$exp] . ' ' . $this->parseValue($val[1]);
                    }
                } elseif ('bind' == $exp) { // 使用表达式
                    $whereStr .= $key . ' = :' . $val[1];
                } elseif ('exp' == $exp) { // 使用表达式
                    $whereStr .= $key . ' ' . $val[1];
                } elseif (preg_match('/^(notin|not in|in)$/', $exp)) { // IN 运算
                    if (isset($val[2]) && 'exp' == $val[2]) {
                        $whereStr .= $key . ' ' . $this->exp[$exp] . ' ' . $val[1];
                    } else {
                        if (is_string($val[1]) || is_numeric($val[1])) {
                            $val[1] = explode(',', $val[1]);
                        }
                        $zone = implode(',', $this->parseValue($val[1]));
                        $whereStr .= $key . ' ' . $this->exp[$exp] . ' (' . $zone . ')';
                    }
                } elseif (preg_match('/^(notbetween|not between|between)$/', $exp)) { // BETWEEN运算
                    $data = is_string($val[1]) ? explode(',', $val[1]) : $val[1];
                    $whereStr .= $key . ' ' . $this->exp[$exp] . ' ' . $this->parseValue($data[0]) . ' AND ' . $this->parseValue($data[1]);
                } else {
                    die('parseWhereItem error!');
                }
            } else {
                $count = count($val);
                $rule  = isset($val[$count - 1]) ? (is_array($val[$count - 1]) ? strtoupper($val[$count - 1][0]) : strtoupper($val[$count - 1])) : '';
                if (in_array($rule, ['AND', 'OR', 'XOR'])) {
                    $count = $count - 1;
                } else {
                    $rule = 'AND';
                }
                for ($i = 0; $i < $count; $i++) {
                    $data = is_array($val[$i]) ? $val[$i][1] : $val[$i];
                    if ('exp' == strtolower($val[$i][0])) {
                        $whereStr .= $key . ' ' . $data . ' ' . $rule . ' ';
                    } else {
                        $whereStr .= $this->parseWhereItem($key, $val[$i]) . ' ' . $rule . ' ';
                    }
                }
                $whereStr = '( ' . substr($whereStr, 0, -4) . ' )';
            }
        } else {
            //对字符串类型字段采用模糊匹配
            $likeFields = 'title|remark';
            if ($likeFields && preg_match('/^(' . $likeFields . ')$/i', $key)) {
                $whereStr .= $key . ' LIKE ' . $this->parseValue('%' . $val . '%');
            } else {
                $whereStr .= $key . ' = ' . $this->parseValue($val);
            }
        }
        return $whereStr;
    }

    /**
     * 特殊条件分析
     * @access protected
     * @param string $key
     * @param mixed  $val
     * @return string
     */
    protected function parseThinkWhere($key, $val) {
        $whereStr = '';
        switch ($key) {
            case '_string':
                // 字符串模式查询条件
                $whereStr = $val;
                break;
            case '_complex':
                // 复合查询条件
                $whereStr = substr($this->parseWhere($val), 6);
                break;
            case '_query':
                // 字符串模式查询条件
                parse_str($val, $where);
                if (isset($where['_logic'])) {
                    $op = ' ' . strtoupper($where['_logic']) . ' ';
                    unset($where['_logic']);
                } else {
                    $op = ' AND ';
                }
                $array = [];
                foreach ($where as $field => $data)
                    $array[] = $this->parseKey($field) . ' = ' . $this->parseValue($data);
                $whereStr = implode($op, $array);
                break;
        }
        return '( ' . $whereStr . ' )';
    }

    /**
     * 字段名分析
     * @access protected
     * @param string $key
     * @return string
     */
    protected function parseKey(&$key) {
        return $key;
    }

    /**
     * value分析
     * @access protected
     * @param mixed $value
     * @return string
     */
    protected function parseValue($value) {
        if (is_string($value)) {
            $value = strpos($value, ':') === 0 && in_array($value, array_keys($this->bind)) ? $this->escapeString($value) : '\'' . $this->escapeString($value) . '\'';
        } elseif (isset($value[0]) && is_string($value[0]) && strtolower($value[0]) == 'exp') {
            $value = $this->escapeString($value[1]);
        } elseif (is_array($value)) {
            $value = array_map([$this, 'parseValue'], $value);
        } elseif (is_bool($value)) {
            $value = $value ? '1' : '0';
        } elseif (is_null($value)) {
            $value = 'null';
        }
        return $value;
    }

    /**
     * SQL指令安全过滤
     * @access public
     * @param string $str SQL字符串
     * @return string
     */
    public function escapeString($str) {
        return addslashes($str);
    }

    /**
     * @node_name 开启事务
     */
    public function beginTransaction() {
        $this->connection()->beginTransaction();
    }

    /**
     * @node_name 回滚事务
     */
    public function rollBack() {
        $this->connection()->rollBack();

    }

    /**
     * @node_name 提交事务
     */
    public function commit() {

        $this->connection()->commit();
    }

}
