<?php
declare(strict_types=1);
namespace AstroPet2Lox;

require_once __DIR__.'/AstroPet/Support.php';
require_once __DIR__.'/AstroPet/AstroPetAccount.php';
require_once __DIR__.'/AstroPet/MobileBridge.php';
require_once __DIR__.'/AstroPet/LoxoneOutput.php';
require_once __DIR__.'/TopicCatalog.php';
require_once __DIR__.'/Gateway.php';

final class Runtime
{
    private array $hooks;
    public string $configDir;
    public string $dataDir;
    public string $binDir;
    public function __construct(string $configDir, string $dataDir, string $binDir, array $hooks=[])
    {
        if (PHP_VERSION_ID < 70400 || PHP_INT_SIZE !== 8) { throw new \RuntimeException('PHP ab 7.4 mit 64 Bit erforderlich.'); }
        foreach ([$configDir,$dataDir] as $dir) { \AstroPet\Support::stateDir(['state_dir'=>$dir]); }
        $this->configDir=$configDir;
        $this->dataDir=$dataDir;
        $this->binDir=$binDir;
        $this->hooks=$hooks;
    }
    public static function fromLoxBerry(): self
    {
        foreach (['LBPCONFIGDIR','LBPDATADIR','LBPBINDIR'] as $key) {
            if (!defined($key)) { throw new \RuntimeException('LoxBerry-Pluginpfade konnten nicht ermittelt werden.'); }
        }
        return new self(LBPCONFIGDIR,LBPDATADIR,LBPBINDIR);
    }
    public function config(): array
    {
        $path=$this->configDir.'/account.php';
        $saved=\AstroPet\Support::readJson($path);
        if ($saved===null && is_file($path)) { throw new \RuntimeException('Die private Plugin-Konfiguration ist beschädigt.'); }
        $saved??=[];
        $email=$saved['astropet_email']??''; $password=$saved['astropet_password']??'';
        $interval=$saved['poll_interval_seconds']??60; $enabled=$saved['enabled']??false;
        if (!is_string($email)||!is_string($password)||!is_int($interval)||$interval<60||$interval>3600||!is_bool($enabled)) {
            throw new \RuntimeException('Ungültige Plugin-Konfiguration.');
        }
        return ['astropet_email'=>$email,'astropet_password'=>$password,'enabled'=>$enabled,
            'poll_interval_seconds'=>$interval,'max_age_seconds'=>max(180,$interval*3),'mapping'=>[],
            'state_dir'=>$this->dataDir.'/state'];
    }
    public function settings():array
    {
        $c=$this->config();
        return ['has_credentials'=>$c['astropet_email']!==''&&$c['astropet_password']!=='',
            'enabled'=>$c['enabled'],'poll_interval_seconds'=>$c['poll_interval_seconds'],'max_age_seconds'=>$c['max_age_seconds']];
    }
    public function saveSettings(string $email,string $password,int $interval=60,bool $enabled=true):void
    {
        if($interval<60||$interval>3600){throw new \RuntimeException('Abfrageintervall muss zwischen 60 und 3600 Sekunden liegen.');}
        $old=$this->config();
        $email=$email===''?$old['astropet_email']:trim($email);
        $password=$password===''?$old['astropet_password']:$password;
        if(!filter_var($email,FILTER_VALIDATE_EMAIL)||strlen($email)>254||$password===''||strlen($password)>1024||strpos($password,"\0")!==false){
            throw new \RuntimeException('Gültige AstroPet-E-Mail und Passwort eingeben.');
        }
        if($email!==$old['astropet_email']||$password!==$old['astropet_password']){$this->bridge()->disconnect();}
        \AstroPet\Support::writeJson($this->configDir.'/account.php',[
            'astropet_email'=>$email,'astropet_password'=>$password,'poll_interval_seconds'=>$interval,'enabled'=>$enabled]);
        $this->requestPublish();
    }
    public function bridge():\AstroPet\MobileBridge
    {
        $c=$this->config(); $dir=\AstroPet\Support::stateDir($c);
        return new \AstroPet\MobileBridge($c,$dir,$this->hooks['mobile_profile']??null,$this->hooks['mobile_transport']??null);
    }
    public function account():\AstroPet\AstroPetAccount{return new \AstroPet\AstroPetAccount($this->hooks['account_transport']??null);}
    public function requestPublish():void
    {
        \AstroPet\Support::writeJson($this->dataDir.'/publish-request.php',['nonce'=>bin2hex(random_bytes(16)),'requested_at'=>time()]);
    }
    public function disconnect():void{$this->bridge()->disconnect();$this->requestPublish();}
    public function gateway():Gateway
    {
        $systemDir=$this->hooks['system_config_dir']??(defined('LBSCONFIGDIR')?LBSCONFIGDIR:null);
        return new Gateway(is_string($systemDir)?$systemDir:'',$this->dataDir);
    }
    public function schema():array
    {
        $state=\AstroPet\Support::readJson($this->dataDir.'/state/mobile-state.php');
        $index=$state['selected_index']??null;
        if(is_int($index)&&is_array($state['devices'][$index]['schema']??null)) {return $state['devices'][$index]['schema'];}
        try {return json_decode(file_get_contents(__DIR__.'/mercury-schema.json'),true,64,JSON_THROW_ON_ERROR);}
        catch(\Throwable $error){return [];}
    }
    public function status():array
    {
        $c=$this->config();
        $p=\AstroPet\LoxoneOutput::project($this->bridge()->snapshot(),time(),$c['max_age_seconds']);
        $w=\AstroPet\Support::readJson($this->dataDir.'/worker-status.php')??[];
        $safe=[];
        foreach(['mqtt_ok','last_mqtt_at','last_tick_at','heartbeat_counter','phase']as$k){if(isset($w[$k])&&(is_int($w[$k])||is_bool($w[$k])||in_array($w[$k],['waiting','running','paused','error'],true))){$safe[$k]=$w[$k];}}
        $safe['last_error']=in_array($w['last_error']??null,[null,'MQTT-Verbindung nicht verfügbar.','Geräteverbindung fehlt oder ist abgelaufen.','Plugin-Konfiguration prüfen.'],true)?($w['last_error']??null):null;
        $recent=is_int($safe['last_mqtt_at']??null)&&$safe['last_mqtt_at']<=time()+5&&$safe['last_mqtt_at']>=time()-35;
        $p=TopicCatalog::projection($p,(int)($recent&&($safe['mqtt_ok']??false)&&$c['enabled']),(int)($safe['last_mqtt_at']??0));
        $p['heartbeat_counter']=is_int($safe['heartbeat_counter']??null)?$safe['heartbeat_counter']:0;
        if(!$c['enabled']){$p['data_valid']=0;}
        return ['projection'=>$p,'worker'=>$safe];
    }
}
