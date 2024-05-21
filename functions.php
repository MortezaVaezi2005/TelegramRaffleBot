<?php
/* 
DEVELOPED BY MortezaVaei
Telegram Username : @MortezaVaezi_ir,
Site URL : mortezavaezi.ir
*/

function bot($method, $datas = [])
{
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

function checkMembership($chatId, $userId)
{
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
function getLatestReleaseVersion() {
    $url = "https://api.github.com/repos/MortezaVaezi2005/TelegramRaffleBot/releases/latest";

    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, $url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_HTTPHEADER, array('User-Agent: PHP'));
    $response = curl_exec($ch);
    curl_close($ch);

    $data = json_decode($response, true);

    if(isset($data['tag_name'])) {
        return $data['tag_name'];
    } else {
        return false;
    }
}
function getBotUpdates()
{
    global $settings,$chat,$message;
    $lastUpdateCheck = $settings->getArrayByKey('lastUpdateCheck')['lastUpdateCheck'];
    if ($lastUpdateCheck == false){
        $settings->updateArrayByKey(['lastUpdateCheck'=>time()],'lastUpdateCheck');
    }elseif(abs(time() - $lastUpdateCheck) >= (24 * 60 * 60)){
        $latestVersion = getLatestReleaseVersion();
        if($latestVersion != false){
            $currentversion = $settings->getArrayByKey('version')['version'];
            if ($currentversion != $latestVersion){
                $bt[] = [["text"=>"دریافت آخرین نسخه ربات","url"=>"https://github.com/MortezaVaezi2005/TelegramRaffleBot/releases/latest"]];
                bot('sendMessage', [
                    'chat_id' => $chat->id,
                    'reply_to_message_id'=>$message->message_id,
                    'text' => '⚠️⚠️نسخه جدید ربات موجود است⚠️⚠️\n🟢 ادمین عزیز شما می توانید با مراجعه به صفحه پروژه ربات نسبت به دریافت سورس جدید ربات اقدام نمایید.\n\n\n\n 💠Developer @MortezaVaezi_ir',
                    'reply_markup' =>json_encode([
                        'inline_keyboard'=>$bt
                    ])
                ]);
            }
        }
    }
}

?>