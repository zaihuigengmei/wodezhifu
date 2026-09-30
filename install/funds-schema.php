<?php
/** Explicit CLI-only schema gate. Never loads common.php or invokes payment logic. */
if (PHP_SAPI !== 'cli') { http_response_code(404); exit('Not Found'); }
$mode=$argv[1] ?? '--check';
if (!in_array($mode,['--check','--apply'],true)) { fwrite(STDERR,"usage: php install/funds-schema.php --check|--apply\n"); exit(2); }
require dirname(__DIR__).'/config.php';
try {
    $prefix=$dbconfig['dbqz'];
    if (!is_string($prefix) || !preg_match('/^[A-Za-z0-9_]+$/D',$prefix)) throw new RuntimeException('invalid table prefix');
    $table=$prefix.'_funds_snapshot';
    $db=new PDO('mysql:host='.$dbconfig['host'].';port='.$dbconfig['port'].';dbname='.$dbconfig['dbname'].';charset=utf8mb4',$dbconfig['user'],$dbconfig['pwd'],[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION]);
    $exists=$db->prepare('SELECT ENGINE FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=:table');
    $exists->execute([':table'=>$table]);$engine=$exists->fetchColumn();
    if (!$engine && $mode==='--apply') {
        // Only one new metadata/receipt table, no ALTER, data write, legacy backfill or version flip.
        $sql=file_get_contents(__DIR__.'/update-funds-snapshot.sql');
        if ($sql===false) throw new RuntimeException('DDL asset missing');
        $db->exec(str_replace('pre_funds_snapshot','`'.$table.'`',$sql));
        $exists->execute([':table'=>$table]);$engine=$exists->fetchColumn();
    }
    if (!$engine) { echo json_encode(['present'=>false,'compatible'=>false])."\n";exit(1); }
    $stmt=$db->prepare('SELECT COLUMN_NAME,COLUMN_TYPE,CHARACTER_SET_NAME,COLLATION_NAME,IS_NULLABLE FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=:table ORDER BY ORDINAL_POSITION');
    $stmt->execute([':table'=>$table]);$cols=$stmt->fetchAll(PDO::FETCH_ASSOC);
    $expected=[['event_key','varchar(96)','ascii','ascii_bin','NO'],['payload','mediumtext','utf8mb4','utf8mb4_0900_ai_ci','NO'],['addtime','datetime',null,null,'NO']];
    $compatible=strcasecmp($engine,'InnoDB')===0 && count($cols)===3;
    foreach($cols as $i=>$c){
        if(!isset($expected[$i])){$compatible=false;continue;}
        $e=$expected[$i];
        // utf8mb4 default collation varies between supported MySQL/MariaDB versions.
        if($c['COLUMN_NAME']!==$e[0] || $c['COLUMN_TYPE']!==$e[1] || $c['CHARACTER_SET_NAME']!==$e[2] || $c['IS_NULLABLE']!=='NO')$compatible=false;
        if($i===0 && $c['COLLATION_NAME']!=='ascii_bin')$compatible=false;
    }
    $stmt=$db->prepare('SELECT INDEX_NAME,COLUMN_NAME,SEQ_IN_INDEX,NON_UNIQUE FROM information_schema.STATISTICS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=:table');
    $stmt->execute([':table'=>$table]);$indexes=$stmt->fetchAll(PDO::FETCH_ASSOC);
    $primary=array_values(array_filter($indexes,static function($r){return $r['INDEX_NAME']==='PRIMARY';}));
    if(count($primary)!==1 || $primary[0]['COLUMN_NAME']!=='event_key' || (int)$primary[0]['NON_UNIQUE']!==0)$compatible=false;
    echo json_encode(['present'=>true,'compatible'=>$compatible])."\n";
    exit($compatible?0:1);
} catch(Throwable $e) { fwrite(STDERR,"funds schema check/apply failed; inspect database/schema privately (no credentials logged)\n");exit(2); }
