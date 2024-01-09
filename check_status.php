<?php
//Сначала нужно послать процессу  SIGUSR2
if (file_exists(__DIR__."/run/queuedaemon.pid")) {
    $file_conf = __DIR__."/run/queuedaemon.pid";
}
elseif (file_exists("/var/www/queuedaemon/run/queuedaemon.pid")) {
    $file_conf = "/var/www/queuedaemon/run/queuedaemon.pid";
}
else {
    $message = "FATAL ERROR read pid file. File not found.";
    exit();
}

$pid = intval(file_get_contents($file_conf));

if($pid > 0) {
    $systemid = "4999";
    $mode = "c"; // Режим доступа 
    $permissions = 0755; // Разрешения для сегмента общей памяти 
    $size = 1024; // Размер сегмента в байтах    
    if($shmid1 = shmop_open($systemid, $mode, $permissions, $size)){
        //echo shmop_read($shmid1, 0, $size);
        shmop_delete($shmid1);
        unset($shmid1);
    }
    
    posix_kill($pid, SIGUSR2);

    while (!$shmid = shmop_open($systemid, $mode, $permissions, $size)) {
        usleep(50000);
    } 
    $size = shmop_size($shmid); 
    while(1) {
        $a = shmop_read($shmid, 0, $size);
        if(strstr($a,"queuedaemon v")){
            break;
        }
    }
    echo $a;
    shmop_delete($shmid); 
}
else {
    exit("Error read pid");
}





