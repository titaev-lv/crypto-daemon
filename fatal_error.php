<?php
function fatal_error() {
    $error = error_get_last();
    if (isset($error['type']) && $error['type'] === E_ERROR) {
        Log::systemLog('error', "[FATAL ERROR] ".$error['message']." ".$error['file']." line ".$error['line']);
    }
}
?>
