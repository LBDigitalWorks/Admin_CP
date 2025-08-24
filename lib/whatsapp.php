<?php
function send_whatsapp(string $toE164, string $message): bool {
    $sid   = TWILIO_SID;
    $token = TWILIO_TOKEN;
    $from  = TWILIO_WHATSAPP_FROM;

    $url  = "https://api.twilio.com/2010-04-01/Accounts/$sid/Messages.json";
    $data = [
        'From' => $from,
        'To'   => 'whatsapp:' . $toE164,
        'Body' => $message,
    ];

    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => http_build_query($data),
        CURLOPT_USERPWD => $sid . ":" . $token,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => 15
    ]);
    $resp = curl_exec($ch);
    $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    return $code >= 200 && $code < 300;
}
