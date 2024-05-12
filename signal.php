<?php
function sigHandler($signal) {
    global $Daemon, $DB;
    switch ($signal) {
        case SIGTERM:
            //Stop programm
            if($Daemon->proc_name == 'ctd_main') {
                Log::systemLog("debug","Intercepted SIGTERM. Init stop ctdaemon");
            }
            if(!empty($Daemon->proc)) {
                foreach ($Daemon->proc as $proc=>$pval) {
                    //Посылаем каждому из дочерних процессов системный вызов на завершение работы
                    posix_kill($pval['pid'], SIGTERM);
                    Log::systemLog('debug',"Send SIGTERM to process ".$pval['name'].': pid='.$pval['pid']);
                    //destroy memory order book
                    if(isset($pval['name']) && $pval['name'] == 'ctd_exchange_orderbook') {
                        if(isset($pval['exchange_name'])) {
                            if(isset($pval['subscribe']) && !empty($pval['subscribe'])) {
                                foreach ($pval['subscribe'] as $s) {
                                    $hash = hash('xxh3', $pval['exchange_id'].'|'.$pval['market'].'|'.$s['id']);
                                    $id = ftok(__DIR__."/ftok/".$hash.".ftok", 'A');
                                    $id2 = ftok(__DIR__."/ftok/".$hash.".ftok", 'B');
                                    $shmId = shm_attach($id);
                                    $shmId2 = shm_attach($id2);
                                    shm_remove($shmId);
                                    shm_remove($shmId2);
                                    //destroy semaphores
                                    $semId = sem_get($id);
                                    $semId2 = sem_get($id2);
                                    sem_remove($semId);
                                    sem_remove($semId2);
                                    Log::systemLog(4,"Destroy memory ".$hash." order book");
                                }
                            }
                        }
                    }
                }
                foreach ($Daemon->proc as $proc=>$pval) {
                     $pid = pcntl_wait($status);
                     if(!pcntl_wifexited($status)) {
                        Log::systemLog("debug","Process pid=".$pid." killed");
                    }
                    else {
                        Log::systemLog("debug","Process pid=".$pid." exit complete");
                    }
                }
            }
            if($Daemon->proc_name == 'ctd_main') {
                //destroy memory
                $id = ftok(__DIR__."/ftok/ServiceRAM.php", 'A');
                $shmId = shm_attach($id);
                shm_remove($shmId);
                //destroy semaphores
                $semId = sem_get($id);
                sem_remove($semId);
                /*$id = ftok(__DIR__."/ftok/ServiceRAM.php", 'B');
                $shmId = shm_attach($id);
                shm_remove($shmId);
                //destroy semaphores
                $semId = sem_get($id);
                sem_remove($semId);*/
                
                $id = ftok(__DIR__."/ftok/ExternalRAM.php", 'A');
                $shmId = shm_attach($id);
                shm_remove($shmId);
                //destroy semaphores
                $semId = sem_get($id);
                sem_remove($semId);
                
                Log::systemLog(0,"ctdaemon STOPPED");
                printf("ctdaemon stopped".PHP_EOL);
            }
            exit();
            break;
        case SIGCHLD:
            //Обратный вызов по завершении дочернего процесса
            /*pcntl_signal(SIGCHLD, "sigHandler");
            $Daemon->errorLog('debug',"Intercepted SIGCHLD.");
            //Мы не знаем pid процесса, который создал этот обратный вызов, поэтому нужно 
            //пробежаться по всем завершенным процессам
            while(($pid = pcntl_wait($status, WNOHANG)) > 0) {
                //Очищаем массив proc от завершившихся процессов
                $time = 0;
                foreach ($Daemon->proc as $proc=>$element) {
                    if($pid == $element['pid']) {
                        $time = time() - $element['ctime'];
                        
                        //Фиксация самого длинного запроса
                        $name = $element['name'];
                        if($Daemon->services[$name]['max_time_success_request']<$time && $time < $Daemon->services[$name]['timeout']) {
                            $Daemon->services[$name]['max_time_success_request'] = $time;
                        }     
                        unset($Daemon->proc[$proc]);
                        $Daemon->proc = array_values($Daemon->proc);
                    }
                }
                if(!pcntl_wifexited($status)) {
                    $Daemon->errorLog("warn","Process pid=".$pid." killed. Lifetime ".$time." seconds");
                }
                else {
                    $Daemon->errorLog("debug","Process pid=".$pid." complete. Lifetime ".$time." seconds");
                }
            }
            return 1;*/
            break;
        case SIGUSR1:
            pcntl_signal(SIGUSR1, "sigHandler");
            $pid = getmypid();
            $time = microtime(true)*1E6;
            $Daemon->writeServiceRAM('out',$pid,'status_order_book_exchange','1',$time);
            Log::systemLog('debug',"Intercepted SIGUSR1 by ".$pid.". Status response to RAM timestamp=".$time);
            break;
        case SIGUSR2:
            pcntl_signal(SIGUSR2, "sigHandler");
            Log::systemLog(0,"SIGUSR2");
            /*$dobj = DateTime::createFromFormat('U.u', microtime(TRUE));
            $dobj->setTimeZone(new DateTimeZone('Europe/Moscow'));
            $date = $dobj->format('Y-m-d H:i:s.u');
            unset($dobj);
            $msg = PHP_EOL;
            $msg .= '******************'.PHP_EOL;
            $msg .= 'queuedaemon v1.1 '.PHP_EOL;
            $msg .= 'Request Timestamp: '.$date.PHP_EOL;
            $msg .= 'Status: Active'.PHP_EOL;
            $msg .= 'Total Working Processs: '.count($Daemon->proc).PHP_EOL;
            $msg .= 'Life time: '.round((time() - $Daemon->start)/3600, 3).' hours'.PHP_EOL;
            $msg .= 'Total request: '.$Daemon->count_request.PHP_EOL;
            $msg .= 'Total timeout: '.$Daemon->count_timeout.PHP_EOL;
            $msg .= PHP_EOL;
            foreach ($Daemon->services as $n=>$s) {
                $msg .= 'Service: '.$n.PHP_EOL;
                $w=0;
                foreach ($Daemon->proc as $d) {
                    if($d['name'] == $n){
                        $w++;
                    }
                }
                $msg .= 'Working Processs: '.$w.PHP_EOL;
                $msg .= 'Total requests: '.$s['count_request'].PHP_EOL;
                $msg .= 'Request timeout: '.$s['count_timeout'].PHP_EOL;
                $msg .= 'Maximum success request time: '.$s['max_time_success_request'].PHP_EOL;
            }
            $msg .= '******************'.PHP_EOL;
            $msg .= PHP_EOL;
             
            $systemid = "4999";
            $mode = "c"; // Режим доступа 
            $permissions = 0755; // Разрешения для сегмента общей памяти 
            $size = 1024; // Размер сегмента в байтах 
            $shmid = shmop_open($systemid, $mode, $permissions, $size); 
            shmop_write($shmid, $msg, 0);

            //$size = shmop_size($shmid); 
            //echo shmop_read($shmid, 0, $size); 
            shmop_close($shmid);
           // print_r($Daemon);
            return 1;*/
    }
}
?>