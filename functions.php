<?php 
/* 
DEVELOPED BY MortezaVaei
Telegram Username : @MortezaVaezi_ir,
Site URL : mortezavaezi.ir
*/

function bot($method, $datas = []) {
    global $botApiKey;
    $url = "https://api.telegram.org/bot" . $botApiKey . "/" . $method;
    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, $url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_POSTFIELDS, $datas);
    $res = curl_exec($ch);
    if (curl_error($ch)) {
        var_dump(curl_error($ch));
    } else {
        return json_decode($res);
    }
}
function checkMembership($chatId, $userId) {
    $method = 'getChatMember';
    $params = [
        'chat_id' => $chatId,
        'user_id' => $userId
    ];

    $result = bot($method, $params);

    if ($result && isset($result->ok) && $result->ok === true) {
        return $result->result->status;
    } else {
        return false;
    }
}
?>