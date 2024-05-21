<?php
/* 
DEVELOPED BY MortezaVaei
Telegram Username : @MortezaVaezi_ir,
Site URL : mortezavaezi.ir
*/
date_default_timezone_set("Asia/Tehran");
if (!file_exists("setting.json") || empty(file_get_contents('setting.json')) || !file_exists("vendor/autoload.php")) {
    header("location:install.php");
    die();
}
include 'vendor/autoload.php';
include "tempDatasManager.php";
include "functions.php";
$settings = new TempDatasManager('setting.json');
$usersDB = new TempDatasManager('participants.json');
$MadelineProto = new \danog\MadelineProto\API('session.madeline');
$MadelineProto->start();
$botApiKey = $settings->getArrayByKey('botToken')['botToken'];
$admins = $settings->getArrayByKey('admins');
$chs = $settings->getArrayByKey('channels');
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
                'reply_markup'=>json_encode(['keyboard' => [[ '⚙️ وضعیت شرکت کنندگان ⚙️'],[ '🌪️ قرعه کشی معمولی 🌪️', '📬 قرعه کشی کامنتی 📬'],[ 'روشن', 'خاموش'],['🗑️ پاکسازی لیست شرکت کنندگان 🗑️'],['درباره ربات']],
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
            if($datas != null){
                $count = count($datas);
            }else{
                $count = 0;
            }
            $timestamp = time();
            if($datas != null && isset(end($datas)['timestamp'])) $timestamp = end($datas)['timestamp'];
            $lastStartTime = date('H:i Y/m/d', $timestamp);
            $date = date('Y/m/d H:i');
            bot('sendMessage', [
                'chat_id' => $chat->id,
                'text' => "تعداد شرکت کنندگان:  {$count}\nثبت نام آخرین شرکت کننده : {$lastStartTime}\n\n\nTime : {$date}",
                'reply_to_message_id'=>$message->message_id]);
        }elseif($message->text == '🌪️ قرعه کشی معمولی 🌪️'){
            $rafflePageUrl = str_replace("bot.php","",$_SERVER['SCRIPT_URI'])."registrationRaffle.php";
            $count = count($usersDB->getAllData());
            if($count>=2){
                $chBt[] = [["text"=>"ورود به صفحه قرعه کشی","url"=>$rafflePageUrl]];
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

        }elseif($message->text == '📬 قرعه کشی کامنتی 📬'){
            bot('sendMessage', [
                'chat_id' => $chat->id,
                'reply_to_message_id'=>$message->message_id,
                'text' => "📇 راهنمای قرعه کشی کامنتی 📇\n\n\n💠 ابتدا مطمئن شوید که اکانت متصل به بات، در کانال مورد نظر عضو است و به محتوای آن دسترسی دارد.\n\n💠سپس پست مورد نظر که حاوی کامنت های قرعه کشی است را به ربات فوروارد کنید.\n\n💠 بعد از فوروارد، درصورت انجام درست مراحل بالا، ربات لینک ورود به صفحه قرعه کشی کامنتی را برای شما ارسال خواهد کرد."
            ]);
        }elseif($message->text == 'درباره ربات'){
            $bt[] = [["text"=>"GitHub Repository","url"=>'https://github.com/MortezaVaezi2005/TelegramRaffleBot']];
            $bt[] = [['text'=>'About Developer','url'=>'https://mortezavaezi.ir/my-telegram-raffle-bot-project/']];
            bot('sendMessage', [
                'chat_id' => $chat->id,
                'reply_to_message_id'=>$message->message_id,
                'text' => "با استفاده از دکمه های زیر میتوانید با پروژه ربات قرعه کشی بیشتر آشنا شوید👇👇👇",
                'reply_markup' =>json_encode([
                    'inline_keyboard'=>$bt
                ])
            ]);
        }elseif(isset($message->forward_origin)){
            $forward = $message->forward_origin;
            if($forward->type == 'channel'){
                if(isset($forward->chat->username)){
                    try {
                        $repliers = $MadelineProto->messages->getReplies(peer:"@{$forward->chat->username}", msg_id: $forward->message_id,limit:50)['messages'];
                        if (count($repliers) > 0) {
                            $offset_id =   end($repliers)['id'];
                            while (true) {
                                $result = $MadelineProto->messages->getReplies(peer:"@{$forward->chat->username}", msg_id: $forward->message_id,limit:100,offset_id: $offset_id)['messages'];

                                if (isset($result) && count($result) > 0) {
                                    $repliers = array_merge($result,$repliers);
                                    $offset_id = end($result)['id'];
                                } else {
                                    break;
                                }
                            }
                        }
                        $fromIds = array_values(array_unique(array_column($repliers, 'from_id')));
                        $rafflePageUrl = str_replace("bot.php","",$_SERVER['SCRIPT_URI'])."commentRaffle.php?".http_build_query(['fromIds' => $fromIds]);
                        $count = count($fromIds);
                        if($count>=2){
                            bot('sendMessage', [
                                'chat_id' => $chat->id,
                                'reply_to_message_id'=>$message->message_id,
                                'parse_mode'=>'HTML',
                                'text' => "برای ورود به صفحه قرعه کشی کامنتی روی لینک زیر کلیک کنید👇👇👇\n\n<a href='{$rafflePageUrl}'>👨‍💻👨‍💻ورود به صفحه قرعه کشی کامنتی👨‍💻👨‍💻</a>",
                            ]);
                        }else{
                            bot('sendMessage', [
                                'chat_id' => $chat->id,
                                'reply_to_message_id'=>$message->message_id,
                                'text' => "حداقل تعداد افرادی که کامنت گذاشته اند باید 2 نفر باشد."
                            ]);
                        }
                    } catch (\danog\MadelineProto\RPCErrorException $e) {
                        $MadelineProto->logger($e);
                    }
                }else{
                    bot('sendMessage', [
                        'chat_id' => $chat->id,
                        'reply_to_message_id'=>$message->message_id,
                        'text' => ' امکان قرعه کشی میان کامنت های این پست وجود ندارد😞🥀'
                    ]);
                }
            }else{
                bot('sendMessage', [
                    'chat_id' => $chat->id,
                    'reply_to_message_id'=>$message->message_id,
                    'text' => "⚠️⚠️⚠️\n\nقرعه کشی کامنتی تنها برای پیام هایی که در چنل ها ارسال شده اند، امکان پذیر است!"
                ]);
            }
        }
        getBotUpdates();
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