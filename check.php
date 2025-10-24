<?php
header('Content-Type: application/json; charset=utf-8');

$hosts = [
  'http://mehmetbagcivan.com',
  'http://hatabul.com',
  'http://turbokonya.com'
];

function ping_http($url, $timeout_ms = 1500){
    $start = microtime(true);
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_NOBODY => true,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT_MS => $timeout_ms,
        CURLOPT_CONNECTTIMEOUT_MS => $timeout_ms,
        CURLOPT_SSL_VERIFYPEER => false,
        CURLOPT_SSL_VERIFYHOST => 0
    ]);
    $res = curl_exec($ch);
    $errno = curl_errno($ch);
    $http  = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    $ms = (microtime(true)-$start)*1000;
    if($errno==0 && $http>=200 && $http<400)
        return $ms;
    else
        return null;
}

$pings = [];
foreach($hosts as $h){
    $t = ping_http($h);
    if($t !== null) $pings[] = $t;
}

if(count($pings)>0){
    $avg = array_sum($pings)/count($pings);
    $internet = true;
}else{
    $avg = null;
    $internet = false;
}

echo json_encode([
    'internet' => $internet,
    'avg_ms' => $avg,
    'hosts' => $hosts,
    'timestamp' => date('c')
]);
