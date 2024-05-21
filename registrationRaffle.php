<?php
/* 
DEVELOPED BY MortezaVaei
Telegram Username : @MortezaVaezi_ir,
Site URL : mortezavaezi.ir
*/

include "tempDatasManager.php";
$participants = new TempDatasManager("participants.json");
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
      background-color: #87CEEB; /* پس‌زمینه رنگ آبی آسمانی */
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
  <h2 class="text-center">قرعه کشی</h2>
  <form method="post">
    <div class="form-group">
        <h5 for="number" style="float: inline-start;">تعداد کل شرکت کنندگان: <?php echo count($participants->getAllData()); ?></h5><br><hr><br>
      <label for="number" style="float: inline-start;">تعداد برندگان قرعه کشی:</label>
      <input type="number" class="form-control" name="number" min="1" max="<?php
      echo count($participants->getAllData()); ?>" required>
    </div>
    <button type="submit" name="btn" class="btn btn-primary btn-block">قرعه کشی نفرات</button>
  </form>
  
  <?php
  if(isset($_POST['btn'])){
      $result = getRandomValuesFromArray($participants->getAllData(),$_POST['number']);
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
          $data = $per['data'];
          if(isset($data['username'])){
              $u = "@{$data['username']}";
          }else{
              $l = (isset($data['last_name'])?$data['last_name']:"");
              $u = "{$data['first_name']} {$l}";
          }
          ?>
          <tr>
            <td><?php echo $data['id']; ?></td>
            <td><?php echo $u; ?></td>
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
