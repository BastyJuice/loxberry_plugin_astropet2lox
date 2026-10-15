<?php
declare(strict_types=1);
namespace AstroPet2Lox;

/** One shared catalogue for the MQTT publisher and the administration table. */
final class TopicCatalog
{
    private const MAIN=[
        'availability'=>['Plugin-MQTT-Verbindung: 1 aktiv, 0 getrennt oder pausiert. Last Will setzt bei Verbindungsabbruch 0.','0/1',''],
        'heartbeat'=>['Zeit der letzten Veröffentlichung. 0 bedeutet unbekannt.','Unix-Sekunden',''],
        'heartbeat_counter'=>['Lebenszeichen: Zähler 0 bis 65535, ändert sich normalerweise alle 10 Sekunden. Für den Loxone-Watchdog verwenden.','Zähler',''],
        'bridge_ok'=>['Ein zum ausgewählten Gerät passender Gerätesnapshot ist vorhanden.','0/1',''],
        'data_valid'=>['Letzter Geräteabruf erfolgreich, Cache frisch und Gerät online.','0/1','availability=1'],
        'online'=>['Gemeldete Cloud-Verbindung: 1 online, 0 offline, -1 unbekannt.','-1/0/1',''],
        'last_poll_ok'=>['Ergebnis des letzten Geräteabrufs.','0/1',''],
        'data_age_seconds'=>['Zeit seit dem letzten erfolgreichen Geräteabruf, -1 unbekannt.','s',''],
        'weight_kg'=>['Zuletzt gemessenes Katzengewicht; keine fortlaufende Live-Waage.','kg','availability=1, data_valid=1, weight_available=1; Alter beachten'],
        'weight_g'=>['Zuletzt gemessenes Katzengewicht in Gramm.','g','availability=1, data_valid=1, weight_available=1; Alter beachten'],
        'visits_today'=>['Gemeldeter täglicher Benutzungszähler.','Anzahl','availability=1, data_valid=1, visits_today_valid=1, visits_today_available=1'],
        'visits_today_valid'=>['Zählermeldung stammt vom heutigen Kalendertag der Gerätezeitzone.','0/1',''],
        'last_visit_duration_seconds'=>['Zuletzt gemeldete Dauer eines Toilettenbesuchs.','s','availability=1, data_valid=1, duration_available=1'],
        'litter_level_percent'=>['Zuletzt gemeldeter Streu-Status laut Produktschema. Bereich 0 bis 250; kein Abfallbehälter-Füllstand.','%','availability=1, data_valid=1, litter_level_valid=1, litter_level_available=1'],
        'litter_level_valid'=>['Streumeldung stammt von heute und ist höchstens 180 Sekunden alt.','0/1',''],
        'work_state'=>['Numerischer Arbeitszustand. Bedeutungen der Codes 0 bis 11 sind nicht verifiziert.','Code','availability=1, data_valid=1, work_state_available=1'],
        'message_bitmap'=>['Gemeldete Meldungsbits. Einzelne Bitbedeutungen sind nicht verifiziert.','Bitfeld','availability=1, data_valid=1'],
        'fault_bitmap'=>['Gemeldetes Fehler-Bitfeld BIT/E1. Einzelne Bitbedeutungen sind nicht verifiziert.','Bitfeld','availability=1, data_valid=1'],
        'fault_active'=>['Mindestens ein gemeldetes Meldungs-/Fehlerbit gesetzt. 0 setzt beide bekannten Quellen voraus.','0/1','availability=1, data_valid=1, fault_available=1'],
        'auto_clean_enabled'=>['Gemeldete Einstellung für automatische Reinigung; dieses Plugin verändert sie nicht.','0/1','availability=1, data_valid=1, auto_clean_available=1'],
    ];
    private const AVAILABLE=['weight_available'=>'weight_kg','visits_today_available'=>'visits_today','duration_available'=>'last_visit_duration_seconds',
        'litter_level_available'=>'litter_level_percent','work_state_available'=>'work_state','fault_available'=>'fault_active','auto_clean_available'=>'auto_clean_enabled'];
    private const REPORTS=['weight'=>'Gewichtsmeldung','visits_today'=>'Tageszählermeldung','last_visit_duration'=>'Dauermeldung','litter_level'=>'Streumeldung',
        'message'=>'Meldungsbitmap','fault'=>'Fehlerbitmap','fault_active'=>'zusammengefasster Fehlerzustand'];
    private const CODES=[
        'delay_clean_time'=>'Wartezeit nach dem Verlassen bis zur automatischen Reinigung',
        'cat_weight'=>'Zuletzt gemessenes Katzengewicht','excretion_times_day'=>'Gemeldete tägliche Benutzungsanzahl',
        'excretion_time_day'=>'Gemeldete Besuchsdauer','sleep'=>'Gemeldete Schlafmodus-Einstellung',
        'sleep_start_time'=>'Schlafmodus-Startwert laut Geräteschema','sleep_end_time'=>'Schlafmodus-Endwert laut Geräteschema',
        'factory_reset'=>'Gemeldeter Werksreset-Schalter; wird vom Plugin nicht betätigt',
        'work_state'=>'Arbeitszustand als unverifizierter Zahlencode','allowance'=>'Gemeldeter Streu-Status laut Schema',
        'message'=>'Meldungsbitfeld ohne verifizierte Bitbedeutungen','man_mode'=>'Manueller Modus als Zahlencode',
        'sav'=>'Gemeldete Energiespareinstellung des Displays','language'=>'Gemeldete Display-Sprache als Zahlencode',
        'self_check'=>'Gemeldete Einstellung des täglichen Selbsttests','self_check_time'=>'Gemeldeter Selbsttest-Zeitwert',
        'bit'=>'Fehler-Bitfeld BIT/E1 ohne verifizierte Bitbedeutungen','clear'=>'Gemeldete Geruchsreinigungs-Einstellung',
        'light2'=>'Gemeldete Einstellung der Innenbeleuchtung','auto'=>'Gemeldete automatische Reinigung',
        'startr'=>'Gemeldeter Beginn-Zeitwert eines Besuchs; Format nicht verifiziert',
        'endr'=>'Gemeldeter Ende-Zeitwert eines Besuchs; Format nicht verifiziert',
        'automaticd'=>'Gemeldete automatische Geruchsreinigung','unusual'=>'Unverifizierter Zahlencode für die Geräte-Anomalieanzeige',
    ];
    public static function projection(array $p,int $availability,int $heartbeat):array
    {
        $p['availability']=$availability;$p['heartbeat']=$heartbeat;
        foreach(self::AVAILABLE as$flag=>$field){$p[$flag]=(int)array_key_exists($field,$p);}
        return $p;
    }
    public static function rows(array $projection,array $schema=[]):array
    {
        $catalog=self::MAIN;
        foreach(self::AVAILABLE as$flag=>$field){$catalog[$flag]=['Der Wert '.$field.' ist im gemeldeten Snapshot vorhanden.','0/1',''];}
        foreach(self::REPORTS as$prefix=>$label){
            $catalog[$prefix.'_reported_at']=['Zeitpunkt der '.$label.'. 0 bedeutet unbekannt.','Unix-Sekunden',''];
            $catalog[$prefix.'_age_seconds']=['Alter der '.$label.'. -1 bedeutet unbekannt; wird bei jeder Ausgabe neu berechnet.','s',''];
        }
        foreach($schema as$row){
            if(!is_array($row)||!is_string($row['code']??null)||!isset($row['id'])){continue;}
            $property=$row['property']??[];
            if(is_string($property)){try{$property=json_decode($property,true,32,JSON_THROW_ON_ERROR);}catch(\Throwable $error){continue;}}
            if(!is_array($property)||!in_array($property['type']??null,['value','bool','enum','bitmap'],true)){continue;}
            $id=(string)$row['id'];$code=strtolower($row['code']);
            if(!preg_match('/\A[1-9][0-9]{0,8}\z/D',$id)||!preg_match('/\A[a-z][a-z0-9_]{0,59}\z/D',$code)){continue;}
            $desc=self::CODES[$code]??('Gemeldeter Schema-Datenpunkt '.$row['code'].'. Bedeutung nicht verifiziert.');
            $unit=$property['type']==='value'?(string)($property['unit']??''):(['bool'=>'0/1','enum'=>'Code','bitmap'=>'Bitfeld'][$property['type']]);
            $unit=['分钟'=>'min','秒'=>'s','times'=>'Anzahl'][$unit]??$unit;
            $catalog['dp_'.$id]=[$desc.'. Schema-geprüfter Rohwert, ohne Skalierung.','Rohwert','availability=1, data_valid=1; letzte Gerätemeldung'];
            $catalog['dp_'.$code]=[$desc.'. Wert nach Schema-Skalierung.',$unit,'availability=1, data_valid=1; letzte Gerätemeldung'];
        }
        foreach($projection as$key=>$value){if(!isset($catalog[$key])&&is_string($key)&&preg_match('/\A[a-z0-9_]{1,64}\z/D',$key)){$catalog[$key]=['Zusätzlicher numerischer Gerätewert. Bedeutung nicht verifiziert.','Zahl','availability=1, data_valid=1'];}}
        $rows=[];
        foreach($catalog as$field=>[$description,$unit,$validity]){$rows[]=['field'=>$field,'topic'=>'astropet2lox/'.$field,
            'loxone_name'=>'astropet2lox_'.$field,'description'=>$description,'unit'=>$unit,'validity'=>$validity,'value'=>$projection[$field]??null];}
        return $rows;
    }
    /** @param int|float $value */
    public static function payload($value):string
    {
        if(!is_int($value)&&!is_float($value)){throw new \TypeError('MQTT-Zahlenwert muss int oder float sein.');}
        if(is_float($value)&&!is_finite($value)){throw new \RuntimeException('Ungültiger MQTT-Zahlenwert.');}
        return is_int($value)?(string)$value:rtrim(rtrim(sprintf('%.12F',$value),'0'),'.');
    }
}
