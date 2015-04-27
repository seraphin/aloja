<?php

namespace alojaweb\Controller;

use alojaweb\inc\HighCharts;
use alojaweb\inc\Utils;
use alojaweb\inc\DBUtils;
use alojaweb\inc\MLUtils;

class MLFindAttributesController extends AbstractController
{
	public function mlfindattributesAction()
	{
		$instance = $message = $tree_descriptor = '';
		$must_wait = 'NO';
		try
		{
			$dbml = new \PDO($this->container->get('config')['db_conn_chain_ml'], $this->container->get('config')['mysql_user'], $this->container->get('config')['mysql_pwd']);
		        $dbml->setAttribute(\PDO::ATTR_ERRMODE, \PDO::ERRMODE_EXCEPTION);
		        $dbml->setAttribute(\PDO::ATTR_EMULATE_PREPARES, false);

		    	$db = $this->container->getDBUtils();
		    	
		    	$configurations = array ();	// Useless here
		    	$where_configs = '';
		    	$concat_config = "";		// Useless here
		    	
			$params = array();
			$param_names = array('benchs','nets','disks','mapss','iosfs','replications','iofilebufs','comps','blk_sizes','id_clusters'); // Order is important
			foreach ($param_names as $p) { $params[$p] = Utils::read_params($p,$where_configs,$configurations,$concat_config); sort($params[$p]); }

			$unseen = (array_key_exists('unseen',$_GET) && $_GET['unseen'] == 1);

			if (count($_GET) <= 1
			|| (count($_GET) == 2 && array_key_exists("current_model",$_GET))
			|| (count($_GET) == 2 && array_key_exists("dump",$_GET))
			|| (count($_GET) == 2 && array_key_exists("tree",$_GET))
			|| (count($_GET) == 2 && array_key_exists("pass",$_GET))
			|| (count($_GET) == 3 && array_key_exists("dump",$_GET) && array_key_exists("current_model",$_GET))
			|| (count($_GET) == 3 && array_key_exists("tree",$_GET) && array_key_exists("current_model",$_GET))
			|| (count($_GET) == 3 && array_key_exists("tree",$_GET) && array_key_exists("current_model",$_GET))
			|| (count($_GET) == 3 && array_key_exists("pass",$_GET) && array_key_exists("current_model",$_GET)))
			{
				$where_configs = '';
				$params['benchs'] = array('terasort'); $where_configs .= ' AND bench IN ("terasort")';
				$params['disks'] = array('HDD','SSD'); $where_configs .= ' AND disk IN ("HDD","SSD")';
				$params['iofilebufs'] = array('65536','131072'); $where_configs .= ' AND iofilebuf IN ("65536","131072")';
				$params['comps'] = array('0'); $where_configs .= ' AND comp IN ("0")';
				$params['replications'] = array('1'); $where_configs .= ' AND replication IN ("1")';
				$params['id_clusters'] = array('1'); $where_configs .= ' AND id_cluster IN ("1")';
				$params['mapss'] = array('4'); $where_configs .= ' AND maps IN ("4")';
				$params['iosfs'] = array('10'); $where_configs .= ' AND iosf IN ("10")';
				$params['blk_sizes'] = array('128'); $where_configs .= ' AND blk_size IN ("128")';
				$unseen = FALSE;
			}

			$jsonData = $jsonHeader = "[]";
			$mae = $rae = 0;

			// compose instance
			$model_info = MLUtils::generateModelInfo($param_names, $params, $unseen, $db);
			$instance = MLUtils::generateSimpleInstance($param_names, $params, $unseen, $db);			
			$instances = MLUtils::generateInstances($param_names, $params, $unseen, $db);

			// Model for filling
			MLUtils::findMatchingModels($model_info, $possible_models, $possible_models_id, $dbml);

			$current_model = "";
			if (array_key_exists('current_model',$_GET)) $current_model = $_GET['current_model'];

			if (!empty($possible_models_id))
			{
				if ($current_model == "")
				{
					$query = "SELECT AVG(ABS(exe_time - pred_time)) AS MAE, AVG(ABS(exe_time - pred_time)/exe_time) AS RAE, p.id_learner FROM predictions p, learners l WHERE l.id_learner = p.id_learner AND p.id_learner IN ('".implode("','",$possible_models_id)."') AND predict_code > 0 ORDER BY MAE LIMIT 1";
					$result = $dbml->query($query);
					$row = $result->fetch();	
					$current_model = $row['id_learner'];
				}
				$config = $instance.'-'.$current_model;

				$is_cached_mysql = $dbml->query("SELECT count(*) as total FROM trees WHERE id_findattrs = '".md5($config)."'");
				$tmp_result = $is_cached_mysql->fetch();
				$is_cached = ($tmp_result['total'] > 0);

				$tmp_file = md5($config).'.tmp';

				$in_process = file_exists(getcwd().'/cache/query/'.md5($config).'.lock');
				$finished_process = $in_process && ((int)shell_exec('ls '.getcwd().'/cache/query/'.md5($config).'-*.lock | wc -w ') == count($instances));

				if (!$in_process && !$finished_process && !$is_cached)
				{
					exec('cd '.getcwd().'/cache/query ; touch '.md5($config).'.lock ; rm -f '.$tmp_file);
					$count = 1;
					foreach ($instances as $inst)
					{
						exec(getcwd().'/resources/queue -d -c "cd '.getcwd().'/cache/query ; ../../resources/aloja_cli.r -m aloja_predict_instance -l '.$current_model.' -p inst_predict=\''.$inst.'\' -v | grep -v \'Prediction\' >>'.$tmp_file.' 2>/dev/null; touch '.md5($config).'-'.($count++).'.lock" >/dev/null 2>&1 &');
					}
				}
				$finished_process = ((int)shell_exec('ls '.getcwd().'/cache/query/'.md5($config).'-*.lock | wc -w ') == count($instances));

				if ($finished_process && !$is_cached)
				{
					// Read results and dump to DB
					$i = 0;
					$token = 0;
					$token_i = 0;
					$query = "INSERT IGNORE INTO predictions (id_exec,exe_time,bench,net,disk,maps,iosf,replication,iofilebuf,comp,blk_size,id_cluster,name,datanodes,headnodes,vm_OS,vm_cores,vm_RAM,provider,vm_size,type,pred_time,id_learner,instance,predict_code) VALUES ";
					if (($handle = fopen(getcwd().'/cache/query/'.$tmp_file, "r")) !== FALSE)
					{
						while (($line = fgets($handle, 1000)) !== FALSE && $i < 1000) // FIXME - Mysql install current limitation
						{
							if ($line=='') break;

							// Fetch Real Value
							$inst_aux = preg_split("/\s+/", $line);
							$query_var = "SELECT AVG(exe_time) as AVG, outlier FROM predictions WHERE instance = '".$inst_aux[1]."' AND predict_code > 0";
							$result = $dbml->query($query_var);
							$row = $result->fetch();

							$realexecval = (is_null($row['AVG']) || $row['outlier'] == 2)?0:$row['AVG'];
/*
							$query_var = "SELECT count(*) as num FROM predictions WHERE instance = '".$inst_aux[1]."' AND id_learner = '".$current_model."'";
							$result = $dbml->query($query_var);
							$row = $result->fetch();

							// Insert instance values
							if ($row['num'] == 0)
							{
*/
								$token_i = 1;
								$selected_instance = preg_replace('/,Cmp(\d+),/',',${1},',$inst_aux[1]);
								$selected_instance = preg_replace('/,Cl(\d+),/',',${1},',$selected_instance);
								if ($token > 0) { $query = $query.","; } $token = 1;
								$query = $query."('0','0','".str_replace(",","','",$selected_instance)."','".$inst_aux[2]."','".$current_model."','".$inst_aux[1]."','0') ";								
//							}

							$i++;

							if ($i % 100 == 0 && $token_i > 0)
							{
								if ($dbml->query($query) === FALSE) throw new \Exception('Error when saving into DB');
								$query = "INSERT IGNORE INTO predictions (id_exec,exe_time,bench,net,disk,maps,iosf,replication,iofilebuf,comp,blk_size,id_cluster,name,datanodes,headnodes,vm_OS,vm_cores,vm_RAM,provider,vm_size,type,pred_time,id_learner,instance,predict_code) VALUES ";
								$token = 0;
								$token_i = 0;
							}
						}
						if ($token_i > 0)
						{
							if ($dbml->query($query) === FALSE) throw new \Exception('Error when saving into DB');
						}

						// Descriptive Tree
						$tree_descriptor = shell_exec(getcwd().'/resources/aloja_cli.r -m aloja_representative_tree -p method=ordered:dump_file="'.getcwd().'/cache/query/'.$tmp_file.'":output="html" -v 2> /dev/null');
						$tree_descriptor = substr($tree_descriptor, 5, -2);
						$query = "INSERT INTO trees (id_findattrs,id_learner,instance,model,tree_code) VALUES ('".md5($config)."','".$current_model."','".$instance."','".$model_info."','".$tree_descriptor."')";

						if ($dbml->query($query) === FALSE) throw new \Exception('Error when saving tree into DB');

						// remove remaining locks
						shell_exec('rm -f '.getcwd().'/cache/query/'.md5($config).'*.lock'); 

						// Remove temporal files
						$output = shell_exec('rm -f '.getcwd().'/cache/query/'.md5($config).'.tmp');

						$is_cached = true;
					}
					fclose($handle);
				}

				if (!$is_cached)
				{
					$jsonData = $jsonHeader = $jsonColumns = $jsonColor = '[]';
					$must_wait = 'YES';
					if (isset($_GET['dump'])) { $dbml = null; echo "1"; exit(0); }
					if (isset($_GET['pass'])) { $dbml = null; return "1"; }
				}
				else
				{
					if (isset($_GET['pass']) && $_GET['pass'] == 2) { $dbml = null; return "2"; }

					// Fetch results and compose JSON
					$header = array('Benchmark','Net','Disk','Maps','IO.SFS','Rep','IO.FBuf','Comp','Blk.Size','Cluster','Cl.Name','Datanodes','Headnodes','VM.OS','VM.Cores','VM.RAM','Provider','VM.Size','Type','Prediction','Observed');
					$jsonHeader = '[{title:""}';
					foreach ($header as $title) $jsonHeader = $jsonHeader.',{title:"'.$title.'"}';
					$jsonHeader = $jsonHeader.']';

					$query = "SELECT @i:=@i+1 as num, instance, AVG(pred_time) as pred_time, AVG(exe_time) as exe_time FROM predictions, (SELECT @i:=0) d WHERE id_learner='".$current_model."' ".$where_configs." GROUP BY instance";
					$result = $dbml->query($query);
					$jsonData = '[';
					foreach ($result as $row)
					{
						if ($jsonData!='[') $jsonData = $jsonData.',';
						$jsonData = $jsonData."['".$row['num']."','".str_replace(",","','",$row['instance'])."','".$row['pred_time']."','".$row['exe_time']."']";
					}
					$jsonData = $jsonData.']';

					foreach (range(1,33) as $value) $jsonData = str_replace('Cmp'.$value,Utils::getCompressionName($value),$jsonData);

					// Fetch MAE & RAE values
					$query = "SELECT AVG(ABS(exe_time - pred_time)) AS MAE, AVG(ABS(exe_time - pred_time)/exe_time) AS RAE FROM predictions WHERE id_learner='".md5($config)."' AND predict_code > 0";
					$result = $dbml->query($query);
					$row = $result->fetch();
					$mae = $row['MAE'];
					$rae = $row['RAE'];

					// Dump case
					if (isset($_GET['dump']))
					{
						echo "ID".str_replace(array("[","]","{title:\"","\"}"),array('','',''),$jsonHeader)."\n";
						echo str_replace(array('],[','[[',']]'),array("\n",'',''),$jsonData);

						$dbml = null;
						exit(0);
					}
					if (isset($_GET['pass']) && $_GET['pass'] == 1)
					{
						$retval = "ID".str_replace(array("[","]","{title:\"","\"}"),array('','',''),$jsonHeader)."\n";
						$retval .= str_replace(array('],[','[[',']]'),array("\n",'',''),$jsonData);

						$dbml = null;
						return $retval;
					}

					// Display Descriptive Tree
					$query = "SELECT tree_code FROM trees WHERE id_findattrs = '".md5($config)."'";
					$result = $dbml->query($query);
					$row = $result->fetch();
					$tree_descriptor = $row['tree_code'];
				}			
			}
			else
			{
				$message = "There are no prediction models trained for such parameters. Train at least one model in 'ML Prediction' section.".$instance;
				if (isset($_GET['dump'])) { echo "-1"; exit(0); }
				if (isset($_GET['pass'])) { return "-1"; }
			}
			$dbml = null;
		}
		catch(\Exception $e)
		{
			$this->container->getTwig ()->addGlobal ( 'message', $e->getMessage () . "\n" );

			$jsonData = $jsonHeader = "[]";
			$instance = $instances = $possible_models_id = "";
			$possible_models = $possible_models_id = array();
			$must_wait = 'NO';
			$mae = $rae = 0;

			$dbml = null;
			if (isset($_GET['pass'])) { return "-2"; }
		}
		echo $this->container->getTwig()->render('mltemplate/mlfindattributes.html.twig',
			array(
				'selected' => 'mlfindattributes',
				'instance' => $instance,
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
				'jsonData' => $jsonData,
				'jsonHeader' => $jsonHeader,
				'models' => '<li>'.implode('</li><li>',$possible_models).'</li>',
				'models_id' => '[\''.implode("','",$possible_models_id).'\']',
				'current_model' => $current_model,
				'message' => $message,
				'mae' => $mae,
				'rae' => $rae,
				'must_wait' => $must_wait,
				'tree' => (isset($_GET['tree'])?"true":"false"),
				'tree_descriptor' => $tree_descriptor,
				'options' => Utils::getFilterOptions($db)
			)
		);
	}

	public function mlattributestreeAction()
	{
		$_GET['tree'] = 1;
		$this->mlfindattributesAction();
	}
}
?>