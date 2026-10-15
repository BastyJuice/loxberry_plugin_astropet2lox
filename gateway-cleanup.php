#!/usr/bin/env php
<?php
if(PHP_SAPI!=='cli'){http_response_code(404);exit;}
if(PHP_VERSION_ID<70400||PHP_INT_SIZE!==8){exit(1);}
ini_set('display_errors','0');
try{
    require_once 'loxberry_system.php';
    require_once __DIR__.'/lib/Runtime.php';
    AstroPet2Lox\Runtime::fromLoxBerry()->gateway()->removeOwned();
}catch(Throwable $error){fwrite(STDERR,"AstroPet2Lox: MQTT-Subscription bei Bedarf manuell prüfen.\n");exit(1);}
