<?php

require_once 'vendor/autoload.php';

require_once 'init.php';

require_once 'utils.php';

use Respect\Validation\Validator as v; //don't understand why i need this here

// $app->get('/user/list', function .....);

//admin page
// profile/
// /clinicschedule
// /modifyschedule
// /modify[doctor/patient]profile
// /clinicdashboard (stats)

$app->group('/admin', function ($app) use ($log){
    
    $app->get('[/]', function($request, $response, $args) use ($log){
        $log->debug(print_r($_SERVER['HTTP_HOST']));
        return $this->view->render($response, "admin/admin.html.twig");
    });
    

    //register
$app->get('/register', function ($request, $response, $args) {
    //populate doctor list with names of doctors
    $docList = DB::query("SELECT id, firstName, lastName FROM users WHERE `role` = 'doctor' ORDER BY lastName");
    return $this->view->render($response, "admin/register.html.twig", ['docList' => $docList]);
});

$app->post('/register', function ($request, $response, $args) {
    $email = validateEmail($request->getParam('email'));
    $password = validatePassword($request->getParam('password'));
    $firstName = validateName($request->getParam('fname'));
    $lastName = validateName($request->getParam('lname'));
    $address = validateAddress($request->getParam('address')); //VALIDATE ME
    $phone = validatePhone($request->getParam('phone'));
    $role = validateRole($request->getParam('role'));
    $photo = validatePhoto($_FILES['photo']['tmp_name']);
    $dateOfBirth = validateDOB($request->getParam('dob'));
    $license = validateLicense($request->getParam('license'));
    $healthCardNo = validateHealthCard($request->getParam('healthcard'));
    $doctorId = validateDr($request->getParam('doctor'));

    $array = [
        'email' => $email,
        'password' => $password,
        'firstName' => $firstName,
        'lastName' => $lastName,
        'address' => $address,
        'phone' => $phone,
        'role' => $role,
        'photo' => $photo,
        'dateOfBirth' => $dateOfBirth,
        'doctorLicense' => $license,
        'healthCardNo' => $healthCardNo,
        'familyDoctorId' => $doctorId
    ];
    $errorList = [];
    foreach($array as $field => $value){
        if($value === false){
            $errorList[] = $field;
        }
    }
    if(!$errorList){
        $insert = DB::insert('users', $array);
        if ($insert) {
            setFlashMessage('submission successful', 'success');
        }
    } else {
        $flashMessage = "<ul class='alert alert-danger'>The following fields are invalid:";
        foreach($errorList as $error){
            $flashMessage .= "<li>".$error."</li>";
        }
        $flashMessage .= "</ul>";
        setFlashMessage($flashMessage);
    }
    return $response->withRedirect("register");
});

//modify profile
$app->get('/modifyprofile/{id}', function ($request, $response, $args) {
    $profile = DB::queryFullColumns("SELECT p.*, d.firstName, d.lastName FROM users `p` LEFT JOIN users `d` ON  p.familyDoctorId = d.id WHERE p.id=%s", $args['id']);
    prepareUserList($profile);
    foreach ($profile as &$item) { //since we're only expecting one row here, this just pulls that row out of the array (if there were multiple rows, it would just get the last one)
        $temp = $item;
    }
    $profile = $temp;
    $doctorList = DB::query("SELECT id, firstName, lastName FROM users WHERE `role`=%s", 'doctor');

    return $this->view->render($response, "admin/modify.html.twig", ['profile' => $profile, 'doctorList' => $doctorList]);
});

$app->post('/modifyprofile/{id}', function ($request, $response, $args) {
    //    $variable = $requst->getParam('formVariable');

    return $this->view->render($response, "admin/modify.html.twig");
});
$app->post('/deleteuser/{id}', function ($request, $response, $args) {

    DB::delete('users', 'id=%i', $args['id']);
    $delete = DB::affectedRows();
    if($delete){
        setFlashMessage("user #".$args['id']." deleted");
        return $response->withRedirect("/admin/viewall");
    } else {
        setFlashMessage("delete failed");
        return $this->view->render($response, "admin/modify.html.twig");
    }
});

//modify profile
$app->get('/viewall', function ($request, $response, $args) {
    $userList = DB::queryFullColumns("SELECT p.*, d.firstName, d.lastName FROM users `p` LEFT JOIN users `d` ON  p.familyDoctorId = d.id ");
    prepareUserList($userList);
    return $this->view->render($response, "admin/viewall.html.twig", ['userList' => $userList]);
});
// filter by role
//sort by id (default), last name
$app->get('/filtersort/{role}/{sort}', function ($request, $response, $args){
    $role = $args['role'];
    $sort = $args['sort'];
    if($role == 'all'){
        $userList = DB::queryFullColumns("SELECT p.*, d.firstName, d.lastName FROM users `p` LEFT JOIN users `d` ON  p.familyDoctorId = d.id ORDER BY p.`$sort`");
    } else {
        $userList = DB::queryFullColumns("SELECT p.*, d.firstName, d.lastName FROM users `p` LEFT JOIN users `d` ON  p.familyDoctorId = d.id WHERE p.`role`=%s ORDER BY p.`$sort`", $role);
    }
    prepareUserList($userList);
    return $this->view->render($response, "admin/viewall.html.twig", ['userList' => $userList]);
});

$app->post('/modify/formfield', function ($request, $response, $args) { //FIXME: no server side validation and no password hashing

    $userId = json_decode($request->getParam('userId'), true);
    $field = $request->getParam('field');

    /* PREVENT CHANGES TO DEMO ACCOUNTS */
    if(in_array($userId, DEMO_IDS)){
        if($field == 'password'){
            return $response->write("We're sorry. This is a demo profile, and the password cannot be changed");
        }
        if($field == 'email'){
            return $response->write("We're sorry. This is a demo profile, and the email address cannot be changed");
        }
    }
    /* -- */
    
    switch($field){
        case 'email':$newValue = validateEmail($request->getParam('newValue')); break;
        case 'password':$newValue = validatePassword($request->getParam('newValue')); break;
        case 'firstName':$newValue = validateName($request->getParam('newValue')); break;
        case 'lastName':$newValue = validateName($request->getParam('newValue')); break;
        case 'address':$newValue = $request->getParam('newValue'); break;
        case 'phone':$newValue = validatePhone($request->getParam('newValue')); break;
        case 'role':$newValue = validateRole($request->getParam('newValue')); break;
        case 'photo':$newValue = validatePhoto($request->getParam('newValue')); break;
        case 'dateOfBirth':$newValue = validateDOB($request->getParam('newValue')); break;
        case 'doctorLicense':$newValue = validateLicense($request->getParam('newValue')); break;
        case 'healthCardNo':$newValue = validateHealthCard($request->getParam('newValue')); break;
        case 'familyDoctorId':$newValue = validateDr($request->getParam('newValue')); break;
    }
    
    if($newValue === false){
        return $response->write("Invalid input");
    }
    $insert = DB::update('users', [$field => $newValue], 'id=%i', $userId);
    if ($insert) {
        return $response->write("Record successfully changed. ".$newValue);
    } else {
        return $response->write("There was an error. Please refresh the page. Status: ");
    }
});

$app->post('/uploadnewphoto/{id}', function ($request, $response, $args){
    $photo = validatePhoto($_FILES['photo']['tmp_name']);
    if(!$photo){
        setFlashMessage("photo not uploaded", "danger");
    } else {
        DB::update('users', ['photo' => $photo], 'id=%i', $args['id']);
        $success = DB::affectedRows();
        if(!$success){
            setFlashMessage("no changes were made to the photo", "info");
        } else {
            setFlashMessage("photo updated", "success");
        }
    }
    return $response->withRedirect('/admin/modifyprofile/'.$args['id']);
});


$app->get('/schedules', function ($request, $response, $args) {
    return $this->view->render($response, "admin/schedules.html.twig");
});

$app->post('/schedules', function ($request, $response, $args) {
    $doctorId = $request->getParam('whichDoctor');
    $date = $request->getParam('schedDate');
    $availability = $request->getParam('schedType');
    if(!$doctorId){
        setFlashMessage("no doctor selected");
        return $response->withRedirect('/admin/schedules');
    }
    $working = DB::queryFirstRow("SELECT * FROM doctorschedules WHERE doctorId=%s AND `date`=%s", $doctorId, $date);
    if($working){
        setFlashMessage("this doctor is already working on this day", "danger");
    } else {
        DB::insert('doctorschedules', ['doctorId' => $doctorId, 'date' => $date, 'availability' => $availability]);
        $success = DB::affectedRows();
        if(!$success){
            setFlashMessage("there was a problem adding a schedule", "danger");
        }
    }
    setFlashMessage("schedule added", "success");
    return $response->withRedirect('/admin/schedules');
});

$app->get('/deleteappointment/{id}', function ($request, $response, $args){
    DB::delete('bookingslots', 'id=%i', $args['id']);
    $success=DB::affectedRows();
    if($success){
        setFlashMessage("Appointment #".$args['id']." deleted", "success");
        return $response->withRedirect("/admin/schedules");
    } else {
        setFlashMessage("there was an problem with deleting the appointment", "danger");
        return $response->withRedirect("/admin/schedules");
    }
});
$app->get('/deleteschedule/{id}', function ($request, $response, $args){
    DB::delete('doctorschedules', 'id=%i', $args['id']);
    $success=DB::affectedRows();
    if($success){
        setFlashMessage("Schedule #".$args['id']." deleted", "success");
        return $response->withRedirect("/admin/schedules");
    } else {
        setFlashMessage("there was a problem with deleting appointment#".$args['id'], "danger");
        return $response->withRedirect("/admin/schedules");
    }
});

$app->get('/doctorlist', function ($request, $response, $args){
    $date = $request->getParam('date');
    $set = $request->getParam('set');
    if($set == 'patients'){
        $id = $request->getParam('id');
        $patients = DB::query("SELECT id, firstName, lastName FROM users WHERE familyDoctorId = %i", $id);
        return $response->withJson($patients);
    }
    //SELECT id, firstName, lastName FROM users WHERE `role`='doctor' AND id NOT IN (SELECT doctorId FROM doctorschedules WHERE date = "2021-11-12")
    $excluded = DB::queryFirstColumn("SELECT doctorId FROM doctorschedules WHERE date = %s", $date);
    if($excluded){
        $list = DB::query("SELECT id, firstName, lastName FROM users WHERE `role`='doctor' AND id NOT IN %ls", $excluded);
    } else {
        $list = DB::query("SELECT id, firstName, lastName FROM users WHERE `role`='doctor'");
    }
    return $response->withJson($list);
});

$app->get('/walkintriage', function ($request, $response, $args) {
    return $this->view->render($response, "admin/walkintriage.html.twig");
});

$app->get('/walkintriage/api', function ($request, $response, $args) {
 
    $walkins = DB::query("SELECT w.id, w.priority, w.queueStart, w.activeStatus, w.appointmentType, w.type, w.date, w.description, u.firstName, 
    u.lastName FROM walkins w, users u where w.patientId = u.id AND w.activeStatus = 'ACTIVE' AND DATE(queueStart) = CURRENT_DATE()
    ORDER BY `priority` DESC;");

    if($walkins){

        foreach ($walkins as &$walkinRecord) {
            $phpdate = strtotime($walkinRecord['queueStart']);
            $walkinRecord += ['time' => date('H:i', $phpdate)];
        }

        return $response->getBody()->write(json_encode($walkins));
    }
    return $response->getBody()->write(json_encode(false));
});

$app->get('/walkintriage/api/{id:[0-9]+}', function ($request, $response, $args) {

    $id = $args['id'];
 
    $walkin = DB::queryFirstRow("SELECT w.id, w.priority, w.queueStart, w.activeStatus, w.appointmentType, w.type, w.date, u.firstName, 
    u.lastName FROM walkins w, users u where w.patientId = u.id AND w.activeStatus = 'ACTIVE' AND w.id=%i", $id);

    if (!$walkin) {
        throw new \Slim\Exception\NotFoundException($request, $response);
    }  
        $phpdate = strtotime($walkin['queueStart']);
        $walkin += ['time' => date('H:i', $phpdate)];
        return $response->getBody()->write(json_encode($walkin));
    
});

$app->delete('/walkintriage/api/{id:[0-9]+}', function ($request, $response, $args) use ($log) {
    $id = $args['id'];
    $result = DB::queryFirstRow("SELECT * FROM walkins where id = %i", $id);
    
    if (!$result) {
        throw new \Slim\Exception\NotFoundException($request, $response);
    }
    DB::delete('walkins', "id=%i", $id);
    $log->debug("Record walkins deleted id=" . $id);
    // code is always 200
    // return true if record actually deleted, false if it did not exist in the first place
    $count = DB::affectedRows();
    $json = json_encode($count != 0, JSON_PRETTY_PRINT); // true or false
    return $response->getBody()->write($json);
});

$app->patch('/walkintriage/api/{id:[0-9]+}', function ($request, $response, $args) use ($log) {
 
    ///FIX ME
    $id = $args['id'];
    $result = DB::queryFirstRow("SELECT * FROM walkins WHERE id=%i ", $id);
    $log->debug(($result['id']));
    if (!$result) {
        throw new \Slim\Exception\NotFoundException($request, $response);
    }
    
    $json = $request->getBody();
    $item = json_decode($json, TRUE);
    $priority = $item['priority'];

    $errorList = [];

    $result = verifyPriority($priority);
    if ($result !== TRUE) {
        $errorList[] = $result;
    }

    $insert = ['priority' => $priority];
    if (!$errorList) {
        DB::update('walkins', $insert, "id=%i", $id);
        $log->debug("Record walkins updated, id=" . $id);


        $json = json_encode(TRUE); // true or false
        return $response->getBody()->write($json);

    } else {
        $json = json_encode($errorList);
        return $response->getBody()->write($json);
    }

});

$app->get('/walkinregister', function ($request, $response, $args)  use ($log) {

    $time = date("H:i:s");
    $openingTime = date("H:i:s", strtotime("08:00:00"));
    $closingTime = date("H:i:s", strtotime("15:00:00")); //change

    //check if current day is a weekday
    $date = date('Y-m-d');
    $isweekend = date('N', strtotime($date)) >= 6;

    if ($time < $openingTime || $time > $closingTime || $isweekend) {
        setFlashMessage("Walkin Registration is not open. Please try again between 8am and 3pm on weekdays", "danger");
        return $response->withRedirect("/admin");
    }

    if(capacity(walkinNumOfDoctors() == 0)) {
        $message = "Unable to register patient: No doctors are scheduled for walkins today.  ";
        setFlashMessage($message, "danger");
        return $response->withRedirect("/admin");

    }

    if(walkinAvailableSpots() == 0){
        $message = "Unable to register patient: No more spots available in the walkin clinic for today.  ";
        setFlashMessage($message, "danger");
        return $response->withRedirect("/admin");

    }

    return $this->view->render($response, "admin/walkinregister.html.twig");
});

$app->post('/walkinregister', function ($request, $response, $args)  use ($log) {


    $healthCardNo = $request->getParam('healthCardNo');
    $type = $request->getParam('type');
    $reason = strip_tags($request->getParam('reason'));
    $appointmentType = $request->getParam('appointmentType');
    $fileDescription = strip_tags($request->getParam('fileDescription'));
    $errorList = [];
    
    //verify if the patient has already registered for the day
    // $patientId = DB::queryFirstField("SELECT id FROM walkins WHERE patientId = %i AND DATE(queueStart) = CURRENT_DATE()", $id);
    $patientId = DB::queryFirstField("SELECT id FROM `users` WHERE healthCardNo=%s", $healthCardNo);
    if (!$patientId) {
        $errorList[] = "You must first register the patient at the clinic before registering them for walkins.";
    }
    $result = DB::queryFirstField("SELECT id FROM walkins WHERE patientId = %i AND DATE(queueStart) = CURRENT_DATE()", $patientId);
    if ($result) {
        $errorList[] = "Patient is already registered for today's walkin clinic.";
    }

    $result = verifyHealthCardNo($healthCardNo);
    if ($result !== TRUE) {
        $errorList[] = $result;
    }
    $result = verifyDescription($reason);
    if ($result !== TRUE) {
        $errorList[] = $result;
    }
    $result = verifyAppointmentType($appointmentType);
    if ($result !== TRUE) {
        $errorList[] = $result;
    }
    $result = verifyType($type);
    if ($result !== TRUE) {
        $errorList[] = $result;
    }

    $file = $_FILES['file'];

    if ($file['error'] !== 4) {

        $fileName = null;
        $result = verifyUploadedFile($file, $fileName);
        if ($result !== TRUE) {
            $errorList[] = $result;
        }

        $result = verifyFileNameExists($patientId, $fileName);
            if ($result !== TRUE) {
                $errorList[] = $result;
        }

        $file = file_get_contents($file['tmp_name']);

        $result = verifyFileDescription($fileDescription);
        if ($result !== TRUE) {
            $errorList[] = $result;
        }
    } else {
        $file = null;
        if(strlen($fileDescription) > 0) {
            $errorList[] = "You must upload a file along with a file description.";
    }

    }

    $valuesList = ['reason' => $reason, 'fileDescription' => $fileDescription, 'healthCardNo' => $healthCardNo];

    if ($errorList) {
        return $this->view->render($response, '/admin/walkinregister.html.twig', ['v' => $valuesList, 'errorList' => $errorList]);
    } else {

        $queueStart = date('Y-m-d H:i:s');
        $date = date("Y-m-d");
        $activeStatus = "ACTIVE";

        //insert into walkins
        $walkinValues = [
            'patientId' => $patientId, 'queueStart' => $queueStart, 'appointmentType' =>  $appointmentType,
            'description' => $reason, 'type' => $type, 'date' => $date, 'activeStatus' => $activeStatus
        ];
        DB::insert('walkins', $walkinValues);

        $walkinId = DB::insertId();
        $queueNumber = findQueuePosition($walkinId);
        $avgWaitTime = averageWaitTimeWalkin();

        $message = "Patient has been registered for today's walkin clinic.\n They are currently in position "
        . $queueNumber . " of the queue." . "The average wait time is currently " . $avgWaitTime . " minutes.";

        if ($file !== NULL) {
            $patientFileValues = [
                'patientId' => $patientId, 'uploadedBy' =>  $_SESSION['user']['role'],
                'file' => $file, 'walkinId' => $walkinId, 'description' => $fileDescription, 'date' => $date, 'fileName' => $fileName
            ];
            //insert into patient files
            DB::insert('patientfiles', $patientFileValues);
            $message = $message . " A file has also been uploaded for the patient's appointment.";
        }
        setFlashMessage($message, "success");
        return $response->withRedirect("/admin");
    }
});

$app->get('/getcalendarinfo', function ($request, $response, $args) use ($log){
    //for patients, doctorID of patient logged in 
    $set = $request->getQueryParams()['set'];

    $date = $request->getQueryParams()['date'];
    $doctorSchedules = DB::query("SELECT s.id `schedId`, s.doctorId, s.availability, s.date, d.firstName, d.LastName FROM doctorschedules `s`, users `d` WHERE s.doctorId = d.id AND `date`=%s", $date);
    $appointments = DB::query("SELECT b.*, p.firstName, p.lastName FROM bookingslots b, users p WHERE b.patientId = p.id AND appointmentDate=%s", $date);
    $response = $response->withHeader('Content-type', 'application/json');
    if($set == 'doctors'){
        return $response->withJson($doctorSchedules);
    }
    return $response->withJson(['appointments' => $appointments, 'doctorschedules'=>$doctorSchedules]);
});

$app->post('/bookappointments', function ($request, $response, $args) use ($log){
    
    $patientId = $request->getParam('patient');
    $doctorId = $request->getParam('doctor');
    $timeSlot = $request->getParam('timeSlot');
    $timeSlotDate = $request->getParam('timeSlotDate');
    // $timeSlot = date('Y-m-d H:i:s', strtotime($timeSlot));
    $type = $request->getParam('type');
    $reason = strip_tags($request->getParam('reason'));
    $appointmentType = $request->getParam('appointmentType');
    $fileDescription = strip_tags($request->getParam('fileDescription'));
    $date = date('Y-m-d H:i:s');
    $errorList = [];
    //get the doctorscheduleId
    $doctorScheduleId = DB::queryFirstField("SELECT id FROM doctorschedules WHERE doctorId = %i AND `date`=%s", $doctorId, $timeSlotDate);
    if(!$doctorScheduleId){
        $errorList[] = "This doctor is not available for appointments on the selected day";
    }
    //make sure there is no double booking
    $result = DB::queryFirstField("SELECT id FROM bookingslots WHERE doctorScheduleId = %i AND timeSlot=%i", $doctorScheduleId, $timeSlot);

    if ($timeSlotDate < $date || $result) {
        $errorList[] = "This time slot is invalid";
    }
    // no appointments on weekends and after 4pm?
    // $time = date("H:i:s", strtotime($timeSlot));
    // $openingTime = date("H:i:s", strtotime("08:00:00"));
    // $closingTime = date("H:i:s", strtotime("16:00:00")); //change
    // $isweekend = date('N', strtotime($timeSlot)) >= 6;

    // if ($time < $openingTime || $time > $closingTime || $isweekend) {
    //     $errorList[] = "Appointments are only available on weekdays between 8am and 4pm";
    // }
    //TODO: only have dates that are a month away?

    $log->debug("Reason is: ".$reason);
    $result = verifyDescription($reason);
    if ($result !== TRUE) {
        $errorList[] = $result;
    }
    $result = verifyAppointmentType($appointmentType);
    if ($result !== TRUE) {
        $errorList[] = $result;
    }
    $result = verifyType($type);
    if ($result !== TRUE) {
        $errorList[] = $result;
    }

    $file = $_FILES['file'];
    if(!$file){
        $file = null;
        if(strlen($fileDescription) > 0) {
            $errorList[] = "You must upload a file along with a file description.";
        }

    } else if ($file['error'] !== 4) {

        $fileName = null;
        $result = verifyUploadedFile($file, $fileName);
        if ($result !== TRUE) {
            $errorList[] = $result;
        }
        $result = verifyFileNameExists($patientId, $fileName);
            if ($result !== TRUE) {
                $errorList[] = $result;
        }

        $file = file_get_contents($file['tmp_name']);

        $result = verifyFileDescription($fileDescription);
        if ($result !== TRUE) {
            $errorList[] = $result;
        }

    }
    $valuesList = ['reason' => $reason, 'fileDescription' => $fileDescription];

    if ($errorList) {
        $errorMessage = "There were problems with your submission";
        foreach($errorList as $error){
            $errorMessage.="<li>$error</li>";
        }
        setFlashMessage($errorMessage, "danger");
        return $response->withRedirect("/admin/schedules");
    } else {

        $bookingSlotValues = [
            'timeSlot' => $timeSlot, 'patientId' => $patientId, 'doctorId' => $doctorId,
            'appointmentType' =>  $appointmentType, 'description' => $reason, 'type' => $type,
            'doctorScheduleId' => $doctorScheduleId, 'appointmentDate' => $timeSlotDate
        ];

        $message = "Your appointment has been booked.";

        DB::insert('bookingslots', $bookingSlotValues);
        $date = date("Y-m-d");

        if ($file !== NULL) {
            $appointmentId = DB::insertId();

            $patientFileValues = [
                'patientId' => $patientId, 'doctorId' => $doctorId, 'uploadedBy' =>  $_SESSION['user']['role'],
                'file' => $file, 'appointmentId' => $appointmentId, 'description' => $fileDescription,
                'date' => $date, 'fileName' => $fileName
            ];

            DB::insert('patientfiles', $patientFileValues);
            $message = $message . " Your file has also been uploaded.";

        }
        setFlashMessage($message, "success");
        return $response->withRedirect("/admin/schedules");
    }

});

$app->get('/patientsperdoctorapi', function ($request, $response, $args) {
    $counts = DB::query("SELECT count(*) as `count`, u.firstName, u.lastName from users u, bookingslots b where u.id = b.doctorId AND 
    appointmentDate = CURRENT_DATE() group by b.doctorId");

    if($counts) {
        return $response->getBody()->write(json_encode($counts));
    }
    return $response->getBody()->write(json_encode(false));
    
});

$app->get('/appointmenttypesapi', function ($request, $response, $args) {
    $appointmentTypes = DB::query("SELECT count(*) as `count`, appointmentType FROM `bookingslots` WHERE appointmentDate = CURRENT_DATE()
    GROUP BY appointmentType");

    if($appointmentTypes){
        return $response->getBody()->write(json_encode($appointmentTypes));
    }
    return $response->getBody()->write(json_encode(false)); 
});

$app->get('/waittimesperdoctorapi', function ($request, $response, $args) {
    $records = DB::query("SELECT DISTINCT doctorId, u.firstName, u.lastName FROM `bookingslots` b, users u 
    WHERE b.doctorId = u.id AND appointmentDate = CURRENT_DATE() AND consultationTime IS NOT NULL;");

    if($records) {
        foreach ($records as &$doctorId) {
            $doctorId['avgWaitTime'] = averageWaitTimeForDoctor($doctorId['doctorId']);
        }
    
        return $response->getBody()->write(json_encode($records));
    }
    return $response->getBody()->write(json_encode(false)); 
});

$app->get('/walkinpatientsperdoctorapi', function ($request, $response, $args) {
    $counts = DB::query("SELECT count(*) as `count`, u.firstName, u.lastName from users u, walkins w 
    where u.id = w.doctorId AND DATE(queueStart) = CURRENT_DATE() group by w.doctorId;");

    if($counts) {
        return $response->getBody()->write(json_encode($counts));
    }
    return $response->getBody()->write(json_encode(false));
});

$app->get('/walkinappointmenttypesapi', function ($request, $response, $args) {
    $appointmentTypes = DB::query("SELECT count(*) as `count`, appointmentType FROM `walkins` WHERE DATE(queueStart) = CURRENT_DATE()
    GROUP BY appointmentType");

        if($appointmentTypes){
            return $response->getBody()->write(json_encode($appointmentTypes));
        }
        return $response->getBody()->write(json_encode(false));
});


$app->get('/walkinwaittimesperdoctorapi', function ($request, $response, $args) {
    $records = DB::query("SELECT DISTINCT w.doctorId, u.firstName, u.lastName FROM `walkins` w, 
    users u WHERE w.doctorId = u.id AND DATE(queueStart) = CURRENT_DATE() AND consultationTime IS NOT NULL;");

    if($records){
        foreach ($records as &$doctorId) {
            $doctorId['avgWaitTime'] = walkinAvgWaitTimeForDoctor($doctorId['doctorId']);
        }
        return $response->getBody()->write(json_encode($records));
    }
    return $response->getBody()->write(json_encode(false));
});

$app->get('/walkinstats', function ($request, $response, $args) {

    $walkinCapacity = capacity(walkinNumOfDoctors());
    $walkinAvailableSpots = walkinAvailableSpots();
    $walkinAvgWaitTime = averageWaitTimeWalkin();
    $appointmentsCapacity = capacity(appointmentsNumOfDoctors());
    $appointmentsAvailableSpots = appointmentsAvailableSpots();

    $appointmentsAvgWaitTime = averageWaitTimeAppointments();

    $stats = ['walkinCapacity' => $walkinCapacity, 'walkinAvailableSpots' => $walkinAvailableSpots, 
    'walkinAvgWaitTime' => $walkinAvgWaitTime, 'appointmentsCapacity' => $appointmentsCapacity, 
    'appointmentsAvailableSpots' => $appointmentsAvailableSpots, 'appointmentsAvgWaitTime' => $appointmentsAvgWaitTime
    ];
    return $response->getBody()->write(json_encode($stats));
});

})->add(function ($request, $response, $next) { //middleware to authenticate login

    if(!$_SESSION['user']){
        return $response->withStatus(404);
    } else if ($_SESSION['user']['role'] != "admin"){
        return $response->withRedirect("/");
    } 
    $response = $next($request, $response);

    return $response;
});



