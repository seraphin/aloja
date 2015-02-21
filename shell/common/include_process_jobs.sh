BASE_DIR=$(pwd)

source "$CUR_DIR/common/common.sh"

#set the path or use the default
if [ "$1" ] ; then
  IMPORT_DIR="$1"
else
  IMPORT_DIR="$CUR_DIR/../import-jobs"
fi

DONE_DIR="/scratch/attached/1/DONE"
FAIL_DIR="$IMPORT_DIR/FAIL"

mkdir -p "$IMPORT_DIR" "$DONE_DIR" "$FAIL_DIR"

SHARE_DIR="/home/pristine/share"

logger "DEBUG: IMPORT_DIR=$IMPORT_DIR"
