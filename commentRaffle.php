<?php
/* 
DEVELOPED BY MortezaVaei
Telegram Username : @MortezaVaezi_ir,
Site URL : mortezavaezi.ir
*/

include 'vendor/autoload.php';
$MadelineProto = new \danog\MadelineProto\API('session.madeline');
$MadelineProto->start();
function getRandomValuesFromArray($array, $count) {
    $result = [];
    $arrayCount = count($array);
    
    if ($count > $arrayCount) {
        $count = $arrayCount;
    }
    
    $keys = array_rand($array, $count);
    
    if ($count == 1) {
        $result[] = $array[$keys];
    } else {
        foreach ($keys as $key) {
            $result[] = $array[$key];
        }
    }
    
    return $result;
}
?>

<!DOCTYPE html>
<html lang="fa" dir="rtl">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>قرعه کشی</title>
  <link rel="stylesheet" href="https://maxcdn.bootstrapcdn.com/bootstrap/4.5.2/css/bootstrap.min.css">
  <style>
    body {
      background-color: #FFC0CB	; /* پس‌زمینه رنگ آبی آسمانی */
    }
    
    .container {
      background-color: #ffffff; /* پس‌زمینه رنگی بخش فرم */
      border-radius: 10px;
      padding: 20px;
      box-shadow: 0 0 10px rgba(0, 0, 0, 0.1); /* سایه */
    }
    
    .table-responsive {
      overflow-x: auto;
    }
    
    th, td {
      text-align: center; /* متون در وسط ستون */
    }
  </style>
</head>
<body>

<div class="container mt-5">
  <h2 class="text-center">قرعه کشی کامنتی</h2>
  <form method="post">
    <div class="form-group">
        <h5 for="number" style="float: inline-start;">تعداد کل شرکت کنندگان: <?php echo count($_GET['fromIds']); ?></h5><br><hr><br>
      <label for="number" style="float: inline-start;">تعداد برندگان قرعه کشی:</label>
      <input type="number" class="form-control" name="number" min="1" max="<?php
      echo count($_GET['fromIds']); ?>" required>
    </div>
    <button type="submit" name="btn" class="btn btn-danger btn-block">قرعه کشی نفرات</button>
  </form>
  
  <?php
  if(isset($_POST['btn'])){
      $result = getRandomValuesFromArray($_GET['fromIds'],$_POST['number']);
  ?>
  <div class="mt-5">
    <h3 class="text-center mb-3">برندگان</h3>
    <div class="table-responsive">
      <table class="table table-bordered" style="direction: ltr;">
        <thead>
          <tr>
            <th>آیدی برنده</th>
            <th>نام کاربری برنده</th>
          </tr>
        </thead>
        <tbody>
          <?php foreach($result as $per){ 
          $per = $MadelineProto->getPwrChat($per);
          if(isset($per['username'])){
              $u = "@{$per['username']}";
          }else{
              $l = (isset($per['last_name'])?$per['last_name']:"");
              $u = "{$per['first_name']} {$l}";
          }
          ?>
          <tr>
            <td><?php echo $per['id']; ?></td>
            <td><a href="tg://openmessage?user_id=<?php echo $per['id']; ?>"><?php echo $u; ?></a></td>
          </tr>
          <?php } ?>
        </tbody>
      </table>
    </div>
  </div>
  <?php } ?>
</div>

</body>
</html>
