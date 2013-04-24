<?

require_once ('dbmysql.php');


class DBOException extends Exception {}


class DBObject
{
    var $db;
    var $last_result;
    var $insert_id;
    var $tables;

    function __construct($config) {
        $this->DBObject($config);
    }

    function DBObject($config)
    {
        if (!is_object($this->db)) {
            $this->db = new DBMySQL(
                $config['mysql']['domain'],
                $config['mysql']['db']['user'],
                $config['mysql']['db']['password'],
                $config['mysql']['db']['name'],
                false
            );
            $res = $this->db->connect();
            if (!$res) {
                throw new DBOException("can't connect to MySQL {$this->db->errstr}");
            }
            $this->db->query("SET NAMES 'utf8' COLLATE 'utf8_unicode_ci'");
        }
        $this->tables = array();
        return $this;
    }

    function get_result() {
        return $this->last_result;
    }

    function get_insert_id() {
        return $this->insert_id;
    }

    function start_transaction()
    {
        $this->last_result = $this->execute("start transaction");
        return $this;
    }

    function commit()
    {
        $this->last_result = $this->execute("commit");
        return $this;
    }

    function rollback()
    {
        $this->last_result = $this->execute("rollback");
        return $this;
    }

    function execute($sql)
    {
        // extention for supporting simple SQL
        $this->last_result = $this->db->query($sql);
        if (!$this->last_result) {
            throw new DBOException("wrong SQL:\n{$sql}\n");
        }
        $this->insert_id = $this->db->insert_id(); // if $sql is an update - some strange stuff may be returned
        return $this;
    }

    function query($sql, $mode = "all")
    {
        if (!$query = $this->db->query($sql)) {
            throw new DBOException("wrong SQL:\n{$sql}");
        }
        if ($mode == "column") {
            return $this->db->fetch_column($query);
        } else {
            return $this->db->fetch_all($query);
        }
    }

    function init_tables($tables) {
        if (!is_array($tables)) {
            throw new DBOException("Incorrect tables data");
        }
        foreach ($tables as $k => $v) {
            if (!is_array($v)) {
                throw new DBOException("Incorrect table data for '{$k}'");
            }
        }
        $this->tables = $tables;
    }

    function get_prototype($prototype) {
        if (is_array($prototype)) {
            return $prototype;
        } elseif (is_string($prototype) && isset($this->tables[$prototype])) {
            return $this->tables[$prototype];
        }
        throw new DBOException("No correct table '{$prototype}'");
    }

    function set($prototype, $data, $filter = null, $full = true)
    {
        $prototype = $this->get_prototype($prototype);

        // avoid cycles in the tree for update operation
        if (isset($prototype['parent_key'])) {
            if ($filter && $prototype['parent_key'] && isset($data[$prototype['parent_key']])) {
                $path_keys = array();
                $path_keys = $this->list_values(
                    $this->list_tree($this->get_tree($prototype, "path", $data[$prototype['parent_key']])), $prototype['key']);
                $keys = $this->list_values($this->get($prototype, $filter), $prototype['key']);
                if (array_intersect($path_keys, $keys)) {
                    throw new DBOException("cycle in the tree");
                }
            }
        }

        $fields = array_keys($prototype['fields']);
        $set = "";
        reset($fields);
        while (list(, $field) = each($fields)) {
            if (($full && $prototype['key'] != $field) || isset($data[$field])) {
                $value = isset($data[$field]) ? $data[$field] : null;
                if (!(($sql_value = $this->value_to_sql($value, $prototype['fields'][$field])) === false)) {
                    $set .= ($set ? ", " : "") . "`$field`={$sql_value}";
                } else {
                    return false;
                }
            }
        }

        $where = $this->make_where($prototype, $filter);
        if (isset($filter)) { // update
            if (!isset($where)) {
                throw new DBOException("we do not update all records in a table");
            } else {
                $sql = "update " . $prototype['table'] . " set {$set} where {$where}";
                if (!$this->db->query($sql)) {
                    throw new DBOException("wrong SQL:\n{$sql}");
                }
            }
        } else { // insert
            $sql = "insert " . $prototype['table'] . " set {$set}";
            if (!$this->db->query($sql)) {
                throw new DBOException("wrong SQL:\n{$sql}");
            }
            $filter = $this->db->insert_id();
        }

        return $filter;
    }

    function get($prototype, $filter = null, $order = null)
    {
        $prototype = $this->get_prototype($prototype);

        $mkorder = null;
        if ($order) {
            $tmp_order = explode(".", $order);
            foreach ($tmp_order as $tt) {
                $mkorder[] = '`' . $tt . '`';
            }
            $mkorder = join(".", $mkorder);
        }

        $where = $this->make_where($prototype, $filter);
        $sql =
            "select * from " . $prototype['table'] .
                ($where ? " where $where" : "") .
                ($mkorder ? " order by $mkorder" : "");

        if (!$query = $this->db->query($sql)) {
            throw new DBOException("wrong SQL:\n{$sql}\n");
        }
        $res = $this->db->fetch_all($query);

        return $res;
    }

    function get_tree($prototype, $what, $root = null, $order = null)
    {

        /** $what ==  "classes", "children", "branches", "tree", "path", "path&branches" */

        $prototype = $this->get_prototype($prototype);

        $res = array();

        if (!$root) {
            $root = "is null";
        } elseif ($root == 'null') {
            $root = "is null";
        }

        switch ($what) {
            case 'classes':
                $res = $this->get($prototype, $root, $order);
                break;

            case 'children':
                $root = array($prototype['parent_key'] => ($root ? $root : "is null"));
                $res = $this->get($prototype, $root, $order);
                break;

            case 'branches':
                $root = array($prototype['parent_key'] => ($root ? $root : "is null"));
                $res = $this->get($prototype, $root, $order);
                if (is_array($res) && sizeof($res) > 0) {
                    $len = sizeof($res);
                    for ($i = 0; $i < $len; $i++) {
                        $res[$i]['children'] = $this->get_tree($prototype, $what, $res[$i][$prototype[key]], $order);
                    }
                }
                break;

            case 'tree':
                if ($root != 'is null') {
                    $res = $this->get($prototype, $root);
                    if (is_array($res) && sizeof($res) > 0) {
                        $res[0]['children'] = $this->get_tree($prototype, "branches", $res[0][$prototype['key']], $order);
                    }
                } else {
                    $res = $this->get_tree($prototype, "branches", null, $order);
                }
                break;

            case 'path':
                if ($root != 'is null') {
                    $res = $this->get($prototype, $root);
                    if (sizeof($res) > 0 && $res[0][$prototype['parent_key']]) {
                        $parents = $this->get_tree($prototype, "path", $res[0][$prototype['parent_key']]);
                        if (sizeof($parents) > 0) {
                            $tmp = &$parents;
                            while (isset($tmp[0]['children']) && is_array($tmp[0]['children']) && sizeof($tmp[0]['children']) > 0) {
                                $tmp = &$tmp[0]['children'];
                            }
                            $tmp[0]['children'] = $res;
                            $res = &$parents;
                        }
                    }
                }
                break;

            case 'path&branches':
                if ($root != 'is null') {
                    $children = $this->get($prototype, "branches", $root, $order);
                    $res = $this->get($prototype, "path", $root);
                    if (sizeof($res) > 0) {
                        $tmp = &$res;
                        while (sizeof($tmp[0]['children']) > 0) {
                            $tmp = &$tmp[0]['children'];
                        }
                        $tmp[0]['children'] = $children;
                    }
                }
                break;

            default:
                break;
        }

        return $res;
    }

    function del($prototype, $filter = null)
    {
        $prototype = $this->get_prototype($prototype);

        $where = $this->make_where($prototype, $filter);
        if ($where) { // $where should not be empty for deletion
            $sql = "delete from " . $prototype['table'] . " where {$where}";
            if (!$last_result = $this->db->query($sql)) {
                throw new DBOException("wrong SQL:\n{$sql}");
            }
        } else {
            throw new DBOException("request for deletion of all table ($where)");
        }

        return $this;
    }

    function value_to_sql($value, $type)
    {
        $res = null;
        if ($value === 'false' || !isset($value) || $value === "" || $value === 'null') {
            $res = 'null';
        } else {
            switch ($type) {
                case 'date':
                    if ($value == 'now()') {
                        $res = "{$value}";
                    } elseif (!preg_match("/^[0-9]{4}-[0-9]{1,2}-[0-9]{1,2}( [0-9]{2}:[0-9]{2}:[0-9]{2})?$/", $value)) {
                        throw new DBOException("wrong date format '$value'");
                    } else {
                        $res = "'{$value}'";
                    }
                    break;

                case 'int':
                    if (!preg_match("/^[+-]?[0-9]+$/", $value)) {
                        throw new DBOException("wrong int format '$value'");
                    }
                    $res = "{$value}";
                    break;

                case 'float':
                    if (!preg_match("/^[+-]?([0-9]+\\.?[0-9]*|\\.[0-9]+)(e[+-][0-9]+)?$/", $value)) {
                        throw new DBOException("wrong float format '$value'");
                    }
                    $res = "{$value}";
                    break;

                case 'blob':
                    $res = "('" . $this->db->escape_string($value) . "')";
                    break;

                case 'char':
                default:
                    $res = "'" . addslashes($value) . "'";
                    break;
            }
        }
        return $res;
    }

    function make_where($prototype, $filter = null)
    {
        $prototype = $this->get_prototype($prototype);

        $where = null;
        if (isset($filter)) {
            if (is_array($filter)) {
                reset($filter);
                while (list($field, $value) = each($filter)) {
                    if ($value) {
                        if (preg_match("/^(=?)search:(.*)$/", $field, $res)) {
                            list(, $strict, $field_list) = $res;
                            if ($field_list) {
                                $fields = explode(',', $field_list);
                            } else {
                                $fields = array();
                                $tmp = $prototype['fields'];
                                reset($tmp);
                                while (list($k,) = each($tmp)) {
                                    if ($prototype['fields'][$k] == 'char') {
                                        $fields[] = $k;
                                    }
                                }
                            }
                            $sub_where = array();
                            reset($fields);
                            while (list(, $sub_field) = each($fields)) {
                                if ($strict) {
                                    $sub_where[] = "`$sub_field` like " . $this->value_to_sql("%{$value}%", 'char');
                                } else {
                                    $sub_where[] = "lower(`$sub_field`) like lower(" . $this->value_to_sql("%{$value}%", 'char') . ")";
                                }
                            }
                            if ($prototype['key']) {
                                $sub_where[] = "`" . $prototype['key'] . "`=" . $this->value_to_sql($value, 'char');
                            }
                            if (sizeof($sub_where) > 0) {
                                $where = ($where ? $where . " and " : "") . "( (" . join(') or (', $sub_where) . ") )";
                            }
                        } else {
                            if (is_int($field)) {
                                if (is_array($value)) {
                                    $where = ($where ? $where . " and " : "") . "( (" . join(") or (", $value) . ") )";
                                } else {
                                    $where = ($where ? $where . " and " : "") . "( {$value} )";
                                }
                            } elseif ($value == "is null" || $value == "is not null") {
                                $where = ($where ? $where . " and " : "") . "( {$field} {$value} )";
                            } else {
                                $op = "=";
                                if (preg_match("/^(%|<|<=|>|>=|=|<>)/", $value)) {
                                    $op = preg_replace("/^(%|<|<=|>|>=|=|<>).*$/", "\\1", $value);
                                    $value = preg_replace("/^(%|<|<=|>|>=|=|<>)(.*)$/", "\\2", $value);
                                }
                                if ($op == '%') {
                                    if ($prototype['fields'][$field] == 'char') {
                                        if ($sql_value = $this->value_to_sql("%{$value}%", 'char')) {
                                            $where = ($where ? $where . " and " : "") . "( `{$field}` like {$sql_value} )";
                                        } else {
                                            return false;
                                        }
                                    } else {
                                        return false;
                                    }
                                } elseif ($sql_value = $this->value_to_sql($value, $prototype['fields'][$field])) {
                                    $where = ($where ? $where . " and " : "") . "( `{$field}` {$op} {$sql_value} )";
                                } else {
                                    return false;
                                }
                            }
                        }
                    }
                }
            } else { // $filter is just an ID
                $where = "`" . (isset($prototype['key']) ? $prototype['key'] : "id") . "` = {$filter}";
            }
        }
        return $where;
    }

    public static function remap_table($table, $key, $column=Null)
    {
        $tmp = array();

        if (is_array($table)) {
            if ($column) {
                foreach ($table as $item) {
                    $tmp[$item[$key]] = $item[$column];
                }
            } else {
                foreach ($table as $item) {
                    $tmp[$item[$key]] = $item;
                }
            }
        }

        return $tmp;
    }

    public static function regroup_table($table, $key)
    {

        $tmp = array();

        if (is_array($table)) {
            foreach ($table as $item) {
                $tmp[$item[$key]][] = $item;
            }
        }

        return $tmp;
    }

    public static function list_values($table, $key)
    {

        $tmp = array();

        if (is_array($table)) {
            foreach ($table as $item) {
                $tmp[] = $item[$key];
            }
        }

        return $tmp;
    }

    public static function list_tree($table, &$source = null, $level = 0)
    {
        if (!$source) {
            $source = array();
        }

        $len = sizeof($table);
        for ($i = 0; $i < $len; $i++) {
            if (is_array($table[$i])) {
                $table[$i]['level'] = $level;
            }
            if (isset($table[$i]['children']) && is_array($table[$i]['children']) && sizeof($table[$i]['children'])) {
                $tmp = $table[$i]['children'];
                $table[$i]['children'] = null;
                $source[] = $table[$i];
                list_tree($tmp, $source, $level + 1);
            } else {
                $source[] = $table[$i];
            }
        }

        return $source;
    }
}

