<?php
declare(strict_types=1);
namespace AstroPet2Lox;
require_once __DIR__.'/AstroPet/Support.php';

/** Adds only this plugin's subscription using the current core JSON format. */
final class Gateway
{
    private string $systemDir;
    private string $dataDir;
    public function __construct(string $systemDir,string $dataDir)
    {
        $this->systemDir=$systemDir;
        $this->dataDir=$dataDir;
    }
    public function info():array
    {
        $subs=$this->read('subscriptions.json');$g=$this->read('mqttgateway.json');$general=$this->read('general.json');
        $registered=false;$routes=[];
        foreach($subs['Subscriptions']??[]as$s){if(($s['Id']??null)==='astropet2lox/#'){$registered=true;$routes=is_array($s['Toms']??null)?$s['Toms']:[];}}
        $ms=(string)($g['Main']['msno']??'1');
        $routes=$routes===[]?[$ms]:$routes;$configured=true;
        foreach($routes as$route){
            $server=is_int($route)||is_string($route)?($general['Miniserver'][(string)$route]??null):null;
            $address=is_array($server)?($server['Ipaddress']??''):'';$uri=is_array($server)?($server['Fulluri']??''):'';
            if((!is_string($address)||trim($address)==='')&&(!is_string($uri)||trim($uri)==='')){$configured=false;}
        }
        $enabled=self::enabled($general['Mqtt']['Gatewayautostart']??true);
        return ['registered'=>$registered,'miniserver_configured'=>$configured,'gateway_enabled'=>$enabled,
            'last_error'=>$this->systemDir===''?'LoxBerry-MQTT-Konfiguration nicht verfügbar.':null];
    }
    public function ensure():array
    {
        if($this->systemDir===''||!is_dir($this->systemDir)){throw new \RuntimeException('LoxBerry-MQTT-Konfiguration nicht verfügbar.');}
        $path=$this->systemDir.'/subscriptions.json';
        $existed=is_file($path);
        $handle=@fopen($path,'c+');
        if($handle===false||!flock($handle,LOCK_EX)){throw new \RuntimeException('MQTT-Weiterleitung konnte nicht gespeichert werden.');}
        try {
            rewind($handle);$raw=stream_get_contents($handle,1048577);
            if($raw===false||strlen($raw)>1048576){throw new \RuntimeException('MQTT-Subscriptions konnten nicht gelesen werden.');}
            if($raw===''&&$existed){throw new \RuntimeException('MQTT-Subscriptions sind ungültig.');}
            try{$cfg=$raw===''?(object)['Subscriptions'=>[]]:json_decode($raw,false,64,JSON_THROW_ON_ERROR);}
            catch(\JsonException $error){throw new \RuntimeException('MQTT-Subscriptions sind ungültig.');}
            if(!is_object($cfg)||!is_array($cfg->Subscriptions??null)||!self::isList($cfg->Subscriptions)){throw new \RuntimeException('MQTT-Subscriptions sind ungültig.');}
            foreach($cfg->Subscriptions as$sub){if(is_object($sub)&&($sub->Id??null)==='astropet2lox/#'){return $this->info();}}
            $entry=['Id'=>'astropet2lox/#','Toms'=>[],'Noncached'=>false,'Jsonexpand'=>false,'Json'=>[]];
            $cfg->Subscriptions[]=(object)$entry;
            $json=json_encode($cfg,JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR)."\n";
            rewind($handle);
            $offset=0;while($offset<strlen($json)){$n=fwrite($handle,substr($json,$offset));if($n===false||$n===0){throw new \RuntimeException('MQTT-Weiterleitung konnte nicht gespeichert werden.');}$offset+=$n;}
            if(!ftruncate($handle,strlen($json))||!fflush($handle)){throw new \RuntimeException('MQTT-Weiterleitung konnte nicht gespeichert werden.');}
            \AstroPet\Support::writeJson($this->dataDir.'/gateway-registration.php',['entry'=>$entry]);
        } finally {flock($handle,LOCK_UN);fclose($handle);}
        return $this->info();
    }
    /** Remove only an unchanged entry which this plugin actually created. */
    public function removeOwned():void
    {
        $record=\AstroPet\Support::readJson($this->dataDir.'/gateway-registration.php');
        $owned=$record['entry']??null;$path=$this->systemDir.'/subscriptions.json';
        if(!is_array($owned)||($owned['Id']??null)!=='astropet2lox/#'||!is_file($path)){return;}
        $h=@fopen($path,'r+');
        if($h===false||!flock($h,LOCK_EX)){throw new \RuntimeException('Eigene MQTT-Weiterleitung konnte nicht entfernt werden.');}
        try{
            $raw=stream_get_contents($h,1048577);
            try{$cfg=json_decode($raw?:'',false,64,JSON_THROW_ON_ERROR);}catch(\Throwable $error){return;}
            if(!is_object($cfg)||!is_array($cfg->Subscriptions??null)){return;}
            $after=[];$changed=false;ksort($owned);
            foreach($cfg->Subscriptions as$entry){
                $a=is_object($entry)?(array)$entry:null;if(is_array($a)){ksort($a);}
                if($a===$owned){$changed=true;}else{$after[]=$entry;}
            }
            if(!$changed){return;}
            $cfg->Subscriptions=$after;$json=json_encode($cfg,JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR)."\n";
            rewind($h);$offset=0;
            while($offset<strlen($json)){$n=fwrite($h,substr($json,$offset));if($n===false||$n===0){throw new \RuntimeException('Eigene MQTT-Weiterleitung konnte nicht entfernt werden.');}$offset+=$n;}
            if(!ftruncate($h,strlen($json))||!fflush($h)){throw new \RuntimeException('Eigene MQTT-Weiterleitung konnte nicht entfernt werden.');}
        }finally{flock($h,LOCK_UN);fclose($h);}
    }
    private function read(string $name):array
    {
        if($this->systemDir===''){return [];}
        try{$raw=@file_get_contents($this->systemDir.'/'.$name);return is_string($raw)?(json_decode($raw,true,64,JSON_THROW_ON_ERROR)?:[]):[];}
        catch(\Throwable $error){return [];}
    }
    private static function isList(array $values):bool
    {
        $index=0;
        foreach($values as$key=>$value){if($key!==$index++){return false;}}
        return true;
    }
    private static function enabled($v):bool{return in_array($v,[true,1,'1','true','True'],true);}
}
