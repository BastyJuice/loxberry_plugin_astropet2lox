#!/usr/bin/env php
<?php
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
ini_set('display_errors','0');
if (PHP_VERSION_ID < 70400 || PHP_INT_SIZE !== 8) { fwrite(STDERR,"AstroPet2Lox benötigt PHP ab 7.4 mit 64 Bit.\n"); exit(1); }
require_once 'loxberry_system.php';
require_once __DIR__.'/lib/Runtime.php';
require_once __DIR__.'/lib/MqttBridge.php';
require_once __DIR__.'/lib/MqttClient.php';
require_once __DIR__.'/lib/Publisher.php';

use AstroPet2Lox\Runtime;
use AstroPet2Lox\MqttBridge;
use AstroPet2Lox\MqttClient;
use AstroPet2Lox\Publisher;
use AstroPet2Lox\TopicCatalog;
use AstroPet\Support;
use AstroPet\LoxoneOutput;

$stop=false;$publisher=null;$client=null;$lock=null;$exitCode=0;$heartbeatCounter=0;$log=null;$loggedStatus=null;
if(function_exists('pcntl_async_signals')){
    pcntl_async_signals(true);
    pcntl_signal(SIGTERM,static function()use(&$stop){$stop=true;});
    pcntl_signal(SIGINT,static function()use(&$stop){$stop=true;});
}
$daemon=in_array('--daemon',$argv,true);
try {
    $runtime=Runtime::fromLoxBerry();
    if(stream_resolve_include_path('loxberry_log.php')!==false){
        try{require_once 'loxberry_log.php';$log=LBLog::newLog(['name'=>'worker','addtime'=>1]);$log->LOGSTART('AstroPet2Lox Hintergrunddienst');}catch(Throwable $ignored){$log=null;}
    }
    $lock=@fopen($runtime->dataDir.'/worker.lock.php','c+b');
    if($lock===false){throw new RuntimeException('Sperre nicht verfügbar.');}
    @chmod($runtime->dataDir.'/worker.lock.php',0600);
    if(!flock($lock,LOCK_EX|LOCK_NB)){fclose($lock);exit(0);}
    if(fstat($lock)['size']===0){fwrite($lock,"<?php http_response_code(404); exit;\n");fflush($lock);}
    $lastPoll=0;$lastHeartbeat=0;$lastRequest=null;$brokerHash=null;$lastAttempt=0;$lastProjection=[];
    do {
        if(is_file($runtime->dataDir.'/stop-worker.php')){break;}
        $now=time();$ok=false;$error=null;$phase='waiting';$stage='config';
        try {
            $settings=$runtime->settings();
            $stage='mqtt';
            $request=Support::readJson($runtime->dataDir.'/publish-request.php')['nonce']??null;
            $due=$now-$lastPoll>=$settings['poll_interval_seconds'];
            $requested=is_string($request)&&$request!==$lastRequest;
            if($client===null&&$now-$lastAttempt>=10){
                $lastAttempt=$now;
                $details=MqttBridge::details();
                $brokerHash=hash('sha256',json_encode($details,JSON_THROW_ON_ERROR));
                $client=new MqttClient($details,'astropet2lox-'.substr(hash('sha256',$runtime->dataDir),0,20));
                $client->connect('astropet2lox/availability');
                $publisher=new Publisher($client,$runtime->dataDir);
                // Disarm any retained validity from an earlier, interrupted batch.
                $client->publish('astropet2lox/availability','0');
                $client->publish('astropet2lox/data_valid','0');
                $lastPoll=0;$lastHeartbeat=0;
            }
            if($client!==null&&$publisher!==null){
                if($due||$requested||$lastPoll===0){
                    $newDetails=MqttBridge::details();
                    if(hash('sha256',json_encode($newDetails,JSON_THROW_ON_ERROR))!==$brokerHash){
                        $publisher->stop();$publisher=null;$client=null;$lastAttempt=0;continue;
                    }
                    $stage='device';
                    $config=$runtime->config();$bridge=$runtime->bridge();
                    $snapshot=$settings['enabled']?$bridge->refresh():$bridge->snapshot();
                    $projection=LoxoneOutput::project($snapshot,time(),$config['max_age_seconds']);
                    if(!$settings['enabled']){$projection['data_valid']=0;}
                    $projection=TopicCatalog::projection($projection,(int)$settings['enabled'],time());
                    $heartbeatCounter=($heartbeatCounter+1)%65536;$projection['heartbeat_counter']=$heartbeatCounter;
                    $stage='mqtt';
                    $publisher->publish($projection);
                    $lastProjection=$projection;
                    $lastPoll=time();$lastHeartbeat=time();$lastRequest=$request;
                    $phase=$settings['enabled']?'running':'paused';
                    if($settings['enabled']&&$projection['data_valid']!==1){$error='Geräteverbindung fehlt oder ist abgelaufen.';}
                } elseif($now-$lastHeartbeat>=10){
                    $client->publish('astropet2lox/heartbeat',(string)$now);
                    $heartbeatCounter=($heartbeatCounter+1)%65536;
                    $client->publish('astropet2lox/heartbeat_counter',(string)$heartbeatCounter);
                    $lastHeartbeat=$now;
                }
                // Re-evaluate time gates locally every second, without cloud traffic.
                // A cached daily count must cease being valid at device midnight.
                if($lastProjection!==[]){
                    $config=$runtime->config();
                    $current=LoxoneOutput::project($runtime->bridge()->snapshot(),time(),$config['max_age_seconds']);
                    if(!$settings['enabled']){$current['data_valid']=0;}
                    foreach(['visits_today_valid','litter_level_valid','data_valid']as$field){
                        if(isset($current[$field],$lastProjection[$field])&&$current[$field]!==$lastProjection[$field]){
                            $client->publish('astropet2lox/'.$field,TopicCatalog::payload($current[$field]));
                            $lastProjection[$field]=$current[$field];
                        }
                    }
                    // Report ages on the heartbeat cadence; measurements remain untouched.
                    if($lastHeartbeat===$now){
                        foreach($current as$field=>$value){
                            if(substr((string)$field,-12)==='_age_seconds'&&isset($lastProjection[$field])&&$value!==$lastProjection[$field]){
                                $client->publish('astropet2lox/'.$field,TopicCatalog::payload($value));$lastProjection[$field]=$value;
                            }
                        }
                    }
                }
                $ok=true;$phase=$settings['enabled']?'running':'paused';
            }
        } catch(Throwable $ignored){
            if($stage==='config'){$error='Plugin-Konfiguration prüfen.';}
            elseif($stage==='device'){$error='Geräteverbindung fehlt oder ist abgelaufen.';}
            else{$error='MQTT-Verbindung nicht verfügbar.';}
            $phase='error';
            if($publisher!==null){try{$publisher->stop();}catch(Throwable $ignored){}}
            elseif($client!==null){try{$client->close();}catch(Throwable $ignored){}}
            $publisher=null;$client=null;
        }
        Support::writeJson($runtime->dataDir.'/worker-status.php',[
            'mqtt_ok'=>$ok,'last_mqtt_at'=>$ok?$lastHeartbeat:0,'last_tick_at'=>time(),'last_error'=>$error,'phase'=>$phase,'heartbeat_counter'=>$heartbeatCounter]);
        $logStatus=$phase.'|'.($error??'');
        if($log!==null&&$logStatus!==$loggedStatus){
            try{$log->INF('Status: '.$phase.($error!==null?' - '.$error:''));}catch(Throwable $ignored){}
            $loggedStatus=$logStatus;
        }
        if(!$daemon){break;}
        for($i=0;$i<10&&!$stop;$i++){usleep(100000);}
    } while(!$stop);
} catch(Throwable $ignored){
    $exitCode=1;
    fwrite(STDERR,"AstroPet2Lox: Plugin-Konfiguration oder Laufzeit prüfen.\n");
} finally {
    if($publisher!==null){try{$publisher->stop();}catch(Throwable $ignored){}}
    elseif($client!==null){try{$client->close();}catch(Throwable $ignored){}}
    if(isset($runtime)){try{Support::writeJson($runtime->dataDir.'/worker-status.php',['mqtt_ok'=>false,'last_mqtt_at'=>0,'last_tick_at'=>time(),'last_error'=>null,'phase'=>'paused','heartbeat_counter'=>$heartbeatCounter]);}catch(Throwable $ignored){}}
    if(is_resource($lock)){flock($lock,LOCK_UN);fclose($lock);}
    if($log!==null){try{$log->INF('Hintergrunddienst beendet.');}catch(Throwable $ignored){}}
}
exit($exitCode);
