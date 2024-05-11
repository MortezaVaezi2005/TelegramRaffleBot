<?php
date_default_timezone_set("Asia/Tehran");
include "tempDatasManager.php";
$botApiKey = "Bot API Key"; //Bot Api Key
$admins = ['Your Id']; //Admins Arrays
$chs = array(
    array(
        "id"=>'-1002090427187', // Channel Id
        "title"=>"Wish Time", // Channel Username
        "link"=>'https://t.me/WishTimem' // Channel Link
        ),
    array(
        "id"=>'-1002090427187', // Channel Id
        "title"=>"Wish Time", // Channel Username
        "link"=>'https://t.me/WishTimem' // Channel Link
        )
    );
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
/* 
DEVELOPED BY MortezaVaei
Telegram Username :  @MortezaVaezi_ir,
Site URL : mortezavaezi.ir
*/
$settings = new TempDatasManager('setting.json');
$usersDB = new TempDatasManager('participants.json');
$update = json_decode(file_get_contents('php://input'));
$off = false;
$botStatus = $settings->getArrayByKey('status');
if($botStatus != false){
   if($botStatus['status'] == 'off'){
       $off = true;
   }
}
if(isset($update->message) ){
    $message = $update->message;
    $chat = $message->chat;
    $from = $message->from;
    $userId = $from->id;
    $firstName = $from->first_name;
    if(in_array($userId,$admins)){
        if($message->text == '/start'){
                     bot('sendMessage', [
                'chat_id' => $chat->id,
                'text' => "سلام، به ربات خودتان خوش آمدید.",
                'reply_to_message_id'=>$message->message_id,
                'reply_markup'=>json_encode(['keyboard' => [[ '⚙️ وضعیت شرکت کنندگان ⚙️', '🌪️ قرعه کشی 🌪️'],[ 'روشن', 'خاموش'],['🗑️ پاکسازی لیست شرکت کنندگان 🗑️']],
            	'resize_keyboard'=>true])]);   
        }elseif($message->text == 'روشن' ){
            $settings->updateArrayByKey(['status'=>'on'],'status');
            bot('sendMessage', [
                'chat_id' => $chat->id,
                'text' => "ربات فعال شد.",
                'reply_to_message_id'=>$message->message_id]);
        }elseif($message->text == 'خاموش'){
            $settings->updateArrayByKey(['status'=>'off'],'status');
            bot('sendMessage', [
                'chat_id' => $chat->id,
                'text' => "ربات خاموش شد.",
                'reply_to_message_id'=>$message->message_id]);
        }elseif($message->text == '⚙️ وضعیت شرکت کنندگان ⚙️'){
            $datas = $usersDB->getAllData();
            $count = count($datas);
            $lastStartTime = date('H:i Y/m/d', end($datas)['timestamp']);
            $date = date('Y/m/d H:i');
            bot('sendMessage', [
                'chat_id' => $chat->id,
                'text' => "تعداد شرکت کنندگان:  {$count}\nثبت نام آخرین شرکت کننده : {$lastStartTime}\n\n\nTime : {$date}",
                'reply_to_message_id'=>$message->message_id]);
        }elseif($message->text == '🌪️ قرعه کشی 🌪️'){
            $LotteryPageUrl = str_replace("bot.php","",$_SERVER['SCRIPT_URI'])."lottery.php";
            $count = count($usersDB->getAllData());
            if($count>=2){
                $chBt[] = [["text"=>"ورود به صفحه قرعه کشی","url"=>$LotteryPageUrl]];
                bot('sendMessage', [
                    'chat_id' => $chat->id,
                    'reply_to_message_id'=>$message->message_id,
                    'text' => 'برای انجام قرعه کشی از طریق دکمه زیر وارد صفحه قرعه کشی شوید.',
                    'reply_markup' =>json_encode([
                       'inline_keyboard'=>$chBt
                          ])
                ]);
            }else{
                bot('sendMessage', [
                    'chat_id' => $chat->id,
                    'reply_to_message_id'=>$message->message_id,
                    'text' => "حداقل تعداد شرکت کنندگان در قرعه کشی باید حداقل 2 تا باشد."
                ]);
            }
        }elseif($message->text == '🗑️ پاکسازی لیست شرکت کنندگان 🗑️'){
            $chBt[] = [["text"=>"بله، پاک شود!","callback_data"=>'cleanParticipants'],['text'=>'خیر، اشتباه شد!','callback_data'=>'noCleanParticipants']];
            bot('sendMessage', [
            'chat_id' => $chat->id,
            'reply_to_message_id'=>$message->message_id,
            'text' => "⚠️⚠️ اخطار ⚠️⚠️\n\nآیا از پاکسازی لیست شرکت کنندگان اطمینان دارید؟\n\n ⭕در صورت پاکسازی لیست شرکت کنندگان امکان برگرداندن آنها وجود ندارد❕❕",
            'reply_markup' =>json_encode([
               'inline_keyboard'=>$chBt
                  ])
            ]);

        }
/* 
DEVELOPED BY MortezaVaei 
Telegram Username : @MortezaVaezi_ir,
Site URL : mortezavaezi.ir
*/
    }else{
        if($off == true){
            bot('sendMessage', ['chat_id' => $chat->id, 'text' =>'ربات توسط ادمین خاموش شده است.
           امکان دارد این خاموشی به دلیل پایان مهلت شرکت در قرعه کشی باشد.']);
           die();
        }
        if($message->text == '/start'){
        start:
        $chBt = [];
        foreach($chs as $channel){
            if(checkMembership($channel['id'], $from->id) == false ||checkMembership($channel['id'], $from->id) == "left"){
                $chBt[] = [["text"=>$channel['title'],"url"=>$channel['link']]];
            }
        }
        if(empty($chBt)){
            if($usersDB->getArrayByKey($from->id) == false){
                $usersDB->createOrOpenTempDatasFile($from, $from->id);
                 bot('sendMessage', [
                 'chat_id' => $chat->id, 
                 'text' => "کاربر عزیز شما در قرعه کشی شرکت داده شدید. 
                 لطفا نتیجه قرعه کشی را از طریق کانال دنبال کنید.",
                'reply_to_message_id'=>$message->message_id]);
            }else{
                 bot('sendMessage', [
                'chat_id' => $chat->id,
                'text' => "شما یکبار در قرعه کشی شرکت داده شده اید!
                 لطفا اخبار قرعه کشی را از کانال دنبال کنید، تا از نتیجه مطلع شوید.",
                'reply_to_message_id'=>$message->message_id]);
            }   
/* 
DEVELOPED BY MortezaVaei  
Telegram Username : @MortezaVaezi_ir,
Site URL : mortezavaezi.ir
*/
        }else{
            $chBt[] = [["text"=>"✅ عضو شدم ✅","callback_data"=>"membershipConfirmation"]];
            bot('sendMessage', [
                'chat_id' => $chat->id,
                'reply_to_message_id'=>$message->message_id,
                'text' => 'برای شرکت در قرعه کشی شما باید در کانال های زیر عضو شوید.',
                'reply_markup' =>json_encode([
                   'inline_keyboard'=>$chBt
                      ])
            ]);
        }
    }
    }
}elseif(isset($update->callback_query)){
    $message = $update->callback_query->message;
    $chat = $message->chat;
    $from = $update->callback_query->from;
    $messageId = $message->message_id;
    $data = $update->callback_query->data;
    $userId = $from->id;
/* 
DEVELOPED BY MortezaVaei    
Telegram Username : @MortezaVaezi_ir,
Site URL : mortezavaezi.ir
*/
    if(in_array($userId,$admins)){
        if($data == "cleanParticipants"){
            $usersDB->deleteOldArrays(0);
            bot('editMessageText', [
                'chat_id' => $chat->id,
                'message_id' => $messageId,
                'text' => 'لیست شرکت کنندگان در قرعه کشی پاک شد.'
            ]);
        }elseif($data == "noCleanParticipants"){
            bot('editMessageText', [
                'chat_id' => $chat->id,
                'message_id' => $messageId,
                'text' => 'عملیات پاکسازی لیست شرکت کنندگان لغو شد.'
            ]);
        }
    }else{
        if($off == true){
            bot('sendMessage', ['chat_id' => $chat->id, 'text' =>'ربات توسط ادمین خاموش شده است.
           امکان دارد این خاموشی به دلیل پایان مهلت شرکت در قرعه کشی باشد.']);
           die();
        }
        if($data == "membershipConfirmation"){
            $chBt = [];
            foreach($chs as $channel){
                if(checkMembership($channel['id'], $from->id) == false ||checkMembership($channel['id'], $from->id) == "left"){
                    $chBt[] = [["text"=>$channel['title'],"url"=>$channel['link']]];
                }
            }
            if(empty($chBt)){
                $message = $update->callback_query->message->reply_to_message;
                $chat = $message->chat;
                $from = $message->from;
                $userId = $from->id;
                $firstName = $from->first_name;
                bot('deleteMessage', [
                'chat_id' => $chat->id,
                'message_id' => $messageId
                ]);     
                goto start;      
            }else{
/* 
DEVELOPED BY MortezaVaei          
Telegram Username : @MortezaVaezi_ir,
Site URL : mortezavaezi.ir
*/
                $chBt[] = [["text"=>"✅ عضو شدم ✅","callback_data"=>"membershipConfirmation"]];
                bot('editMessageText', [
                    'chat_id' => $chat->id,
                    'message_id' => $messageId,
                    'text' => 'لطفا در کانال های زیر عضو شوید و سپس دکمه  عضو شدم را بفشارید.',
                    'reply_markup' =>json_encode([
                       'inline_keyboard'=>$chBt
                          ])
                ]);
            }
        }
    }
}



/* 
DEVELOPED BY MortezaVaei                
Telegram Username : @MortezaVaezi_ir,
Site URL : mortezavaezi.ir
*/

?>