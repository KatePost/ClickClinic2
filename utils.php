<?php

require_once 'vendor/autoload.php';

require_once 'init.php';

use Respect\Validation\Validator as v; //don't understand why i need this here

use \Firebase\JWT\JWT;
use GuzzleHttp\Client;
 
define('ZOOM_API_KEY', 'removed');
define('ZOOM_SECRET_KEY', 'removed');

function getZoomAccessToken() {
    $key = ZOOM_SECRET_KEY;
    $payload = array(
        "iss" => ZOOM_API_KEY,
        'exp' => time() + 3600,
    );
    return JWT::encode($payload, $key);    
}

function getZoomMeeting($meetingId) {
    if($meetingId == null){
        return false;
    }
    $client = new Client([
        // Base URI is used with relative requests
        'base_uri' => 'https://api.zoom.us/v2/',
    ]);
 
    $response = $client->request('GET', 'meetings/' . $meetingId, [
        "headers" => [
            "Authorization" => "Bearer " . getZoomAccessToken()
        ]
    ]);

    if($response->getStatusCode() != 200){
        return false;
    }
 
    $data = json_decode($response->getBody());
    if (!empty($data) ) {
        return ['start_url' => $data->start_url, 'start_time' => $data->start_time, 'join_url' => $data->join_url, 'password' => $data->password];
    }else{
        return false;
    }
}
 
function createZoomMeeting($start_time, $topic)
{
    $client = new Client([
        // Base URI is used with relative requests
        'base_uri' => 'https://api.zoom.us',
    ]);

    $response = $client->request('POST', '/v2/users/me/meetings', [
        "headers" => [
            "Authorization" => "Bearer " . getZoomAccessToken()
        ],
        'json' => [
            "timezone" => "America/Montreal",
            "topic" => $topic,
            "type" => 2,
            "start_time" => $start_time,
            "duration" => "30",
            "password" => "123456"
        ],
    ]);

    $data = json_decode($response->getBody());
    return ['url' => $data->join_url, 'password' => $data->password, 'meetingId' => $data->id];
}

function verifyUploadedFile($file, &$fileName) {

    if ($file['error'] != UPLOAD_ERR_OK) {
        return "Error uploading file ";
    }
    if ($file['size'] > 3*1024*1024) { // 2MB
        return "File too big. 3MB max is allowed.";
    }

    // $info = getimagesize($file['tmp_name']);
    // print_r($info);


    //if file is an image
    // if ($info) {
    //     if ($info[0] < 1000 || $info[0] > 1000 || $info[1] < 1000 || $info[1] > 1000) {
    //         return "Width and height must be within 200-1000 pixels range";
    //     }
    // }

    $ext = "";
    switch ($file['type']) {
        case 'image/jpeg': $ext = "jpg"; break;
        case 'application/pdf': $ext = "pdf"; break;
        case 'image/png': $ext = "png"; break;
        default:
            return "Only JPG, PDF and PNG file types are allowed";
    }
    $filenameWithoutExtension = pathinfo($file['name'], PATHINFO_FILENAME);
    // Note: keeping the original extension is dangerious and would allow for code injection - very dangerous
    $sanitizedFileName = mb_ereg_replace('([^A-Za-z0-9_-])', '_', $filenameWithoutExtension);
    $fileName = $sanitizedFileName . "." . $ext;
    return TRUE;
}


function setFlashMessage($message, $status = "") {
    $_SESSION['flashStatus'] = $status;
    $_SESSION['flashMessage'] = $message;
} 

// returns empty string if no message, otherwise returns string with message and clears is
function getAndClearFlashMessage() {
   if (isset($_SESSION['flashMessage'])) {
       $message = $_SESSION['flashMessage'];
       unset($_SESSION['flashMessage']);
       return $message;
   }
   return "";
}

function getAndClearFlashStatus() {
    if (isset($_SESSION['flashStatus'])) {
        $status = $_SESSION['flashStatus'];
        unset($_SESSION['flashStatus']);
        return $status;
    }
    return "";
 }

function verifyPasswordQuality($pass1, $pass2) {
    if ($pass1 != $pass2) {
        return "Passwords do not match";
    } else {
        if (
            strlen($pass1) < 6 || strlen($pass1) > 100
            || (preg_match("/[A-Z]/", $pass1) !== 1)
            || (preg_match("/[a-z]/", $pass1) !== 1)
            || (preg_match("/[0-9]/", $pass1) !== 1)
        ) {
            return "Password must be 6-100 characters long and contain at least one "
                . "uppercase letter, one lowercase, and one digit.";
        }
    }
    return TRUE;
}

function verifyHealthCardNo($healthCardNo) {
    if (preg_match('/^[A-Z]{4}[0-9]{8}$/', $healthCardNo) !== 1) {
        return"Health card number should begin with 4 uppercase letters followed by 8 digits.";
    }
    return TRUE;
}

function verifyPhoneNo($phone) {
    if (preg_match('/^[\d]{10}$/', $phone) !== 1) {
        return "Phone number should only be 10 digits";
    }
    return TRUE;
}

function verifyEmailQuality($email) {
    if (filter_var($email, FILTER_VALIDATE_EMAIL) === false) {
        return "Email does not look like a valid email";
    }
    return TRUE;
}

function verifyFileNameExists ($patientId, $fileName){
    $query = DB::queryFirstField("SELECT id FROM patientfiles WHERE `fileName` = %s and patientId=%i", $fileName, $patientId );
    if ($query) {
        return "File name already exists. Please rename the file you wish to upload.";
    }
    return TRUE;
}


function findQueuePosition($walkinId) {
    $rows= DB::query("SELECT id FROM walkins WHERE activeStatus = 'ACTIVE' 
    AND DATE(queueStart) = CURRENT_DATE() ORDER BY priority, id;");
    $count = 0;
    foreach ($rows as &$row){
        $count += 1;
        if($row['id'] == $walkinId) {
            return $count;
        }
    }
    return $count;
}

function verifyFileDescription($fileDescription){
    if (strlen($fileDescription) <= 0 || strlen($fileDescription) > 250) {
        return "You must provide a file description that must not exceed 250 characters";
    }
    return TRUE;
}

function verifyAppointmentType($appointmentType){
        if (!in_array($appointmentType, ['checkup','mental health','reproductive health','urgent','follow up','other'])) {
            return "Appointment Type invalid: must be one of 'checkup','mental health','reproductive health','urgent','follow up','other'";
        }
    return TRUE;
}

function verifyType($type){
        if (!in_array($type, ['face to face','virtual'])) {
            return "Type invalid: must be 'face to face' or 'virtual'. Type selected: ".$type;
        }
    return TRUE;
}

function verifyPriority($priority){
    if (!in_array($priority, ['HIGH','LOW', 'TBD'])) {
        return "Type invalid: must be 'HIGH', 'LOW' or 'TBD'";
    }
return TRUE;
}

function verifyDescription($description){
    if (strlen($description) <= 0 || strlen($description) > 500) {
        return "You must provide a reason for your appointment no longer than 500 characters.";
    }
    return TRUE;
}


function averageWaitTimeWalkin() {

    $time = DB::queryFirstField("SELECT AVG(TIMESTAMPDIFF(MINUTE,queueStart, consultationTime)) FROM walkins 
    WHERE consultationTime IS NOT NULL AND DATE(queueStart) = CURRENT_DATE()");
    if(!$time) {
        return 0;
    }
    return round($time);
}

function averageWaitTimeAppointments() {
    $records = DB::query("SELECT consultationTime, timeSlot FROM `bookingslots` WHERE appointmentDate = CURRENT_DATE() AND consultationTime IS NOT NULL");
    if(!$records) {
        return 0;
    }
    $avgMinutes = 0;
    $totalMinutes = 0;
    $rows = 0;

    foreach ($records as &$appointmentRecord) {
        $startTime = new DateTime(timeSlotsFormatted($appointmentRecord['timeSlot']));
        $endTime = new DateTime($appointmentRecord['consultationTime']);

        $minutes = calculateTimeDiffMinutes($startTime, $endTime);

        $totalMinutes += $minutes;
        $rows += 1;   
        
    }
    $avgMinutes = ceil($totalMinutes / $rows);

    return round($avgMinutes);


}

function averageWaitTimeForDoctor($doctorId) {
    $records = DB::query("SELECT consultationTime, timeSlot FROM `bookingslots` WHERE doctorId = %i 
    AND appointmentDate = CURRENT_DATE() AND consultationTime IS NOT NULL;", $doctorId);
    if(!$records) {
        return 0;
    }
    $totalMinutes = 0;
    $rows = 0;
    foreach ($records as &$appointmentRecord) {
        $startTime = new DateTime(timeSlotsFormatted($appointmentRecord['timeSlot']));
        $endTime = new DateTime($appointmentRecord['consultationTime']);
        $minutes = calculateTimeDiffMinutes($startTime, $endTime);
        $totalMinutes += $minutes;
        $rows += 1;
        $avgMinutes = ceil($totalMinutes / $rows);
        
    }
    return $avgMinutes;
}



function walkinAvgWaitTimeForDoctor($doctorId) {

    $time = DB::queryFirstField("SELECT AVG(TIMESTAMPDIFF(MINUTE,queueStart, consultationTime)) FROM walkins WHERE 
    doctorId = %i AND consultationTime IS NOT NULL AND DATE(queueStart) = CURRENT_DATE();", $doctorId);
    if(!$time) {
        return 0;
    }

    return round($time);
}


function calculateTimeDiffMinutes($startTime, $endTime) {
    $difference = date_diff($startTime,$endTime);
    $minutes = $difference->days * 24 * 60;
    $minutes += $difference->h * 60;
    $minutes += $difference->i;
    return $minutes;

}

function patientWalkinWaitTime($walkinId) {

    $time = DB::queryFirstField("SELECT TIMESTAMPDIFF(MINUTE,queueStart, consultationTime) FROM walkins 
    WHERE consultationTime IS NOT NULL AND DATE(queueStart) = CURRENT_DATE() AND id = %i", $walkinId);
    if(!$time) {
        return 0;
    }
    return round($time);

}

function walkinNumOfDoctors(){

    $count= DB::queryFirstField("SELECT count(*) from doctorschedules where date = CURRENT_DATE() and availability = 'WALK-IN'");
    if(!$count) {
        return 0;
    }
    return $count;
}

function capacity($numOfDoctors){

    $capacity = $numOfDoctors * 16;
    return $capacity;
}

function walkinAvailableSpots() {
    $spotsTaken = DB::queryFirstField("SELECT count(*) from walkins where DATE(queueStart) = CURRENT_DATE()");
    $capacity = capacity(walkinNumOfDoctors());
    $availableSpots = $capacity - $spotsTaken;
    return $availableSpots;
}

function appointmentsNumOfDoctors(){
    $count= DB::queryFirstField("SELECT count(distinct doctorId) FROM `bookingslots`where appointmentDate = CURRENT_DATE();");
    if(!$count) {
        return 0;
    }
    return $count;
}

function appointmentsAvailableSpots() {
    $spotsTaken = DB::queryFirstField("SELECT count(*) from bookingslots where appointmentDate = CURRENT_DATE()");
    $capacity = capacity(appointmentsNumOfDoctors());
    $availableSpots = $capacity - $spotsTaken;
    return $availableSpots;
}




/* Admin UTILS */

function nullify($value)
{
    if (!$value) {
        return null;
    }
    return $value;
}

function prepareUserList(&$userList){
    foreach ($userList as &$user) {
        nullify($user['p.photo']);
        if ($user['p.photo']) {
            $user['p.photo'] = "/files/profile/" . $user['p.id'];
        }
        $user['p.phone'] = formatPhone($user['p.phone']);
    }
}
//modified from a method by Arjun Praveen @ https://arjunphp.com/ - for displaying phone numbers on page
function formatPhone($number){

    // Allow only Digits, remove all other characters.
    $number = preg_replace("/\D/", "", $number);
    // get number length.
    $length = strlen($number);
    // if number = 10
    if ($length == 10) {
        $number = preg_replace("/^1?(\d{3})(\d{3})(\d{4})$/", "$1&#8209;$2&#8209;$3", $number); //uses non-breaking slashes - raw required
    } else if($length == 0){
        return null;
    } else {
        return false;
    }
    return $number;
}

function validatePhone($phone){ //will take phone input with hyphens and convert it to numbers, then verify format
    if(!$phone){
        return null;
    }
    if(!preg_match('/\d{3}-\d{3}-\d{4}/', $phone)){
        return false;
    }
    return preg_replace('/\D/', '', $phone);
}

function validateEmail($emailAddress){
    // email address is blank
    if (!$emailAddress) {
        return NULL;
    }
    // not a valid email address
    if (!filter_var($emailAddress, FILTER_VALIDATE_EMAIL)) {
        return FALSE;
    }
    //already exists in database
    $query = DB::queryFirstRow("SELECT `email` FROM users WHERE `email` = %s", $emailAddress);
    if ($query) {
        return FALSE;
    }
    return $emailAddress;
}

function validatePassword($password){ //temporary password?
    global $log;
    if(!$password){
        return NULL;
    }
    if(!v::noneOf(v::uppercase(), v::lowercase(), v::alpha(), v::digit())->length(8, 72)->validate($password)){
        $log->debug("Password ".$password."invalid");
        return false;
    }
    return password_hash($password, PASSWORD_DEFAULT);
}

function validateName($name){
    //only letters and letters with accents, no numbers, hyphens allowed, one apostrophe allowed
    if(!preg_match('/[A-Za-z\xC0-\xFF-\']/', $name)){ // represent the characters A-Z, a-z, À-ÿ, -, and '
        return false;
    }
    if(substr_count($name, '\'', 0) > 1){ //one hyphen allowed
        return false;
    }
    return $name;
}

function validateAddress($address){ //FIXME
    // must start with a number?
    global $log;
    $address = strip_tags($address);
    if(!preg_match('/[A-Za-z0-9\xC0-\xFF-\'\.\ ]/', $address)){ // represent the characters A-Z, a-z, À-ÿ, -, ' and .
        $log->debug("Address contains invalid characters");
        return false;
    }
    return $address;
}

function validateRole($role){
    if(v::noneOf(v::equals('patient'),v::equals('doctor'),v::equals('admin'))->validate($role)){
        return false;
    }
    return $role;
}

function validateDOB($dob){
    // must be before today, must be after 150 years ago
    $valid = v::date()->maxAge(150)->minAge(0)->validate($dob);
    if($valid){
        return $dob;
    }
    return $valid;
}

function validateLicense($licno){ //FIXME - add validation for duplicate entry
    global $log;
    if(!$licno){
        return null;
    }
    if(!v::digit()->length(5,5)->validate($licno)){
        $log->debug("incorrect format for license number - license number is 5 numbers");
        return false;
    }
    if(DB::queryFirstRow("SELECT * FROM users WHERE doctorLicense = %s", $licno)){
        $log->debug("license number already in use");
        return false;
    }
    return $licno;
}

function validateHealthCard($hcno){
    global $log;
    if(!$hcno){
        $log->debug("health card is blank");
        return null;
    }
    $matches = DB::queryFirstField("SELECT id FROM users WHERE healthCardNo=%s", $hcno);
    if($matches){
        $log->debug("health card number already in use.");
        return false;
    }
    // $hcno = preg_replace(" ", "", $hcno);
    if (!preg_match('/^[A-Z]{4}[0-9]{8}$/', $hcno)) {
        $log->debug("health card does not match the regex".$hcno);
        return false;
    }
    return $hcno;

}

function validateDr($drId){
    //doctor must already be in database
    if(!$drId){
        return null;
    }
    if($drId == "none"){
        return null;
    }
    $idList = DB::queryFirstColumn("SELECT id FROM users WHERE `role`=%s", 'doctor');
    if(!in_array($drId, $idList)){
        return false;
    }
    return $drId;
}

function validatePhoto($photo){
    if(!$photo){
        return null;
    }

    $max = DB::queryFirstRow( 'SELECT @@global.max_allowed_packet' );
    $max = $max['@@global.max_allowed_packet']."B";
    if(!v::noneOf(v::executable())->image()->size(null, $max)->validate($photo)){
        return false;
    }

    return file_get_contents($photo);
}

const TIME_SLOTS = array(
    "choose a time slot",
    "8am - 8:30am",
    "8:30am - 9am",
    "9am - 9:30am",
    "9:30am - 10am",
    "10am - 10:30am",
    "10:30am - 11am",
    "11am - 11:30am",
    "11:30am - 12pm",
    "1pm - 1:30pm",
    "1:30pm - 2pm",
    "2pm - 2:30pm",
    "2:30pm - 3pm",
    "3pm - 3:30pm",
    "3:30pm - 4pm",
    "4pm - 4:30pm",
    "4:30pm - 5pm"
);

const TIME_SLOTS_FORMATTED = array(
    "08:00:00",
    "08:30:00",
    "09:00:00",
    "09:30:00",
    "10:00:00",
    "10:30:00",
    "11:00:00",
    "11:30:00",
    "13:00:00",
    "13:30:00",
    "14:00:00",
    "14:30:00",
    "15:00:00",
    "15:30:00",
    "16:00:00",
    "16:30:00",
);

function timeSlots($slot){
    if($slot < 1 || $slot > 16){
        return false;
    }
    return TIME_SLOTS[$slot];
}

function timeSlotsFormatted($slot){
    if($slot < 1 || $slot > 16){
        return false;
    }
    return TIME_SLOTS_FORMATTED[$slot];
}

/* DEFINE DEMO ACCOUNTS */ 
const DEMO_IDS = array(1, 6, 7); 