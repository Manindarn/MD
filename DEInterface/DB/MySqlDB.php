<?php

// require_once $_SERVER['DOCUMENT_ROOT'] . DIRECTORY_SEPARATOR . 'db_credentials.php';

class MySqlDB
{

    private $DBName = null;
    private $tableName = null;
    protected $connection = null;

    public function __construct($tableName = null, $DBName = null)
    {

        $config = CONFIG['MySQL'];

        $servername = $config['host'];
        $username = $config['userName'] ?? "";
        $password = $config['password'] ?? "";
        
/*         $servername = MASTER_HOST;
        $username = MASTER_USER;
        $password = MASTER_PWD; */

        $this->DBName = $DBName ?? $config['DefaultDB'];
        $this->tableName = $tableName;

        // $this->connection = mysqli_connect($servername, $username, $password, $this->DBName);

        if (!isset($GLOBALS['mysqlConnection'])) {
            $GLOBALS['mysqlConnection'] = mysqli_connect($servername, $username, $password, $this->DBName);
        }

        $this->connection = $GLOBALS['mysqlConnection'];

        if (!$this->connection) {
            die("Connection failed: " . mysqli_connect_error());
        }

    }

    public function insertMany($data)
    {
        $response = null;
        try {

            $newDataValue = [];
            foreach ($data as $dataKey => $dataValue) {
                $newDataValue[] = '"' . implode('", "', array_values($dataValue)) . '"';
            }
            $columns = array_keys(current($data));

            $columns = '`' . implode('`,`', $columns) . '`';
            $values = '(' . implode('), (', $newDataValue) . ')';

            $sql = "INSERT IGNORE INTO `$this->DBName`.`$this->tableName` ($columns) VALUES $values";

            $returnData = mysqli_query($this->connection, $sql);

        } catch (Exception $exc) {
            $response = ['status' => 'error', 'msg' => "Something went wrong: " . $exc->getTraceAsString()];
        }
        return $response;
    }

    public function get($where = 1, $selectedColumns = null)
    {

        $columns = $selectedColumns ? implode(',', $selectedColumns) : '*';
        try {
            $sql = "SELECT $columns FROM `$this->DBName`.`$this->tableName` WHERE $where LIMIT 1";

            $result = mysqli_query($this->connection, $sql);

            $data = [];
            if ($result) {
                $data = mysqli_fetch_assoc($result);
                mysqli_free_result($result);
            }
            $response = ['status' => 'success', 'msg' => 'Record fetched successfully.', 'data' => $data];

        } catch (Exception $exc) {
            $response = ['status' => 'error', 'msg' => "Something went wrong: " . $exc->getTraceAsString()];
        }
        return $response;
    }

    public function getCount($where = 1)
    {
        $response = 0;

        try {
            $sql = "SELECT count(*) count FROM `$this->DBName`.`$this->tableName` WHERE $where";

            $result = mysqli_query($this->connection, $sql);

            $data = [];
            if ($result) {
                $data = mysqli_fetch_assoc($result);
                mysqli_free_result($result);
            }
            $response = $data['count'] ?? 0;

        } catch (Exception $exc) {
            // $response = ['status' => 'error', 'msg' => "Something went wrong: " . $exc->getTraceAsString()];
        }
        return $response;
    }

    public function getAll($where = 1, $selectedColumns = null, $options = [])
    {
        $response = null;

        try {

            $selectedColumns = $selectedColumns && is_array($selectedColumns) ? implode(', ', $selectedColumns) : '*';

            $sql = "SELECT $selectedColumns FROM `$this->DBName`.`$this->tableName` WHERE $where ";

            if (isset($options['limit']) && isset($options['offset'])) {
                $sql .= ' LIMIT ' . $options['offset'] . ',' . $options['limit'];
            }

            $result = mysqli_query($this->connection, $sql);
            $data = mysqli_fetch_all($result, MYSQLI_ASSOC);
            mysqli_free_result($result);

            $response = ['status' => 'success', 'msg' => 'Record fetched successfully.', 'data' => $data];

        } catch (Exception $e) {
            $response = ['status' => 'error', 'msg' => "Something went wrong: " . $exc->getTraceAsString()];
        }

        return $response;
    }

    public function rawQuery($query, $return = true)
    {
        $response = null;

        try {
            $result = mysqli_query($this->connection, $query);

            $response = [];
            if ($return && $result) {
                while ($row = $result->fetch_assoc()) {
                    $response[] = $row;
                }
                mysqli_free_result($result);
            }
            $response = ['status' => 'success', 'msg' => 'Record fetched successfully.', 'data' => $response];

        } catch (Exception $e) {
            $response = ['status' => 'error', 'msg' => "Something went wrong: " . $exc->getTraceAsString()];
        }

        return $response;
    }

    public function update($where, $data)
    {
        $response = null;

        try {

            if (is_array($data)) {
                $set = '';
                $tmp = [];
                array_walk($data, function ($value, $key) use (&$tmp) {
                    $tmp[] = '`' . $key . '` =' . '"' . $value . '"';
                });

                $set = implode(', ', $tmp);

                $sql = "UPDATE `$this->DBName`.`$this->tableName` SET $set WHERE $where ";

                $result = mysqli_query($this->connection, $sql);

                if ($result) {
                    $response = ['status' => 'success', 'msg' => 'Record updated successfully.'];
                }
            } else {
                $response = ['status' => 'error', 'msg' => "Data not in array format"];
            }

        } catch (Exception $e) {
            $response = ['status' => 'error', 'msg' => "Something went wrong: " . $exc->getTraceAsString()];
        }

        return $response;
    }

    public function delete($where)
    {
        return mysqli_query($this->connection, "DELETE FROM `$this->DBName`.`$this->tableName` WHERE $where");
    }

}
