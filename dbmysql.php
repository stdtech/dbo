<?

class DBMySQL
{

    var $host = ""; // MySQL host[:port]
    var $database = ""; // Default database
    var $user = ""; // Username
    var $password = ""; // Password
    var $persistent = false; // Use persistent connections?
    var $linkid = "";
    var $query = "";
    var $queryid = "";
    var $usetrans = 0; // Include support for transactions while executing script?
    var $on_err = ""; // User-defined error handler
    var $errstr = ""; // Last error string


    function __constructor($db_host = "", $db_user = "", $db_pass = "", $db_database = "", $db_persistent = false) {
        $this->DBMySQL($db_host, $db_user, $db_pass, $db_database, $db_persistent);
    }

    /* Class constructior */
    function DBMySQL($db_host = "", $db_user = "", $db_pass = "", $db_database = "", $db_persistent = false)
    {
        if ($db_host != '') $this->host = $db_host;
        if ($db_user != '') $this->user = $db_user;
        if ($db_pass != '') $this->password = $db_pass;
        if ($db_database != '') $this->database = $db_database;
        $this->persistent = $db_persistent;
    }

    /* Register error-handler function */
    function on_error($error_handler)
    {
        if (function_exists($error_handler)) {
            $this->on_err = $error_handler;
        }
    }

    /* Connect */
    function connect()
    {
        if ($this->persistent) {
            $this->linkid = @mysql_pconnect($this->host, $this->user, $this->password);
        } else {
            $this->linkid = @mysql_connect($this->host, $this->user, $this->password);
        }
        if (!$this->linkid) { // Call error handler
            $this->errstr = @mysql_error($this->linkid);
            if ($this->on_err != "") {
                call_user_func($this->on_err, 2, $this->errstr, __FILE__, __LINE__);
            }
            return false;
        }
        if (!@mysql_select_db($this->database, $this->linkid)) {
            $this->errstr = @mysql_error($this->linkid);
            if ($this->on_err != "") {
                call_user_func($this->on_err, 2, $this->errstr, __FILE__, __LINE__);
            }
            return false;
        }
        $this->query('SET NAMES \'utf8\';');
        return true;
    }

    /* Change user & db */
    function change_user($db_user, $db_pass, $db_database = '')
    {

        if ($db_database != '') {
            $res = @mysql_change_user($db_user, $db_pass, $db_database, $this->linkid);
        } else {
            $res = @mysql_change_user($db_user, $db_pass, $this->database, $this->linkid);
        }
        if (!$res) {
            return false;
        }
        $this->user = $db_user;
        $this->password = $db_pass;
        if ($db_database != '') {
            $this->database = $db_database;
        }
        return true;
    }

    /* Change db */
    function change_db($db_database)
    {
        $res = @mysql_select_db($db_database, $this->linkid);
        if ($res) $this->database = $db_database;
        return $res;
    }

    /* Execute script */
    function script($script)
    {
        $queries = split(';', $script);
        if ($this->usetrans) $this->query("BEGIN");
        for ($i = 0; $i < sizeof($queries); $i++) {
            if (($q = trim($queries[$i])) != '')
                if (!($this->query($q))) {
                    if ($this->usetrans) $this->query("ROLLBACK");
                    return false;
                }
        }
        if ($this->usetrans) $this->query("COMMIT");
        return true;
    }

    /* Query */
    function query($query)
    {
        $this->query = $query;
//                echo $query;
        $this->queryid = @mysql_query($query, $this->linkid);

        if (!$this->queryid) { // Call error handler
            $this->errstr = @mysql_error($this->linkid);
            if ($this->on_err != "")
                call_user_func($this->on_err, 2, $this->errstr, __FILE__, __LINE__);
            return false;
        }
        return $this->queryid;
    }

    /* Last ID */
    function insert_id()
    {
        return @mysql_insert_id($this->linkid);
    }

    /* Rows fetched */
    function num_rows($db_queryid)
    {
        return @mysql_num_rows($db_queryid);
    }

    /* Rows affected -- by 00alex */
    function affected_rows()
    {
        return @mysql_affected_rows($this->linkid);
    }

    /* Fetch all */
    function fetch_all($db_queryid)
    {
        $res = array();
        while ($row = @mysql_fetch_assoc($db_queryid))
            $res[] = $row;
        @mysql_free_result($db_queryid);
        return $res;
    }

    /* Fetch row */
    function fetch_row($db_queryid)
    {
        $row = @mysql_fetch_assoc($db_queryid);
        @mysql_free_result($db_queryid);
        return $row;
    }

    /* Fetch column */
    function fetch_column($db_queryid, $i = 0)
    {
        while ($row = @mysql_fetch_array($db_queryid, MYSQL_NUM))
            $res[] = $row[$i];
        @mysql_free_result($db_queryid);
        if (!isset($res))
            return array();
        else
            return $res;
    }

    /* Fetch one value */
    function fetch_value($db_queryid, $i = 0)
    {
        if ($row = @mysql_fetch_row($db_queryid))
            $res = $row[$i];
        else $res = false;
        @mysql_free_result($db_queryid);
        return $res;
    }

    /* Free query */
    function free_query($db_queryid)
    {
        @mysql_free_result($db_queryid);
    }

    /* Store all */
    function store_all($table, $data, $unique, $uval)
    {
        $res = true;
        $query = "UPDATE $table SET ";
        for ($i = 0; $i < sizeof($data); $i++) {
            $q = $this->gen_data($data);
            $q .= " WHERE $unique='$uval'";
            if (!$this->query($query . $q))
                $res = false;
        }
        return $res;
    }

    /* Store row */
    function store_row($table, $data, $where)
    {
        $query = "UPDATE $table SET ";
        $q = $this->gen_data($data);
        $q .= " $where";
        return $this->query($query . $q);
    }

    /* Insert all */
    function insert_all($table, $data)
    {
        $res = true;
        $query = "INSERT IGNORE $table SET ";
        for ($i = 0; $i < sizeof($data); $i++) {
            $q = $this->gen_data($data);
            if (!$this->query($query . $q))
                $res = false;
        }
        return $res;
    }

    /* Insert row */
    function insert_row($table, $data)
    {
        $query = "INSERT $table SET ";
        $q = $this->gen_data($data);
        $res = $this->query($query . $q);
        if (!$res) return false;
        if (($last_id = $this->insert_id()) != 0)
            return $last_id;
        else
            return $res;
    }

    /* Generate string for update/insert */
    function gen_data($data)
    {
        while (list ($key, $val) = each($data)) {
            if ($q) $q .= ', ';
            if (is_numeric($val)) {
                if (is_integer($val))
                    $q .= "$key=$val";
                else
                    $q .= "$key='$val'";
            } elseif (is_bool($val))
                $q .= "$key=" . ($val ? 1 : 0); elseif (is_string($val)) {
                if ($val == '')
                    $q .= "$key=NULL";
                else
                    $q .= "$key='$val'";
            } else {
                $q .= "$key='$val'";
            }
        }
        return $q;
    }

    /* Delete row */
    function delete_row($table, $where)
    {
        $q = "DELETE FROM $table $where";
        return $this->query($q);
    }

    /* Close connection */
    function close()
    {
        @mysql_close($this->linkid);
    }

    function escape_string($value) {
        return mysql_real_escape_string($value, $this->linkid);
    }
}

