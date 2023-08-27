
<?php

error_reporting(E_ALL);
ini_set('display_errors', 1);

/*
if (isset($_SERVER['REMOTE_ADDR'])) {
    header('HTTP/1.0 403 Forbidden');
    die('No Permission');
}
*/

ini_set('max_execution_time', '0');

$courselist = json_decode(file_get_contents(__DIR__ . '/datas/courselist.json'), true);
$outputdatas = [];
$successinput = [];
$errorcourse = [];
$imgparam = [];
$starttime = microtime(true);
$host = 'https://rgsntl.rgs.cuhk.edu.hk/aqs_prd_applx/Public/tt_dsp_crse_catalog.aspx';
date_default_timezone_set('Asia/Hong_Kong');

//Get Cookie & Init Fetch
$ch = curl_init($host);
curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
curl_setopt($ch, CURLOPT_HEADER, 1);
$result = curl_exec($ch);
preg_match_all('/^Set-Cookie:\s*([^;]*)/mi', $result, $matches);
$cookies = array();
foreach ($matches[1] as $item) {
    parse_str($item, $cookie);
    $cookies = array_merge($cookies, $cookie);
}
$SESSIONID = $cookies["ASP_NET_SessionId"];
$browserheader = [
    'Cookie: PS_DEVICEFEATURES=maf:0 width:1728 height:1117 clientWidth:1200 clientHeight:871 pixelratio:2 touch:0 geolocation:1 websockets:1 webworkers:1 datepicker:1 dtpicker:1 timepicker:1 dnd:1 sessionstorage:1 localstorage:1 history:1 canvas:1 svg:1 postmessage:1 hc:0; ASP.NET_SessionId=' . $SESSIONID,
];

//Add all courses to curl list
$mh = curl_multi_init();
foreach ($courselist as $subjectcode => $coursedetails) {
    foreach ($coursedetails["classes"] as $eventtarget => $classdetails) {
        $curlarray[$subjectcode][$eventtarget] = curl_init();
        curl_setopt($curlarray[$subjectcode][$eventtarget], CURLOPT_URL, $host);
        curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
        curl_setopt($curlarray[$subjectcode][$eventtarget], CURLOPT_CUSTOMREQUEST, 'POST');
        curl_setopt($curlarray[$subjectcode][$eventtarget], CURLOPT_RETURNTRANSFER, 1);
        curl_setopt($curlarray[$subjectcode][$eventtarget], CURLOPT_HTTPHEADER, $browserheader);
        $coursedetails["input"]["__EVENTTARGET"] = str_replace(
            '_lbtn_course_nbr','$lbtn_course_nbr', str_replace(
            'gv_detail_ctl','gv_detail$ctl', $eventtarget
        ));
        $request = http_build_query($coursedetails["input"]);
        curl_setopt($curlarray[$subjectcode][$eventtarget], CURLOPT_POST, 1);
        curl_setopt($curlarray[$subjectcode][$eventtarget], CURLOPT_POSTFIELDS, $request);
        curl_multi_add_handle($mh, $curlarray[$subjectcode][$eventtarget]);
    }
}

$active = null;
do {
    $mrc = curl_multi_exec($mh, $active);
} while ($mrc == CURLM_CALL_MULTI_PERFORM);

while ($active && $mrc == CURLM_OK) {
    if (curl_multi_select($mh) != -1) {
        do {
            $mrc = curl_multi_exec($mh, $active);
        } while ($mrc == CURLM_CALL_MULTI_PERFORM);
    }
}

if ($mrc != CURLM_OK) {
    echo ("Curl multi read error " . $mrc . "\n");
}

//Get each class status from html
$classdetails = [];
foreach ($curlarray as $subjectcode => $course) {
    foreach ($course as $curlevent => $ch) {
        $coursecode = $courselist[$subjectcode]["classes"][$curlevent]["code"];
        $classdetails[$subjectcode][$coursecode]["Details"] = array(
            "code" => $courselist[$subjectcode]["classes"][$curlevent]["code"],
            "name" => $courselist[$subjectcode]["classes"][$curlevent]["name"]
        );

        $html = curl_multi_getcontent($ch);
        if ($html != "" && curl_error($ch) == "") {
            $dom = new DOMDocument();
            @$dom->loadHTML($html);
            $coursehtml = new DOMXPath($dom);
            foreach ($coursehtml->query('//tr[@class="normalGridViewRowStyle"]') as $tablerow) {
                try {
                    $classstatussrc = $coursehtml->evaluate('.//img[contains(@id, "_img_status")]', $tablerow);
                    if ($classstatussrc->length > 0) {
                        $classcode = $coursehtml->evaluate('.//a[contains(@id, "_lkbtn_class_section")]', $tablerow)->item(0)->nodeValue;
                        $classstatussrc = $coursehtml->evaluate('.//img[contains(@id, "_img_status")]', $tablerow)->item(0)->getAttribute('src');
                        $classstatus =  strpos($classstatussrc, "open") !== false ? "Open" : (strpos($classstatussrc, "closed") !== false ? "Closed" : (strpos($classstatussrc, "wait") !== false ? "Waitlist" : "Error"));
                        $classdetails[$subjectcode][$coursecode]["status"][$classcode] = $classstatus;
                        $successinput[$subjectcode][$coursecode][] = $classcode;
                    }
                } catch (Exception $e) {
                }
            }
        } else {
            $errorcourse[$subjectcode][] = $coursecode;
            echo "ERROR on " . $subjectcode . ": " . (curl_error($ch) ?: "Blank Output");
        }
    }
}
curl_multi_close($mh);



if (count($errorcourse) > 0) {
    file_put_contents(__DIR__ . '/datas/classdatas_error.json', json_encode($classdetails, JSON_PRETTY_PRINT));
    echo "ERROR!\n";
} else {
    file_put_contents(__DIR__ . '/datas/classdatas.json', json_encode($classdetails, JSON_PRETTY_PRINT));
    echo "Complete!\n";
}
echo "Imported Subject(\e[1m" . count($successinput) . "\e[0m): " . print_r($successinput,true) . "\n";
echo "Error Subject(\e[1m" . count($errorcourse) . "\e[0m): " . print_r($errorcourse,true) . "\n";
echo "Total Run Time: \e[1m" . (microtime(true) - $starttime) . "\e[0m seconds.";

