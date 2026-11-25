<?php
function sms_send(string $to, string $msg): bool {
    global $C;
    $p = preg_replace('/[^\d+]/', '', $to);
    if (str_starts_with($p, '0')) $p = '+250' . substr($p, 1);
    elseif (str_starts_with($p, '250')) $p = '+' . $p;
    elseif (!str_starts_with($p, '+') && strlen($p) === 9) $p = '+250' . $p;
    $at = $C['at'];
    if (!$at['user'] || !$at['key']) { error_log("[SMS demo] $p $msg"); return false; }
    $ch = curl_init(($at['user'] === 'sandbox' ? 'https://api.sandbox.africastalking.com' : 'https://api.africastalking.com') . '/version1/messaging');
    curl_setopt_array($ch, [
        CURLOPT_POST => true, CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 10,
        CURLOPT_HTTPHEADER => ['apiKey: ' . $at['key'], 'Accept: application/json'],
        CURLOPT_POSTFIELDS => http_build_query(array_filter(['username' => $at['user'], 'to' => $p, 'message' => $msg, 'from' => $at['sender']])),
    ]);
    $r = curl_exec($ch); $code = curl_getinfo($ch, CURLINFO_HTTP_CODE); curl_close($ch);
    error_log("[SMS] $code $r");
    return $code >= 200 && $code < 300;
}
