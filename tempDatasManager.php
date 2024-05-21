<?php
/* 
DEVELOPED BY MortezaVaei
Telegram Username : @MortezaVaezi_ir,
Site URL : mortezavaezi.ir
*/

class TempDatasManager
{
    private $filename;

    public function __construct($filename)
    {
        if (!file_exists($filename)) {
            file_put_contents($filename,json_encode(array()));
        }
        $this->filename = $filename;
    }

    public function createOrOpenTempDatasFile($dataArray, $arrayName)
    {
        if (!file_exists($this->filename)) {
            $data = array();
        } else {
            $data = json_decode(file_get_contents($this->filename), true);
        }

        $data[$arrayName] = [
            'data' => $dataArray,
            'timestamp' => time(),
        ];

        file_put_contents($this->filename, json_encode($data));
    }

    public function getArrayByKey($arrayName)
    {
        if (file_exists($this->filename)) {
            $data = json_decode(file_get_contents($this->filename), true);
    
            if (isset($data[$arrayName])) {
                return $data[$arrayName]['data'];
            }
        }
    
        return false;
    }
    public function getTimeStampByKey($arrayName)
    {
        if (file_exists($this->filename)) {
            $data = json_decode(file_get_contents($this->filename), true);

            if (isset($data[$arrayName])) {
                return $data[$arrayName]['timestamp'];
            }
        }

        return false;
    }
    public function deleteArrayByKey($arrayName)
    {
        if (file_exists($this->filename)) {
            $data = json_decode(file_get_contents($this->filename), true);

            if (isset($data[$arrayName])) {
                unset($data[$arrayName]);
                file_put_contents($this->filename, json_encode($data));
            }
        }
    }
    
    public function updateArrayByKey($newDataArray, $arrayName)
    {
            $this->createOrOpenTempDatasFile($newDataArray, $arrayName);
    }
    
    public function deleteOldArrays($hour)
    {
        if (file_exists($this->filename)) {
            $data = json_decode(file_get_contents($this->filename), true);

            $currentTimestamp = time();
            $threeHoursAgo = $currentTimestamp - ($hour * 3600);

            foreach ($data as $arrayName => $arrayData) {
                if ($arrayData['timestamp'] < $threeHoursAgo) {
                    unset($data[$arrayName]);
                }
            }

            file_put_contents($this->filename, json_encode($data));
        }
    }

    public function getAllData()
    {
        if (file_exists($this->filename)) {
            $data = json_decode(file_get_contents($this->filename), true);
            return $data;
        }
        return false;
    }
}

?>