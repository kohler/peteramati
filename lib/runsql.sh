#! /bin/sh
## runsql.sh -- HotCRP database shell
## Copyright (c) 2006-2020 Eddie Kohler; see LICENSE.

export LC_ALL=C LC_CTYPE=C LC_COLLATE=C CONFNAME=
if ! expr "$0" : '.*[/]' >/dev/null; then LIBDIR=./
else LIBDIR=`echo "$0" | sed 's,^\(.*/\)[^/]*$,\1,'`; fi
. ${LIBDIR}dbhelper.sh
export PROG=$0

usage () {
    if [ -z "$1" ]; then status=1; else status=$1; fi
    echo "Usage: $PROG [-n CONFNAME | -c CONFIGFILE] [MYSQL-OPTIONS]
       $PROG --show-password EMAIL
       $PROG --set-password EMAIL [PASSWORD]
       $PROG --create-user EMAIL [COLUMN=VALUE...]" |
       if [ $status = 0 ]; then cat; else cat 1>&2; fi
    exit $status
}

export FLAGS=
mode=
makeuser=
makeusersettings=
makeuserpassword=true
updateuser=false
dryrun=false
pwuser=
pwvalue=
cmdlinequery=
options_file=
while [ $# -gt 0 ]; do
    shift=1
    case "$1" in
    --show-password=*|--show-p=*|--show-pa=*|--show-pas=*|--show-pas=*|--show-pass=*|--show-passw=*|--show-passwo=*|--show-passwor=*)
        test -z "$mode" || usage
        pwuser="`echo "+$1" | sed 's/^[^=]*=//'`"; mode=showpw;;
    --show-password|--show-p|--show-pa|--show-pas|--show-pass|--show-passw|--show-passwo|--show-passwor)
        test "$#" -gt 1 -a -z "$mode" || usage
        pwuser="$2"; shift; mode=showpw;;
    --set-password|--set-p|--set-pa|--set-pas|--set-pass|--set-passw|--set-passwo|--set-passwor)
        test "$#" -gt 1 -a -z "$mode" || usage
        pwuser="$2"; pwvalue="$3"; shift; shift; mode=setpw;;
    --create-user)
        test "$#" -gt 1 -a -z "$mode" || usage
        makeuser="$2"; mode=makeuser; shift;;
    --update-user)
        test "$#" -gt 1 -a -z "$mode" || usage
        makeuser="$2"; mode=makeuser; updateuser=true; shift;;
    --show-opt=*|--show-option=*)
        test -z "$mode" || usage
        optname="`echo "+$1" | sed 's/^[^=]*=//'`"; mode=showopt;;
    --show-opt|--show-option)
        test "$#" -gt 1 -a -z "$mode" || usage
        optname="$2"; shift; mode=showopt;;
    --dry-run)
        dryrun=true;;
    --json-dbopt)
        test -z "$mode" || usage
        mode=json_dbopt;;
    --default-character-set=*|--default-character-set)
        parse_common_argument "$@";;
    -c|--co|--con|--conf|--confi|--config|-c*|--co=*|--con=*|--conf=*|--confi=*|--config=*)
        parse_common_argument "$@";;
    -n|--n|--na|--nam|--name|-n*|--n=*|--na=*|--nam=*|--name=*)
        parse_common_argument "$@";;
    --no-password-f|--no-password-fi|--no-password-fil|--no-password-file)
        parse_common_argument "$@";;
    --help) usage 0;;
    -*)
        if [ "$mode" = cmdlinequery ]; then
            cmdlinequery="$cmdlinequery $1"
        else
            FLAGS="$FLAGS $1"
        fi;;
    *)
        if [ "$mode" = makeuser ] && expr "$1" : "[a-zA-Z0-9_]*=" >/dev/null; then
            colname=`echo "$1" | sed 's/=.*//'`
            collen=`echo "$colname" | wc -c`
            collen=`expr $collen + 1`
            colvalue=`echo "$1" | tail -c +$collen`
            if test -n "$makeusersettings"; then makeusersettings="$makeusersettings, "; fi
            makeusersettings="$makeusersettings$colname='`echo "$colvalue" | sql_quote`'"
            test "$colname" = password && makeuserpassword=false
        elif [ "$mode" = "" ]; then
            mode=cmdlinequery
            cmdlinequery="$1"
        elif [ "$mode" = cmdlinequery ]; then
            cmdlinequery="$cmdlinequery $1"
        else usage; fi
    esac
    shift $shift
done

if ! findoptions >/dev/null; then
    echo "runsql.sh: No options file" 1>&2
    exit 1
fi

get_dboptions runsql.sh

if test "$mode" = json_dbopt; then
    eval "x0=$dbname;x1=$dbuser;x2=$dbpass;x3=$dbhost"
    echo_n '{"dbName":'; echo_n "$x0" | json_quote
    echo_n ',"dbUser":'; echo_n "$x1" | json_quote
    echo_n ',"dbPassword":'; echo_n "$x2" | json_quote
    echo_n ',"dbHost":'; if [ -z "$x3" ]; then echo_n 'null'; else echo_n "$x3" | json_quote; fi
    echo '}'
    exit
fi

check_mysqlish MYSQL mysql
set_myargs "$dbuser" "$dbpass"
exitval=0

if test -n "$pwuser"; then
    pwuser="`echo "+$pwuser" | sed -e 's,^.,,' | sql_quote`"
    if test "$mode" = showpw; then
        echo "select concat(email, ',', if(substr(password,1,1)=' ','<HASH>',coalesce(password,'<NULL>'))) from ContactInfo where email like '$pwuser' and disabled=0" | eval "$MYSQL $myargs -N $FLAGS $dbname"
    else
        showpwvalue=n
        if [ -z "$pwvalue" ]; then
            pwvalue=`generate_random_ints | generate_password 12`
            showpwvalue=y
        fi
        pwvalue="`echo "+$pwvalue" | sed -e 's,^.,,' | sql_quote`"
        query="update ContactInfo set password='$pwvalue', passwordTime=UNIX_TIMESTAMP(CURRENT_TIMESTAMP()) where email='$pwuser'; select row_count()"
        nupdates="`echo "$query" | eval "$MYSQL $myargs -N $FLAGS $dbname"`"
        if [ $nupdates = 0 ]; then
            echo "no such user" 1>&2; exitval=1
        elif [ $nupdates != 1 ]; then
            echo "$nupdates users updated" 1>&2
        fi
        if [ "$showpwvalue" = y -a $nupdates != 0 ]; then
            echo "Password: $pwvalue" 1>&2
        fi
    fi
elif test "$mode" = showopt; then
    if test -n "`echo "$optname" | tr -d A-Za-z0-9._:-`"; then
        echo "bad option name" 1>&2; exitval=1
    else
        opt="`getdbopt "$optname" 2>/dev/null`"
        optopt="`echo "select data from Settings where name='opt.$optname'" | eval "$MYSQL $myargs -N $FLAGS $dbname"`"
        if test -n "$optopt"; then eval "echo $optopt"; else eval "echo $opt"; fi
    fi
elif test "$mode" = makeuser; then
    if $dryrun; then command="cat"; else command="$MYSQL $myargs -N $FLAGS $dbname"; fi
    emailsetting="email='`echo "$makeuser" | sql_quote`'"
    if $updateuser; then
        echo "insert into ContactInfo set $emailsetting, password='' on duplicate key update roles=roles; update ContactInfo set $makeusersettings where $emailsetting" | eval "$command"
    else
        if test -n "$makeusersettings"; then makeusersettings=", $makeusersettings"; fi
        if $makeuserpassword; then makeusersettings="$makeusersettings, password=''"; fi
        echo "insert into ContactInfo set $emailsetting$makeusersettings" | eval "$command"
    fi
elif test "$mode" = cmdlinequery; then
    if test -n "$PASSWORDFILE"; then ( sleep 0.3; rm -f $PASSWORDFILE ) & fi
    echo "$cmdlinequery" | eval "$MYSQL $myargs $FLAGS $dbname"
else
    if test -n "$PASSWORDFILE"; then ( sleep 0.3; rm -f $PASSWORDFILE ) & fi
    eval "$MYSQL $myargs $FLAGS $dbname"
fi

test -n "$PASSWORDFILE" && rm -f $PASSWORDFILE
exit $exitval
