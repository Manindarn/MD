<?php

trait HelperFunctions
{

    private $config = CONFIG;
    private static $instance;

    public static function getInstance()
    {
        if (!(self::$instance instanceof self)) {
            self::$instance = new self;
        }
        return self::$instance;
    }

    public function getConfig($firstKey = null, $secondKey = null)
    {
        return $firstKey ? ($secondKey ? ($this->config[$firstKey][$secondKey] ?? null) : ($this->config[$firstKey] ?? null)) : $this->config;
    }

    public function generateHashCode($string)
    {
        return md5($string . '-' . microtime());
    }

    public function generateRandomOTP()
    {
        return mt_rand(100000, 999999);
    }

    public function cleanMobile($mob)
    {
        return preg_replace("/^\+91| /", "", $mob);
    }

    public function array_merge_recursive2($arr1, $arr2)
    {
        if (!is_array($arr1) or !is_array($arr2)) {return $arr2;}
        foreach ($arr2 as $sKey2 => $sValue2) {
            $arr1[$sKey2] = $this->array_merge_recursive2(@$arr1[$sKey2], $sValue2);
        }
        return $arr1;
    }

    public function arrayFilterByKeys($array, $keys)
    {
        return array_filter($array, function ($key) use ($keys) {return in_array($key, $keys);}, ARRAY_FILTER_USE_KEY);
    }

    public function getFormatedDate($dataData = null, $format = 'Y-m-d')
    {
        return (empty($dataData) || ($dataData == '0000-00-00') || ($dataData == '0000-00-00 00:00:00')) ? date($format) : date($format, strtotime($dataData));
    }

    public function getPrintStatus($result, $name = 'test', $returnData = [])
    {
        $data = [];
        $resultCodes = isset($result['errorCode']) ? array_count_values([$result['errorCode']]) : array_count_values(array_column($result, 'errorCode'));
        $desc = [1 => 'already exist', 2 => 'successfull inserts', 3 => 'not successfull inserts'];
        foreach ($desc as $key => $values) {
            $data[$values] = isset($resultCodes[$key]) ? $resultCodes[$key] : 0;
        }
        if (isset($result['errorCode']) && ($result['errorCode'] == 1) && isset($result['val']['_id'])) {
            $data['_id'] = $result['val']['_id'];
        }
        $returnData[$name] = $data;
        return $returnData;
    }

    public function getTodayDateTime()
    {
        return Date(DATETIME_FORMAT);
    }

    public function triggerError($data, $portingLogObject = null)
    {
        error_log(json_encode($data));

        if (!is_null($portingLogObject)) {

            $_id = $portingLogObject->newMongoId();

            $insertData = [
                "_id" => $_id,
                "message" => $data['message'] ?? null,
                "docID" => $data['docID'] ?? null,
                'createdAt' => date(DATETIME_FORMAT),
            ];

            $portingLogObject->insert($insertData);
        }
        header('Content-Type: application/json');
        // echo json_encode(['status' => 'error', 'msg' => $data['message']]);
        // exit();
    }

    public function cleanUpArray(&$array, $keys = [])
    {
        if (!empty($keys)) {
            foreach ($keys as $key) {
                if (isset($array[$key])) {
                    $array[$key] = $this->cleanUp($array[$key]);
                }
            }
        } else {
            foreach ($array as $key => &$value) {
                $value = $this->cleanUp($value);
            }
        }

    }
    public function cleanUp($str)
    {

        $str = strtr($str, [
            "ร—" => "&#215;",
            "รท" => "&#247;",
            "°" => "&deg;",
            "`" => "'",
            "÷" => "&divide;",
            "–" => "-",
            "‘" => "'",
            "×" => "&times;",
            "²" => "&sup2;",
            "»" => "&#187;",
            "«" => "&#171;",
        ]);

        $map = array(
            chr(0x8A) => chr(0xA9),
            chr(0x8C) => chr(0xA6),
            chr(0x8D) => chr(0xAB),
            chr(0x8E) => chr(0xAE),
            chr(0x8F) => chr(0xAC),
            chr(0x9C) => chr(0xB6),
            chr(0x9D) => chr(0xBB),
            chr(0xA1) => chr(0xB7),
            chr(0xA5) => chr(0xA1),
            chr(0xBC) => chr(0xA5),
            chr(0x9F) => chr(0xBC),
            chr(0xB9) => chr(0xB1),
            chr(0x9A) => chr(0xB9),
            chr(0xBE) => chr(0xB5),
            chr(0x9E) => chr(0xBE),
            chr(0x80) => '&euro;',
            chr(0x82) => '&sbquo;',
            chr(0x84) => '&bdquo;',
            chr(0x85) => '&hellip;',
            chr(0x86) => '&dagger;',
            chr(0x87) => '&Dagger;',
            chr(0x89) => '&permil;',
            chr(0x8B) => '&lsaquo;',
            chr(0x91) => '&lsquo;',
            chr(0x92) => '&rsquo;',
            chr(0x93) => '&ldquo;',
            chr(0x94) => '&rdquo;',
            chr(0x95) => '&bull;',
            chr(0x96) => '&ndash;',
            chr(0x97) => '&mdash;',
            chr(0x99) => '&trade;',
            chr(0x9B) => '&rsquo;',
            chr(0xA6) => '&brvbar;',
            chr(0xA9) => '&copy;',
            chr(0xAB) => '&laquo;',
            chr(0xAE) => '&reg;',
            chr(0xB1) => '&plusmn;',
            chr(0xB5) => '&micro;',
            chr(0xB6) => '&para;',
            chr(0xB7) => '&middot;',
            chr(0xBB) => '&raquo;',
        );
        $str = mb_convert_encoding(strtr($str, $map), 'UTF-8', "ISO-8859-1");

        //&#8658; &#8660; &#945; &#946; &#947; &#916; &#948; &#952; &#955;  &#963; &#8721; &#8730; &#8743; &#8731; &#8736; &#8804; &#8805; » « &#8834; &#8836; &#9830; ÷ × || &#8869; &#8773; &#8800; &#8712; &#8713; &#8746; &#8745; &#8764; ° Sup ² Sup ³ &#8756; &#8757; &#8776;
        //⇒ ⇔ α β γ Δ δ θ λ σ ∑ √ ∧ ∛ ∠ ≤ ≥ � � ⊂ ⊄ ♦ � � || ⊥ ≅ ≠ ∈ ∉ ∪ ∩ ∼ � Sup � Sup � ∴ ∵ ≈

        $encoding = 'UTF-8';
        return $str;
        //return stripcslashes(htmlentities($str, ENT_COMPAT, $encoding));
    }

    public function getFromGlobal($key, $defaultValue = null)
    {
        return $GLOBALS[$key] ?? $defaultValue;
    }
    
    public function setToGlobal($key, $value)
    {
        $GLOBALS[$key] = $value;
    }
}
