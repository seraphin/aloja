#!/bin/bash
# SCRIPT TO STOP, SET CONFIG, AND START HADOOP and run HiBench in AZURE

usage() {
  echo -e "Usage:
$0 -C clusterName
`#[-n net <IB|ETH>] `
[-d disk <SSD|HDD|RL{1,2,3}|R{1,2,3}>]
[-b benchmark <-min|-10>]
[-r replicaton <positive int>]
[-m max mappers and reducers <positive int>]
[-i io factor <positive int>] [-p port prefix <3|4|5>]
[-I io.file <positive int>]
[-l list of benchmarks <space separated string>]
[-c compression <0 (dissabled)|1|2|3>]
[-z <block size in bytes>]
[-s (save prepare)]
[-N (don't delete files)]
[-H hadoop version <hadoop1|hadoop2>]
[-t execution type (e.g: default, experimental)]
[-e extrae (instrument execution)]

example: $0 -C al-04 -n IB -d HDD -r 1 -m 12 -i 10 -p 3 -b _min -I 4096 -l wordcount -c 1
" 1>&2;

  exit 1;
}

OPTIND=1 #A POSIX variable, reset in case getopts has been used previously in the shell.

# Default values
[ ! "$VERBOSE" ] && VERBOSE=0
[ ! "$NET" ] && NET="ETH"
[ ! "$DISK" ] && DISK="HDD"
[ ! "$BENCH" ] && BENCH="HiBench"
[ ! "$REPLICATION" ] && REPLICATION=1
[ ! "$MAX_MAPS" ] && MAX_MAPS=8
[ ! "$IO_FACTOR" ] && IO_FACTOR=10
[ ! "$PORT_PREFIX" ] && PORT_PREFIX=3
[ ! "$IO_FILE" ] && IO_FILE=65536
[ ! "$LIST_BENCHS" ] && LIST_BENCHS="wordcount sort terasort kmeans pagerank bayes dfsioe" #nutchindexing hivebench
[ ! "$EXEC_TYPE" ] && EXEC_TYPE="default"
[ ! "$COMPRESS_GLOBAL" ] && COMPRESS_GLOBAL=0
[ ! "$COMPRESS_TYPE" ] && COMPRESS_TYPE=0
[ ! "$INSTRUMENTATION" ] && INSTRUMENTATION==0

#COMPRESS_GLOBAL=1
#COMPRESS_TYPE=1
#COMPRESS_CODEC_GLOBAL=org.apache.hadoop.io.compress.DefaultCodec
#COMPRESS_CODEC_GLOBAL=com.hadoop.compression.lzo.LzoCodec
#COMPRESS_CODEC_GLOBAL=org.apache.hadoop.io.compress.SnappyCodec
[ ! "$SAVE_BENCH" ] && SAVE_BENCH=""

[ ! "$SAVE_BENCH" ] && BLOCK_SIZE=67108864

[ ! "$SAVE_BENCH" ] && DELETE_HDFS=1

while getopts ":h:?:C:v:b:r:n:d:m:i:p:l:I:c:z:H:sN:D:t" opt; do
    case "$opt" in
    h|\?)
      usage
      ;;
    v)
      VERBOSE=1
      ;;
    C)
      clusterName=$OPTARG
      [ ! -z "$clusterName" ] || usage
      ;;
    n)
      NET=$OPTARG
      #[ "$NET" == "IB" ] || [ "$NET" == "ETH" ] || usage
      ;;
    d)
      DISK=$OPTARG
      defaultDisk=0
      #[ "$DISK" == "SSD" ] || [ "$DISK" == "HDD" ] || [ "$DISK" == "RR1" ] || [ "$DISK" == "RR2" ] || [ "$DISK" == "RR3" ] || [ "$DISK" == "RR4" ]  || [ "$DISK" == "RR5" ]  || [ "$DISK" == "RR6" ] || [ "$DISK" == "RL1" ] || [ "$DISK" == "RL2" ] || [ "$DISK" == "RL3" ] || [ "$DISK" == "RL4" ] || [ "$DISK" == "RL5" ]  || [ "$DISK" == "RL6" ] || [ "$DISK" == "HD1" ] || [ "$DISK" == "HD2" ] || [ "$DISK" == "HD3" ] || [ "$DISK" == "HD4" ] || [ "$DISK" == "HD5" ] || [ "$DISK" == "HD6" ] || [ "$DISK" == "HD7" ] || usage
      ;;
    b)
      BENCH=$OPTARG
      [ "$BENCH" == "HiBench" ] || [ "$BENCH" == "HiBench-10" ] || [ "$BENCH" == "HiBench-min" ] || [ "$BENCH" == "HiBench-1TB" ] || [ "$BENCH" == "HiBench3" ] || [ "$BENCH" == "HiBench3HDI" ] || [ "$BENCH" == "HiBench3-min" ] || [ "$BENCH" == "sleep" ] || [ "$BENCH" == "Big-Bench" ] || [ "$BENCH" == "TPCH" ] || usage
      ;;
    r)
      REPLICATION=$OPTARG
      ((REPLICATION > 0)) || usage
      ;;
    m)
      MAX_MAPS=$OPTARG
      ((MAX_MAPS > 0 && MAX_MAPS < 33)) || usage
      ;;
    i)
      IO_FACTOR=$OPTARG
      ((IO_FACTOR > 0)) || usage
      ;;
    I)
      IO_FILE=$OPTARG
      ((IO_FILE > 0)) || usage
      ;;
    p)
      PORT_PREFIX=$OPTARG
      ((PORT_PREFIX > 0 && PORT_PREFIX < 6)) || usage
      ;;
    c)
      if [ "$OPTARG" == "0" ] ; then
        COMPRESS_GLOBAL=0
        COMPRESS_TYPE=0
      elif [ "$OPTARG" == "1" ] ; then
        COMPRESS_GLOBAL=1
        COMPRESS_TYPE=1
        COMPRESS_CODEC_GLOBAL=org.apache.hadoop.io.compress.DefaultCodec
      elif [ "$OPTARG" == "2" ] ; then
        COMPRESS_GLOBAL=1
        COMPRESS_TYPE=2
        COMPRESS_CODEC_GLOBAL=com.hadoop.compression.lzo.LzoCodec
      elif [ "$OPTARG" == "3" ] ; then
        COMPRESS_GLOBAL=1
        COMPRESS_TYPE=3
        COMPRESS_CODEC_GLOBAL=org.apache.hadoop.io.compress.SnappyCodec
      fi
      ;;
    l)
      LIST_BENCHS=$OPTARG
      ;;
    z)
      BLOCK_SIZE=$OPTARG
      ;;
    s)
      SAVE_BENCH=1
      ;;
    t)
      EXEC_TYPE=$OPTARG
      ;;
    N)
      DELETE_HDFS=0
      ;;
    D)
      LIMIT_DATA_NODES=$OPTARG
      echo "LIMIT_DATA_NODES $LIMIT_DATA_NODES"
      ;;
    H)
      HADOOP_VERSION=$OPTARG
      [ "$HADOOP_VERSION" == "hadoop1" ] || [ "$HADOOP_VERSION" == "hadoop2" ] || usage
      ;;
    e)
        INSTRUMENTATION=1
      ;;
    esac
done

shift $((OPTIND-1))

[ "$1" = "--" ] && shift

[ -z "$clusterName" ] && usage

#####
#load cluster config and common functions
#clusterConfigFile="cluster_${clusterName}.conf"

CUR_DIR_TMP="$( cd "$( dirname "${BASH_SOURCE[0]}" )" && pwd )"
source "$CUR_DIR_TMP/../shell/common/include_benchmarks.sh"

loggerb  "INFO: includes loaded"

#####

#some validations
if ! inList "$CLUSTER_DISKS" "$DISK" ; then
  logger "ERROR: Disk type $DISK not supported for $clusterName\nSupported: $CLUSTER_DISKS"
  usage
fi

if ! inList "$CLUSTER_NETS" "$NET" ; then
  logger "ERROR: Disk type $NET not supported for $clusterName\nSupported: $NET"
  usage
fi

NUMBER_OF_DATA_NODES="$numberOfNodes"

DSH="dsh -M -c -m "

#For infiniband tests
if [ "${NET}" == "IB" ] ; then
  IFACE="ib0"
  master_name="$(get_master_name_IB)"
  node_names="$(get_node_names_IB)"
else
  IFACE="eth0"
  master_name="$(get_master_name)"
  node_names="$(get_node_names)"
fi

DSH_MASTER="ssh $master_name"


if [ ! -z "$LIMIT_DATA_NODES" ] ; then

  node_iteration=0
  for node in $node_names ; do
    if [ ! -z "$nodes_tmp" ] ; then
      node_tmp="$node_tmp\n$node"
    else
      node_tmp="$node"
    fi
    [[ $node_iteration -ge $LIMIT_DATA_NODES ]]  && break;
    node_iteration=$((node_iteration+1))
  done

  node_name=$(echo -e "$nodes_tmp")
  NUMBER_OF_DATA_NODES="$LIMIT_DATA_NODES"
fi

DSH="$DSH $(nl2char "$node_names" ",")"
DSH_C="$DSH -c " #concurrent

DSH_SLAVES="${DSH_C/"$master_name,"/}" #remove master name and trailling coma


[ -z "BENCH_HDD" ] || [ -z "BENCH_SOURCE_DIR" ] || [ -z "BENCH_HADOOP_VERSION" ] && {
  loggerb  "ERROR: Init variables not set"
  exit 1
}

#Check if values are set, if not use defaults

[ ! "$BENCH_SOURCE_DIR" ] && BENCH_SOURCE_DIR="$BENCH_DEFAULT_SCRATCH/aplic"
[ ! "$BENCH_SAVE_PREPARE_LOCATION" ] && BENCH_SAVE_PREPARE_LOCATION="$BENCH_DEFAULT_SCRATCH/HiBench_prepare"

[ ! "$JAVA_XMS" ] && JAVA_XMS="-Xms256m"
[ ! "$JAVA_XMX" ] && JAVA_XMX="-Xmx512m"
[ ! "$JAVA_AM_XMS" ] && JAVA_AM_XMS="-Xms256m"
[ ! "$JAVA_AM_XMX" ] && JAVA_AM_XMX="-Xmx512m"

[ ! "$HADOOP_VERSION" ] && HADOOP_VERSION="hadoop1"

if [ "$defaultProvider" == "hdinsight" ]; then
  HADOOP_VERSION="hadoop2"
fi

if [ ! "$BENCH_HADOOP_VERSION" ] ; then
  if [ "$HADOOP_VERSION" == "hadoop1" ]; then
    BENCH_HADOOP_VERSION="hadoop-1.0.3"
  elif [ "$HADOOP_VERSION" == "hadoop2" ] ; then
    BENCH_HADOOP_VERSION="hadoop-2.6.0"
  fi
fi

[ ! "$PHYS_MEM" ] && PHYS_MEM=$(echo "scale=4;($vmRAM*1024)-3072" | bc)
[ ! "$NUM_CORES" ] && NUM_CORES="$vmCores"
[ ! "$CONTAINER_MIN_MB" ] && CONTAINER_MIN_MB=768
[ ! "$CONTAINER_MAX_MB" ] && CONTAINER_MAX_MB=4096
[ ! "$MAPS_MB" ] && MAPS_MB=768
[ ! "$REDUCES_MB" ]  && REDUCES_MB=1536
[ ! "$AM_MB" ]  && AM_MB=1536

##FOR TPCH ONLY, default 1TB
[ ! "$TPCH_SCALE_FACTOR" ] && TPCH_SCALE_FACTOR=1000

# Use instrumented version of Hadoop
if [ "$INSTRUMENTATION" == "1" ] ; then
  BENCH_HADOOP_VERSION="${BENCH_HADOOP_VERSION}-instr"
fi


loggerb  "DEBUG: BENCH_BASE_DIR=$BENCH_BASE_DIR
BENCH_DEFAULT_SCRATCH=$BENCH_DEFAULT_SCRATCH
BENCH_SOURCE_DIR=$BENCH_SOURCE_DIR
BENCH_SAVE_PREPARE_LOCATION=$BENCH_SAVE_PREPARE_LOCATION
BENCH_HADOOP_VERSION=$BENCH_HADOOP_VERSION
JAVA_XMS=$JAVA_XMS JAVA_XMX=$JAVA_XMX
PHYS_MEM=$PHYS_MEM
NUM_CORES=$NUM_CORES
CONTAINER_MIN_MB=$CONTAINER_MIN_MB
CONTAINER_MAX_MB=$CONTAINER_MAX_MB
MAPS_MB=$MAPS_MB
AM_MB=$AM_MB
JAVA_AM_XMS=$JAVA_AM_XMS
JAVA_AM_XMX=$JAVA_AM_XMX
REDUCES_MB=$REDUCES_MB
Master node: $master_name "

#check that we got the dynamic disk location correctly
if [ -z "$(get_initial_disk "$DISK")" ] ; then
  loggerb "ERROR: cannot determine $DISK path"
  exit 1
fi

#set the main path for the benchmark
HDD="$(get_initial_disk "$DISK")/$(get_aloja_dir "$PORT_PREFIX")"
HDD_TMP="$(get_tmp_disk "$DISK")/$(get_aloja_dir "$PORT_PREFIX")" #for hadoop tmp dir

#BENCH_BASE_DIR="$homePrefixAloja/$userAloja/share"
#BENCH_SOURCE_DIR="/scratch/local/aplic"

#BENCH_HADOOP_VERSION="hadoop-1.0.3"

BENCH_H_DIR="$HDD/aplic/$BENCH_HADOOP_VERSION" #execution dir

if [[ "$BENCH" == HiBench* ]]; then
  EXECUTE_HIBENCH="true"
fi

BENCH_HIB_DIR="$BENCH_SOURCE_DIR/$BENCH"
if [[ "$BENCH" == HiBench* ]]; then
  BENCH_HIB_DIR="$BENCH_SOURCE_DIR/HiBench2"
fi
if [[ "$BENCH" == HiBench3* ]]; then
  BENCH_HIB_DIR="$BENCH_SOURCE_DIR/HiBench3"
fi

#make sure all spawned background jobs are killed when done (ssh ie ssh port forwarding)
#trap "kill 0" SIGINT SIGTERM EXIT
if [ ! -z "$EXECUTE_HIBENCH" ] || [ "$BENCH" == "TPCH" ]; then
  trap 'echo "RUNNING TRAP!"; stop_hadoop; stop_monit; stop_sniffer; [ $(jobs -p) ] && kill $(jobs -p); exit 1;' SIGINT SIGTERM EXIT
else
  trap 'echo "RUNNING TRAP!"; stop_monit; [ $(jobs -p) ] && kill $(jobs -p); exit 1;' SIGINT SIGTERM EXIT
fi


# Output directory name
CONF="${NET}_${DISK}_b${BENCH}_version-${HADOOP_VERSION}_D${NUMBER_OF_DATA_NODES}_${clusterName}"
JOB_NAME="$(get_date_folder)_$CONF"

JOB_PATH="$BENCH_BASE_DIR/jobs_$clusterName/$JOB_NAME"
LOG_PATH="$JOB_PATH/log_${JOB_NAME}.log"
LOG="2>&1 |tee -a $LOG_PATH"

#create dir to save files in one host
$DSH_MASTER "mkdir -p $JOB_PATH"
$DSH_MASTER "touch $LOG_PATH"


#export HADOOP_HOME="$HADOOP_DIR"
[ ! $JAVA_HOME ] && export JAVA_HOME="$BENCH_SOURCE_DIR/jdk1.7.0_25"

loggerb "DEBUG: JAVA_HOME=$JAVA_HOME"

if [ "$defaultProvider" != "hdinsight" ]; then
	bwm_source="$BENCH_SOURCE_DIR/bin/bwm-ng"
	vmstat="$HDD/aplic/vmstat_$PORT_PREFIX"
	bwm="$HDD/aplic/bwm-ng_$PORT_PREFIX"
	sar="$HDD/aplic/sar_$PORT_PREFIX"
else
	bwm_source="bwm-ng"
	vmstat="vmstat"
	bwm="bwm-ng"
	sar="sar"
fi

echo "$(date '+%s') : STARTING EXECUTION of $JOB_NAME"


if [ "$defaultProvider" != "hdinsight" ] && [ ! -z "$EXECUTE_HIBENCH" ]; then
  #temporary OS config
  if [ -z "$noSudo" ] ; then
    $DSH "sudo sysctl -w vm.swappiness=0 > /dev/null;
sudo sysctl vm.panic_on_oom=1 > /dev/null;
sudo sysctl -w fs.file-max=65536 > /dev/null;
sudo service ufw stop 2>&1 > /dev/null;
"
  fi
fi


#only copy files if version has changed (to save time in azure)
loggerb  "Checking if to generate source dirs $BENCH_BASE_DIR/aplic/aplic_version == $BENCH_SOURCE_DIR/aplic_version"
for node in $node_names ; do
  loggerb  " for host $node"
  if [ "$(ssh "$node" "[ "\$\(cat $BENCH_BASE_DIR/aplic/aplic_version\)" == "\$\(cat $BENCH_SOURCE_DIR/aplic_version 2\> /dev/null \)" ] && echo 'OK' || echo 'KO'" )" != "OK" ] ; then
    loggerb  "At least host $node did not have source dirs. Generating source dirs for ALL hosts"

    if [ ! "$(ssh "$node" "[ -d \"$BENCH_BASE_DIR/aplic\" ] && echo 'OK' || echo 'KO'" )" != "OK" ] ; then
      #logger "Downloading initial aplic dir from dropbox"
      #$DSH "wget -nv https://www.dropbox.com/s/ywxqsfs784sk3e4/aplic.tar.bz2?dl=1 -O $BASE_DIR/aplic.tar.bz2"

      $DSH "rsync -aur --force $BENCH_BASE_DIR/aplic.tar.bz2 /tmp/"

      loggerb  "Uncompressing aplic"
      $DSH  "mkdir -p $BENCH_SOURCE_DIR/; cd $BENCH_SOURCE_DIR/../; tar -C $BENCH_SOURCE_DIR/../ -jxf /tmp/aplic.tar.bz2; "  #rm aplic.tar.bz2;
    fi

    logger "Rsynching files"
    $DSH "mkdir -p $BENCH_SOURCE_DIR; rsync -aur --force $BENCH_BASE_DIR/aplic/* $BENCH_SOURCE_DIR/"
    break #dont need to check after one is missing
  else
    loggerb  " Host $node up to date"
  fi
done

#if [ "$(cat $BENCH_BASE_DIR/aplic/aplic_version)" != "$(cat $BENCH_SOURCE_DIR/aplic_version)" ] ; then
#  loggerb  "Generating source dirs"
#  $DSH "mkdir -p $BENCH_SOURCE_DIR; cp -ru $BENCH_BASE_DIR/aplic/* $BENCH_SOURCE_DIR/"
#  #$DSH "cp -ru $BENCH_SOURCE_DIR/${BENCH_HADOOP_VERSION}-home $BENCH_SOURCE_DIR/${BENCH_HADOOP_VERSION}" #rm -rf $BENCH_SOURCE_DIR/${BENCH_HADOOP_VERSION};
#elsefi
#  loggerb  "Source dirs up to date"
#fi


loggerb  "Job name: $JOB_NAME"
loggerb  "Job path: $JOB_PATH"
loggerb  "Log path: $LOG_PATH"
loggerb  "Disk location: $HDD"
loggerb  "TMP Disk location: $HDD_TMP"
loggerb  "Conf: $CONF"
loggerb  "Benchmark: $BENCH_HIB_DIR"
loggerb  "Benchs to execute: $LIST_BENCHS"
loggerb  "DSH: $DSH"
loggerb  ""

##For zabbix monitoring make sure IB ports are available
#ssh_tunnel="ssh -N -L al-1001:30070:al-1001-ib0:30070 -L al-1001:30030:al-1001-ib0:30030 al-1001"
##first make sure we kill any previous, even if we don't need it
#pkill -f "ssh -N -L"
##"$ssh_tunnel"
#
#if [ "${NET}" == "IB" ] ; then
#  $ssh_tunnel 2>&1 |tee -a $LOG_PATH &
#fi

#stop running instances with the previous conf
#$DSH_MASTER $BENCH_H_DIR/bin/stop-all.sh 2>&1 >> $LOG_PATH

#prepare selected conf
#$DSH "rm -rf $DIR/conf/*" 2>&1 |tee -a $LOG_PATH
#$DSH "cp -r $DIR/$CONF/* $DIR/conf/" 2>&1 |tee -a $LOG_PATH


if [ "$defaultProvider" != "hdinsight" ]; then
 prepare_config
fi

benchmark_config

start_time=$(date '+%s')

########################################################
loggerb  "Starting execution of $BENCH"

##PREPARED="/scratch/local/ssd/pristine/prepared"
#"wordcount" "sort" "terasort" "kmeans" "pagerank" "bayes" "nutchindexing" "hivebench" "dfsioe"
#  "nutchindexing"

benchmark_run

stop_monit

benchmark_teardown

benchmark_save


loggerb  "$(date +"%H:%M:%S") DONE $bench"


########################################################
end_time=$(date '+%s')

benchmark_cleanup


#copy
loggerb "INFO: Copying resulting files From: $HDD/* To: $JOB_PATH/"
$DSH "cp $HDD/* $HDD_TMP/* $JOB_PATH/"

# Save current config (all environment variables)
( set -o posix ; set ) | grep -i -v "password" > $JOB_PATH/config.sh


# Execute post-process of traces
if [ "$INSTRUMENTATION" == "1" ] ; then
  instrumentation_post_process
fi


#report
#finish_date=`$DATE`
#total_time=`expr $(date '+%s') - $(date '+%s')`
#$(touch ${JOB_PATH}/finish_${finish_date})
#$(touch ${JOB_PATH}/total_${total_time})
du -h $JOB_PATH|tail -n 1
loggerb  "DONE, total time $total_time seconds. Path $JOB_PATH"
