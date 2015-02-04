<?php

namespace alojaweb\Controller;

use alojaweb\inc\HighCharts;
use alojaweb\inc\Utils;
use alojaweb\inc\DBUtils;

class MLSummariesController extends AbstractController
{
	public function mlsummariesAction()
	{
		$displaydata = $message = '';
		try
		{
			$db = $this->container->getDBUtils();
		    	
		    	$configurations = array ();	// Useless here
		    	$where_configs = '';
		    	$concat_config = "";		// Useless here
		    	
			$params = array();
			$param_names = array('benchs','nets','disks','mapss','iosfs','replications','iofilebufs','comps','blk_sizes','id_clusters'); // Order is important
			foreach ($param_names as $p) { $params[$p] = Utils::read_params($p,$where_configs,$configurations,$concat_config); sort($params[$p]); }

			$separate_feat = 'joined';
			if (array_key_exists('feature',$_GET)) $separate_feat = $_GET['feature'];

			if (count($_GET) <= 1)
			{
				$params['benchs'] = array('sort','terasort','wordcount');
				$params['disks'] = array('HDD','SSD');
				$where_configs = ' AND bench IN ("sort","terasort","wordcount") AND disk IN ("HDD","SSD")';
			}

			$model_info = '';
			foreach ($param_names as $p) $model_info = $model_info.((empty($params[$p]))?' '.substr($p,0,-1).' ("*")':' '.substr($p,0,-1).' ("'.implode('","',$params[$p]).'")');

			$cache_ds = getcwd().'/cache/query/'.md5($model_info.' '.$separate_feat.' SUMMARY').'-cache.csv';

			if (file_exists($cache_ds))
			{
				$keep_cache = TRUE;
				foreach (array("summary.data") as &$value)
				{
					$keep_cache = $keep_cache && file_exists(getcwd().'/cache/query/'.md5($model_info.' '.$separate_feat.' SUMMARY').'-'.$value);
				}
				if (!$keep_cache)
				{
					unlink($cache_ds);
					shell_exec("sed -i '/".md5($model_info.' '.$separate_feat.' SUMMARY')." : ".$model_info.' '.$separate_feat." SUMMARY/d' ".getcwd()."/cache/query/record.data");
				}
			}

			if (!file_exists($cache_ds))
			{
				// get headers for csv
				$header_names = array(
					'id_exec' => 'ID','bench' => 'Benchmark','exe_time' => 'Exe Time','exec' => 'Exec Conf','cost' => 'Running Cost $','net' => 'Net',
					'disk' => 'Disk','maps' => 'Maps','iosf' => 'IO SFac','replication' => 'Rep','iofilebuf' => 'IO FBuf','comp' => 'Comp',
					'blk_size' => 'Blk size','id_cluster' => 'Cluster','histogram' => 'Histogram','prv' => 'PARAVER','end_time' => 'End time',
				);

			    	$query="SHOW COLUMNS FROM execs;";
			    	$rows = $db->get_rows ($query);
				if (empty($rows)) throw new \Exception('No data matches with your critteria.');
				$headers = array();
				$names = array();
				$count = 0;
				foreach($rows as $row)
				{
					if (array_key_exists($row['Field'],$header_names))
					{
						$headers[$count] = $row['Field'];
						$names[$count++] = $header_names[$row['Field']];
					}
				}
				$headers[$count] = 0;	// FIXME - Costs are NOT in the database?! What sort of anarchy is this?!
				$names[$count++] = $header_names['cost'];

				// dump the result to csv
			    	$query="SELECT ".implode(",",$headers)." FROM execs WHERE valid = TRUE ".$where_configs.";";
			    	$rows = $db->get_rows ( $query );

				if (empty($rows)) throw new \Exception('No data matches with your critteria.');

				$fp = fopen($cache_ds, 'w');
				fputcsv($fp, $names,',','"');
			    	foreach($rows as $row)
				{
					$row['id_cluster'] = "Cl".$row['id_cluster'];	// Cluster is numerically codified...
					$row['comp'] = "Cmp".$row['comp'];		// Compression is numerically codified...
					fputcsv($fp, array_values($row),',','"');
				}

				// launch query
				$command = 'cd '.getcwd().'/cache/query; '.getcwd().'/resources/aloja_cli.r -m aloja_print_summaries -d '.$cache_ds.' -p '.(($separate_feat!='joined')?'sname='.$separate_feat.':':'').'fprint='.md5($model_info.' '.$separate_feat.' SUMMARY').':fwidth=135';
				$output = shell_exec($command);

				// update cache record (for human reading)
				$register = md5($model_info.' '.$separate_feat.' SUMMARY').' : '.$model_info.' '.$separate_feat." SUMMARY\n";
				shell_exec("sed -i '/".$register."/d' ".getcwd()."/cache/query/record.data");
				file_put_contents(getcwd().'/cache/query/record.data', $register, FILE_APPEND | LOCK_EX);
			}

			// read results of the CSV
			if (($handle = fopen(getcwd().'/cache/query/'.md5($model_info.' '.$separate_feat.' SUMMARY').'-summary.data', 'r')) !== FALSE)
			{
				$displaydata = "";
				while (($data = fgets($handle)) !== FALSE)
				{
					$displaydata = $displaydata.str_replace(' ','&nbsp;',$data)."<br />";
				}
				fclose($handle);
			}
		}
		catch(\Exception $e)
		{
			$this->container->getTwig ()->addGlobal ( 'message', $e->getMessage () . "\n" );
			$displaydata = $separate_feat = '';
		}
		echo $this->container->getTwig()->render('mltemplate/mlsummaries.html.twig',
			array(
				'selected' => 'mlsummaries',
				'displaydata' => $displaydata,
				'benchs' => $params['benchs'],
				'nets' => $params['nets'],
				'disks' => $params['disks'],
				'blk_sizes' => $params['blk_sizes'],
				'comps' => $params['comps'],
				'id_clusters' => $params['id_clusters'],
				'mapss' => $params['mapss'],
				'replications' => $params['replications'],
				'iosfs' => $params['iosfs'],
				'iofilebufs' => $params['iofilebufs'],
				'feature' => $separate_feat,
				'message' => $message,
				'options' => Utils::getFilterOptions($db)
			)
		);	
	}
}
?>
