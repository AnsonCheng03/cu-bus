<?php

/*
if (isset($_SERVER['REMOTE_ADDR'])) {
    header('HTTP/1.0 403 Forbidden');
    die('No Permission');
}
*/

ini_set('max_execution_time', '3600');

$outputdatas = [];
$imgparam = [];
$blankcourse = [];
$successinput = [];
$errorcourse = [];
$starttime = microtime(true);
$host = 'https://rgsntl.rgs.cuhk.edu.hk/aqs_prd_applx/Public/tt_dsp_crse_catalog.aspx';
date_default_timezone_set('Asia/Hong_Kong');

$html = file_get_contents($host, 0, stream_context_create(["http" => ["timeout" => 20]]));
if ($html === false) die('fetch');

//Get Input Fields
$dom = new DOMDocument();
libxml_use_internal_errors(true);
$dom->loadHTML($html);
foreach ((new DOMXPath($dom))->query('//input') as $html) {
    try {
        //Save to Array
        $outputdatas[$html->getAttribute('name')] = $html->getAttribute('value');
    } catch (Exception $e) {
    }
}
$outputdatas["__EVENTTARGET"] = "";
$outputdatas["__EVENTARGUMENT"] = "";
unset($outputdatas["btn_refresh"]);

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


//Get Subject List
foreach ((new DOMXPath($dom))->query('//option') as $html) {
    if ($html->getAttribute('value'))
        $courses[$html->getAttribute('value')] = $html->textContent;
}

//Get Verification Code Image
$imgurl = explode("?", (new DOMXPath($dom))->evaluate('//img[@id="imgCaptcha"]')->item(0)->getAttribute('src'));
foreach (explode("&", $imgurl[1]) as $param) {
    $imgparam[explode("=", $param)[0]] = explode("=", $param)[1];
}
$imgparam["len"] = 1;
$veriaddr = $imgurl[0] . "?captchaname=" . $imgparam["captchaname"] . "&len=" . $imgparam["len"];
$ch = curl_init();
curl_setopt($ch, CURLOPT_URL, dirname($host) . "/" . $veriaddr);
curl_setopt($ch, CURLOPT_CUSTOMREQUEST, 'GET');
curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
curl_setopt($ch, CURLOPT_HTTPHEADER, $browserheader);
$response = curl_exec($ch);
curl_close($ch);

//Force-brute Veri code
$ch = curl_init();
curl_setopt($ch, CURLOPT_URL, $host);
curl_setopt($ch, CURLOPT_CUSTOMREQUEST, 'POST');
curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
curl_setopt($ch, CURLOPT_HTTPHEADER, $browserheader);
curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
$verilist = array_merge(range('A', 'Z'), range('0', '9'));
$vericount = 0;
$outputdatas["ddl_subject"] = array_keys($courses)[0];
do {
    $outputdatas["txt_captcha"] = $verilist[$vericount];
    $request = http_build_query($outputdatas);
    curl_setopt($ch, CURLOPT_POST, 1);
    curl_setopt($ch, CURLOPT_POSTFIELDS, $request);
    $response = curl_exec($ch);
    if ($vericount >= count($verilist) - 1)
        die("Verification Failed (Txt_Captcha not in range (A-Z, 0-9)");
    else
        $vericount++;
} while (strpos($response, 'Invalid Verification Code') !== false);
$vericode = $verilist[$vericount - 1];
curl_close($ch);


//Get All courses by search
$mh = curl_multi_init();
$outputdatas["txt_captcha"] = $vericode;
$curlarray = [];

foreach ($courses as $subjectcode => $coursename) {
    $curlarray[$subjectcode] = curl_init();
    curl_setopt($curlarray[$subjectcode], CURLOPT_URL, $host);
    curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
    curl_setopt($curlarray[$subjectcode], CURLOPT_CUSTOMREQUEST, 'POST');
    curl_setopt($curlarray[$subjectcode], CURLOPT_RETURNTRANSFER, 1);
    curl_setopt($curlarray[$subjectcode], CURLOPT_HTTPHEADER, $browserheader);
    $outputdatas["ddl_subject"] = $subjectcode;
    $request = http_build_query($outputdatas);
    curl_setopt($curlarray[$subjectcode], CURLOPT_POST, 1);
    curl_setopt($curlarray[$subjectcode], CURLOPT_POSTFIELDS, $request);
    curl_multi_add_handle($mh, $curlarray[$subjectcode]);
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

foreach ($curlarray as $subjectcode => $ch) {
    $html = curl_multi_getcontent($ch);
    if ($html != "" && curl_error($ch) == "") {
        $coursecount = substr_count($html, 'normalGridViewRowStyle') + substr_count($html, 'normalGridViewAlternatingRowStyle');
        if ($coursecount) {
            $dom = new DOMDocument();
            libxml_use_internal_errors(true);
            $dom->loadHTML($html);
            $coursehtml = new DOMXPath($dom);

            //Get Class List
            $courseno = 2;
            $coursedetailsbycode[$subjectcode]["count"] = $coursecount;
            foreach ($coursehtml->query('//tr[@class="normalGridViewRowStyle" or @class="normalGridViewAlternatingRowStyle"]') as $tablerow) {
                try {
                    $coursecode = $coursehtml->evaluate('.//a[contains(@id, "_lbtn_course_nbr")]', $tablerow)->item(0);
                    $coursedetailsbycode[$subjectcode]["classes"][$coursecode->getAttribute('id')] = array (
                        "code" => $coursecode->nodeValue,
                        "name" => $coursehtml->evaluate('.//a[contains(@id, "_lbtn_course_title")]', $tablerow)->item(0)->nodeValue
                    );
                    $courseno++;
                } catch (Exception $e) {
                }
            }

            //Get POST body
            foreach ($coursehtml->query('//input') as $html) {
                $coursedetailsbycode[$subjectcode]["input"][$html->getAttribute('name')] = $html->getAttribute('value');
            }
            unset($coursedetailsbycode[$subjectcode]["input"]["btn_search"]);
            unset($coursedetailsbycode[$subjectcode]["input"]["btn_refresh"]);
            $coursedetailsbycode[$subjectcode]["input"]["__EVENTARGUMENT"] = '';
            $coursedetailsbycode[$subjectcode]["input"]["ddl_subject"] = $subjectcode;
            $successinput[] = $subjectcode;
        } else {
            $blankcourse[] = $subjectcode;
        }
    } else {
        $errorcourse[] = $subjectcode;
        echo "\e[1mERROR\e[0m on " . $subjectcode . ": " . (curl_error($ch) ?: "Blank Output\n");
    }
    curl_multi_remove_handle($mh, $ch);
}
curl_multi_close($mh);

if (count($errorcourse) > 0 || count($blankcourse) + count($successinput) != count($curlarray)) {
    file_put_contents(__DIR__ . '/datas/courselist_error.json', json_encode($coursedetailsbycode, JSON_PRETTY_PRINT));
    echo "ERROR!\n";
} else {
    file_put_contents(__DIR__ . '/datas/courselist.json', json_encode($coursedetailsbycode, JSON_PRETTY_PRINT));
    echo "Complete!\n";
}
echo "All Subject(\e[1m" . count($curlarray) . "\e[0m): " . implode(", ", array_keys($curlarray)) . "\n";
echo "Imported Subject(\e[1m" . count($successinput) . "\e[0m): " . implode(", ", $successinput) . "\n";
echo "Blank Subject(\e[1m" . count($blankcourse) . "\e[0m): " . implode(", ", $blankcourse) . "\n";
echo "Error Subject(\e[1m" . count($errorcourse) . "\e[0m): " . implode(", ", $errorcourse) . "\n";
echo "Total Run Time: \e[1m" . (microtime(true) - $starttime) . "\e[0m seconds.";
