<?php

namespace alojaweb\inc;

class DBUtils
{
    public static $TASK_METRICS = [
        'Duration',
        'Bytes Read',
        'Bytes Written',
        'FILE_BYTES_WRITTEN',
        'FILE_BYTES_READ',
        'HDFS_BYTES_WRITTEN',
        'HDFS_BYTES_READ',
        'Spilled Records',
        'SPLIT_RAW_BYTES',
        'Map input records',
        'Map output records',
        'Map input bytes',
        'Map output bytes',
        'Map output materialized bytes',
        'Reduce input groups',
        'Reduce input records',
        'Reduce output records',
        'Reduce shuffle bytes',
        'Combine input records',
        'Combine output records',
    ];

    private $dbConn;
    private $container;

    public function __construct($container)
    {
        $this->container = $container;
    }

    public function init()
    {
        $this->dbConn = new \PDO($this->container['config']['db_conn_chain'], $this->container['config']['mysql_user'], $this->container['config']['mysql_pwd']);

        $this->dbConn->setAttribute(\PDO::ATTR_ERRMODE, \PDO::ERRMODE_EXCEPTION);
        $this->dbConn->setAttribute(\PDO::ATTR_EMULATE_PREPARES, false);
    }

    public function get_rows($sql, $params = array())
    {
        $md5_sql = md5($sql.http_build_query($params, '', ','));
        $file_path = "{$this->container['config']['db_cache_path']}/CACHE_$md5_sql.sql";

        if ($this->container['env'] == 'dev' || $_SERVER['HTTP_HOST'] == 'localhost' || (isset($_GET['NO_CACHE']) && strlen($_GET['NO_CACHE']) > 0)) {
            $use_cache = false;
        } else {
            $use_cache = true;
        }

        //check for cache first
        if ($use_cache &&
                file_exists($file_path) &&
                ($rows = file_get_contents($file_path)) &&
                ($rows = unserialize(gzuncompress($rows)))
        ) {
            $this->container['log']->addDebug('CACHED: '.$sql);
        } else {
            if (!$this->dbConn) $this->init();

            $this->container['log']->addDebug('NO CACHE: '.$sql);

            try {
                $sth = $this->dbConn->prepare($sql);
                $sth->execute($params);
            } catch (Exception $e) {
                throw new \Exception($e->getMessage(). " SQL: $sql");
            }

            $rows = $sth->fetchAll(\PDO::FETCH_ASSOC);

            //save cache
            if ($use_cache && $rows) {
                file_put_contents($file_path, gzcompress(serialize($rows), 9));
            }
        }

        return $rows;
    }

    public function get_execs($filter_execs = null)
    {
        if($filter_execs === null)
            $filter_execs = "AND exe_time > 200 AND (id_cluster = 1 OR (bench != 'bayes' AND id_cluster=2))";

        $query = "SELECT e.*, (exe_time/3600)*(cost_hour) cost  FROM execs e
        join clusters USING (id_cluster)
        WHERE 1 $filter_execs
        #AND id_exec IN (select distinct (id_exec) from JOB_status where id_exec is not null and host not like '%-1001');
        AND id_exec IN (select distinct (id_exec) from SAR_cpu where id_exec is not null and host not like '%-1001');
        ";

        return $this->get_rows($query);
    }

    public function get_exec_details($id_exec, $field, &$exec_rows, &$id_exec_rows)
    {
        if (is_numeric($id_exec) && $field) {
            if (!$exec_rows) $exec_rows = $this->get_execs();

            if (!$id_exec_rows) {
                $new_rows = array();
                foreach ($exec_rows as $row) {
                    foreach ($row as $key_row=>$field_value) {
                        if (is_numeric($field_value)) {
                            $new_rows[$row['id_exec']][$key_row] = round($field_value, 2);
                        } else {
                            $new_rows[$row['id_exec']][$key_row] = $field_value;
                        }
                    }
                }
                $exec_rows = $new_rows;
                $id_exec_rows = true;
            }

            if (isset($exec_rows[$id_exec][$field]))
                return $exec_rows[$id_exec][$field];
            else
                return false;
        } else {
            return false;
        }
    }

    public function get_hosts($clusters)
    {
        $query = 'SELECT * FROM hosts WHERE id_cluster IN ("'.join('","', $clusters).'");';

        return $this->get_rows($query);
    }

    public function get_task_metric_query($metric)
    {
        if ($metric === 'Duration') {
            return function($table) { return "TIMESTAMPDIFF(SECOND, $table.`START_TIME`, $table.`FINISH_TIME`)"; };
        } else {
            return function($table) use ($metric)  { return "$table.`$metric`"; };
        }
    }

    /**
     * Adds the clusters of the DBSCAN result to the database. Does NOT replace
     * existing ones.
     */
    public function add_dbscan($jobid, $clusters)
    {
        list($bench, $job_offset, $id_exec) = $this->get_jobid_info($jobid);

        // Check if clusters already exist for this jobid
        $query = "
            SELECT COUNT(*) as COUNT
            FROM `JOB_dbscan`
            WHERE
                `bench` = :bench AND
                `job_offset` = :job_offset AND
                `id_exec` = :id_exec
        ;";
        $query_params = array(":bench" => $bench, ":job_offset" => $job_offset, ":id_exec" => $id_exec);
        $sth = $this->dbConn->prepare($query);
        $sth->execute($query_params);
        if ($sth->fetchAll(\PDO::FETCH_ASSOC)[0]["COUNT"] > 0) {
            return;
        }

        // Insert new clusters
        $this->insert_dbscan($clusters);
    }

    /**
     * Retrieve info about jobid.
     *
     * Returns the bench, job_offset and id_exec of the specified jobid.
     *
     * The job_offset is defined as the last string after _
     * Example: job_201402172244_0002 -> 0002
     */
    public function get_jobid_info($jobid)
    {
        $query = "
            SELECT
                e.`bench`,
                e.`id_exec`
            FROM
                `JOB_details` d,
                `execs` e
            WHERE
                e.`id_exec` = d.`id_exec` AND
                d.`JOBID` = :jobid
        ;";
        $query_params = array(":jobid" => $jobid);

        $rows = $this->get_rows($query, $query_params);

        $bench = $rows[0]["bench"];
        $id_exec = $rows[0]["id_exec"];

        $job_offset = explode('_', $jobid);
        $job_offset = end($job_offset);

        return array($bench, $job_offset, $id_exec);
    }

    /**
     * Save DBSCAN to database.
     *
     * Warning: doesn't check for duplicates neither removes previous clusters.
     */
    private function insert_dbscan($clusters)
    {
        $columns = ["`bench`", "`job_offset`", "`id_exec`", "`centroid_x`", "`centroid_y`"];

        $query_params = [];
        foreach ($clusters as $cluster) {
            $query_params[] = $bench;
            $query_params[] = $job_offset;
            $query_params[] = $id_exec;
            $query_params[] = $cluster->getCentroid()->x;
            $query_params[] = $cluster->getCentroid()->y;
        }

        $query_values = implode(', ', array_fill(0, count($clusters), '('.str_pad('', (count($columns)*2)-1, '?,').')'));
        $query = "
            INSERT INTO
                `JOB_dbscan` (".implode(',', $columns).")
            VALUES
                $query_values
        ;";

        $sth = $this->dbConn->prepare($query);
        $sth->execute($query_params);
    }
}
