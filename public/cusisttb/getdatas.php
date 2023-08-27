
<?php

ini_set('max_execution_time', '0');

$courselist = json_decode(file_get_contents(__DIR__ . '/datas/courselist.json'), true);
$outputdatas = [];
$successinput = [];
$errorcourse = [];
$imgparam = [];
$starttime = microtime(true);
$host = 'https://rgsntl.rgs.cuhk.edu.hk/aqs_prd_applx/Public/tt_dsp_crse_catalog.aspx';
date_default_timezone_set('Asia/Hong_Kong');

if (!isset($_REQUEST['subject']) || !isset($_REQUEST['class'])) {
    header('HTTP/1.0 403 Forbidden');
    echo 'No Permission\n';
    foreach ($courselist as $subjectcode => $coursedetails) {
        echo "Course [" . $subjectcode . "]: \n";
        foreach ($coursedetails["classes"] as $classdetails)
            echo "  " . $classdetails["code"] . " - " . $classdetails["name"] . "\n";
    }
    die();
}

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

$_REQUEST['subject'] = preg_replace("/[^a-zA-Z]+/", "",strtoupper($_REQUEST['subject']));
$_REQUEST['class'] = preg_replace("/[^0-9]+/", "",strtoupper($_REQUEST['class']));

if (isset($courselist[$_REQUEST['subject']])) {
    $subjectcode = $_REQUEST['subject'];
    $coursedetails = $courselist[$_REQUEST['subject']];
} else
    die("Subject Not Found");

$searchclasscode = array_filter($courselist[$_REQUEST['subject']]["classes"], function ($value, $index) {
    if ($value["code"] == $_REQUEST['class']) return $index;
}, ARRAY_FILTER_USE_BOTH);

if (count($searchclasscode) == 0)
    die("Class Not Found");
else {
    $eventtarget = array_keys($searchclasscode)[0];
    $classdetails = $coursedetails["classes"][array_keys($searchclasscode)[0]];
}



$ch = curl_init();
curl_setopt($ch, CURLOPT_URL, $host);
curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
curl_setopt($ch, CURLOPT_CUSTOMREQUEST, 'POST');
curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
curl_setopt($ch, CURLOPT_HTTPHEADER, $browserheader);
$coursedetails["input"]["__EVENTTARGET"] = str_replace(
    '_lbtn_course_nbr',
    '$lbtn_course_nbr',
    str_replace(
        'gv_detail_ctl',
        'gv_detail$ctl',
        $eventtarget
    )
);
$request = http_build_query($coursedetails["input"]);
curl_setopt($ch, CURLOPT_POST, 1);
curl_setopt($ch, CURLOPT_POSTFIELDS, $request);
$html = curl_exec($ch);
curl_close($ch);


//Get each class status from html
$classdetails = [];
$coursecode = $courselist[$subjectcode]["classes"][$eventtarget]["code"];
$classdetails["name"] = $courselist[$subjectcode]["classes"][$eventtarget]["name"];


if ($html != "") {
    $dom = new DOMDocument();
    @$dom->loadHTML($html);
    $coursehtml = new DOMXPath($dom);
    $coursestatustable = $coursehtml->query('//tr[@class="normalGridViewRowStyle"]');
    if (count($coursestatustable) > 0) {
        foreach ($coursestatustable as $tablerow) {
            $classstatussrc = $coursehtml->evaluate('.//img[contains(@id, "_img_status")]', $tablerow);
            if ($classstatussrc->length > 0) {
                $classcode = $coursehtml->evaluate('.//a[contains(@id, "_lkbtn_class_section")]', $tablerow)->item(0)->nodeValue;
                $classstatussrc = $coursehtml->evaluate('.//img[contains(@id, "_img_status")]', $tablerow)->item(0)->getAttribute('src');
                $classstatus =  strpos($classstatussrc, "open") !== false ? "Open" : (strpos($classstatussrc, "closed") !== false ? "Closed" : (strpos($classstatussrc, "wait") !== false ? "Waitlist" : "Error"));
                $classdetails["status"][$classcode] = $classstatus;
                echo "<pre>";
            }
        }
        print_r(json_encode($classdetails, JSON_PRETTY_PRINT));
    } else
        echo "No Status Available";
} else {
    $errorcourse[$subjectcode][] = $coursecode;
    echo "ERROR on " . $subjectcode . ": Blank Output";
}
