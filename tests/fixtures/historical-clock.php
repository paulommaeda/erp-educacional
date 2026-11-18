<?php
/** Build historical fixtures at their original date; current-date validation stays enabled in production. */
function atHistoricalDate(string $date,callable $seed):mixed
{
    $previous=$GLOBALS['ederp_fixture_time']??null;$GLOBALS['ederp_fixture_time']=strtotime($date.' 12:00:00 UTC');
    try{return $seed();}finally{if($previous===null)unset($GLOBALS['ederp_fixture_time']);else $GLOBALS['ederp_fixture_time']=$previous;}
}
