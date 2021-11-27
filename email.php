<?php

require_once 'vendor/autoload.php';

require_once 'init.php';

use SendGrid\Mail\From;
use SendGrid\Mail\To;
use SendGrid\Mail\Mail;

use \Firebase\JWT\JWT;
use GuzzleHttp\Client;


$log->debug("Daily scheduler run started");

$tomorrow = date_format(new DateTime('tomorrow'), "Y-m-d");
$result = DB::query("SELECT p.firstName as firstName, p.lastName as lastName, timeSlot, 
                d.lastName as docLastName, bookingslots.id as bsId, `type`, p.email as email, meetingId
        FROM bookingslots INNER JOIN users as d ON bookingslots.doctorId = d.id 
        INNER JOIN users as p ON p.id = bookingslots.patientId WHERE appointmentDate = %s", $tomorrow);

$from = new From("admn1clickclinic@gmail.com", "Click Clinic");
$tos = [];
if ($result) {
    foreach ($result as $appointment) {
        if ($appointment['type'] == 'virtual') {
            $meetingInfo = getZoomMeeting($appointment['meetingId']);
            $time = timeSlots($appointment['timeSlot']);
            $tos[] = new To(
                $appointment['email'],
                $appointment['firstName'],
                [
                    'first_name' => $appointment['firstName'],
                    'last_name' => $appointment['lastName'],
                    'doc_name' => $appointment['docLastName'],
                    'time' => $time,
                    'virtual' => "Follow this link to join the virtual meeting for your appointment",
                    'link' => "Link: " . $meetingInfo['join_url']
                ]
            );
        } else {
            $time = timeSlots($appointment['timeSlot']);
            $tos[] = new To(
                $appointment['email'],
                $appointment['firstName'],
                [
                    'first_name' => $appointment['firstName'],
                    'doc_name' => $appointment['docLastName'],
                    'last_name' => $appointment['lastName'],
                    'time' => $time
                ]
            );
        }
    }

    $email = new Mail(
        $from,
        $tos
    );
    $email->setTemplateId("removed");
    $sendgrid = new \SendGrid('removed');
    try {
        $response = $sendgrid->send($email);
        print $response->statusCode() . "\n";
        print_r($response->headers());
        print $response->body() . "\n";
    } catch (Exception $e) {
        echo 'Caught exception: ' . $e->getMessage() . "\n";
    }
}
