<?php
/* 
DEVELOPED BY MortezaVaei
Telegram Username : @MortezaVaezi_ir,
Site URL : mortezavaezi.ir
*/
if (!file_exists("vendor/autoload.php")) { ?>
    <p>You must first install the program's prerequisites using Composer with the following command, and then proceed to run the bot's easy installation file.</p>
    <code>composer install</code>
<?php    die();
}
include 'vendor/autoload.php';
include "tempDatasManager.php";
include "functions.php";
$MadelineProto = new \danog\MadelineProto\API('session.madeline');
$MadelineProto->start();
if(isset($_POST['submit'])){
    $settings = new TempDatasManager('setting.json');
    $settings->updateArrayByKey(['status'=>'off'],'status');
    $settings->updateArrayByKey(['botToken'=>$_POST['botToken']],'botToken');
    $settings->updateArrayByKey(['version'=>'V2.3'],'version');
    $admins = [];
    foreach ($_POST['admins'] as $object) {
        $admins[] = $object['id'];
    }
    $settings->updateArrayByKey($admins,'admins');
    $settings->updateArrayByKey($_POST['channels'],'channels');
    $botUrl = str_replace("install.php","bot.php",$_SERVER['SCRIPT_URI']);
    file_get_contents("https://api.telegram.org/bot{$_POST['botToken']}/setwebhook?url={$botUrl}");
    echo "The bot was installed successfully.\nNow you can enter the robot and start it.";
    die();

}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Installation Form</title>
    <style>
        #adminsContainer div,
        #channelsContainer div {
            margin-bottom: 10px;
        }

        button {
            margin: 10px 0px;
        }
    </style>
</head>
<body>
<h2>To complete the installation, please fill out the form below:</h2>

<ul>
    <li>It is possible to edit the information of this form manually through the settings.js file.</li>
    <li>The information in this form is not verified and if they are entered incorrectly, the robot will encounter problems.</li>
    <li>The webhook is also registered through this form and you do not need to register webhook.</li>
    <li>The bot must be registered in all registered channels.</li>
    <li>The bot is automatically turned off at first and only registered admins have access to use the bot.</li>

</ul>

<form method="post">
    <label for="botToken">Bot Token:</label>
    <input type="text" id="botToken" name="botToken" required><br><br>

    <div id="adminsContainer">
        <div id="admin1">
            <label for="adminId1">Admin Id:</label>
            <input type="number" id="adminId1" name="admins[0][id]" required>
        </div>
    </div>
    <button type="button" onclick="addAdminField()">Add More Admin</button>
    <br>

    <div id="channelsContainer">
        <div id="channel1">
            <label for="channelId1">Channel Id:</label>
            <input type="number" id="channelId1" name="channels[0][id]" required>
            <label for="channelTitle1">Channel Title:</label>
            <input type="text" id="channelTitle1" name="channels[0][title]" required>
            <label for="channelLink1">Channel Link:</label>
            <input type="url" id="channelLink1" name="channels[0][link]" required>
        </div>
    </div>
    <button type="button" onclick="addChannelField()">Add More Channel</button>
    <br>

    <input type="submit" name="submit" value="Submit">
</form>

<script>
    let adminCount = 1;
    let channelCount = 1;

    function addAdminField() {
        adminCount++;
        const container = document.getElementById('adminsContainer');
        const newDiv = document.createElement('div');
        newDiv.id = `admin${adminCount}`;
        newDiv.innerHTML = `
                <label for="adminId${adminCount}">Admin Id:</label>
                <input type="number" id="adminId${adminCount}" name="admins[${adminCount - 1}][id]" required>
            `;
        container.appendChild(newDiv);
        const addButton = document.querySelector('#adminsContainer button');
        if (addButton) {
            addButton.remove();
        }
    }

    function addChannelField() {
        channelCount++;
        const container = document.getElementById('channelsContainer');
        const newDiv = document.createElement('div');
        newDiv.id = `channel${channelCount}`;
        newDiv.innerHTML = `
                <label for="channelId${channelCount}">Channel Id:</label>
                <input type="number" id="channelId${channelCount}" name="channels[${channelCount - 1}][id]" required>
                <label for="channelTitle${channelCount}">Channel Title:</label>
                <input type="text" id="channelTitle${channelCount}" name="channels[${channelCount - 1}][title]" required>
                <label for="channelLink${channelCount}">Channel Link:</label>
                <input type="url" id="channelLink${channelCount}" name="channels[${channelCount - 1}][link]" required>
            `;
        container.appendChild(newDiv);
        const addButton = document.querySelector('#channelsContainer button');
        if (addButton) {
            addButton.remove();
        }
    }
</script>
</body>
</html>
