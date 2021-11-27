<?php

require_once 'vendor/autoload.php';
require_once 'utils.php';
require_once 'init.php';

//doctor page
// /doctorprofile
// /edit (modify own profile)
// /viewschedule
// /viewbooking? (with button to alert patient that they’re ready)
// /appointmentinfo X
// /createprescription X
// /createreferral X
// /patientfile X
// /doctordahsboard X                          
//landing page 



$app->group('/doctor', function ($app) {

    //-------------
    //get controllers

    $app->get('[/]', function ($request, $response, $args) {
        $todayDate = date('Y-m-d');
        $appointments = DB::query("SELECT *, bookingslots.id as bookingId, users.id as userId FROM bookingslots LEFT JOIN users ON bookingslots.patientId = users.id WHERE (`status`='upcoming' OR `status`= 'in progress') AND doctorId = %i AND `appointmentDate` = %s ORDER BY appointmentDate, timeSlot ASC LIMIT 5", $_SESSION['user']['id'], $todayDate);
        $position = DB::queryFirstRow("SELECT * FROM doctorschedules WHERE doctorId = %i and `date` = %s", $_SESSION['user']['id'], $todayDate);
        $count = 0;
        foreach ($appointments as &$appointmentRecord) {
            $appointmentRecord['time'] = timeSlots($appointmentRecord['timeSlot']);
            $phpdate = strtotime($appointmentRecord['appointmentDate']);
            if (date('Y-m-d', $phpdate) == date('Y-m-d')) {
                $count++;
                $appointmentRecord += ['date' => 'Today'];
            } else {
                $appointmentRecord += ['date' => date('M d, Y', $phpdate)];
            }
        }
        if (!$position) {
            return $this->view->render($response, "doctor/home.html.twig", ['records' => $appointments, 'count' => $count, 'docSchedule' => $position]);
        }
        if ($position['availability'] == 'WALK-IN') {
            $position['availability'] = 'walk-ins';
            $walkins = DB::queryFirstRow("SELECT *, walkins.id as walkinId, users.id as ptId, count(*) as `count` FROM walkins INNER JOIN users on walkins.patientId = users.id WHERE activeStatus = 'active' AND `date` = CURRENT_DATE() ORDER BY priority, walkins.id LIMIT 1");
            $phpdate = strtotime($walkins['queueStart']);
            $walkins += ['time' => date('H:i', $phpdate)];
            return $this->view->render($response, "doctor/home.html.twig", ['records' => $appointments, 'count' => $count, 'docSchedule' => $position, 'walkins' => $walkins]);
        } else if ($position['availability'] == 'APPOINTMENTS') {
            $position['availability'] = 'appointments';
        } else {
            $position['availability'] = 'time off';
        }
        return $this->view->render($response, "doctor/home.html.twig", ['records' => $appointments, 'count' => $count, 'docSchedule' => $position]);
    });

    $app->get('/searchpatients[/{search}]', function ($request, $response, $args) {
        if (isset($args['search'])) {
            $searchVal = $args['search'];
            return $this->view->render($response, "doctor/searchpatients.html.twig", ['searchVal' => $searchVal]);
        }
        return $this->view->render($response, "doctor/searchpatients.html.twig");
    });

    $app->get('/search/{search}', function ($request, $response, $args) {
        $search = $args['search'];
        $result = DB::query("SELECT p.id, p.firstName, p.lastName as ptLastName, p.dateOfBirth, d.lastName as docLastName, d.firstName as docFirstName FROM users p 
    INNER JOIN users d ON p.familyDoctorId = d.id WHERE p.`role` = 'patient' and (p.firstName LIKE %ss OR p.lastName LIKE %ss)", $search, $search);
        if (!$result) {
            return $response->getBody()->write(json_encode(FALSE));
        } else {
            return $response->getBody()->write(json_encode($result));
        }
    });

    $app->get('/chartapi', function ($request, $response, $args) {
        $appointmentTypes = DB::query("SELECT count(*) as `count`, appointmentType as `type` FROM `bookingslots` WHERE doctorId = %i GROUP BY appointmentType", $_SESSION['user']['id']);
        
        if($appointmentTypes) {
            return $response->getBody()->write(json_encode($appointmentTypes));
        }
        return $response->getBody()->write(json_encode(false));

    });

    $app->get('/walkinchartapi', function ($request, $response, $args) {
        $appointmentTypes = DB::query("SELECT count(*) as `count`, appointmentType as `type` FROM `walkins` WHERE doctorId = %i GROUP BY appointmentType", $_SESSION['user']['id']);
        
        if($appointmentTypes) {
            return $response->getBody()->write(json_encode($appointmentTypes));
        }
        return $response->getBody()->write(json_encode(false));

    });

    $app->get('/walkinqueue', function ($request, $response, $args) {
        $walkins = DB::query("SELECT *, walkins.id as walkinId, users.id as ptId FROM walkins INNER JOIN users on walkins.patientId = users.id WHERE activeStatus = 'active' AND `date` = CURRENT_DATE() ORDER BY priority, walkins.id LIMIT 5");
        foreach ($walkins as &$walkinRecord) {
            $phpdate = strtotime($walkinRecord['queueStart']);
            $walkinRecord += ['time' => date('H:i', $phpdate)];
        }
        return $this->view->render($response, "doctor/walkinqueue.html.twig", ['records' => $walkins]);
    });

    $app->get('/appointmentform/{id:[0-9]+}/{appointmentId:[0-9]+}', function ($request, $response, $args) {
        $id = $args['id'];
        $currentAppointmentId = $args['appointmentId'];
        $currentAppointment = DB::queryFirstRow("SELECT * FROM bookingslots WHERE id = %i and patientId = %i", $currentAppointmentId, $id);
        if (!$currentAppointment) {
            throw new \Slim\Exception\NotFoundException($request, $response);
        }
        $currentAppointment['time'] = timeSlots($currentAppointment['timeSlot']);
        $patient = DB::queryFirstRow("SELECT * FROM users WHERE id = %i AND `role` = 'patient'", $id);
        nullify($patient['photo']);
        if ($patient['photo']) {
            $patient['photo'] = "/files/profile/".$patient['id'];
        }
        $appointments = DB::query("SELECT *, b.description as patientDescription, p.description as doctorDescription FROM bookingslots b LEFT JOIN patientfiles p ON b.id = p.appointmentId WHERE b.patientId = %i AND `status` = 'completed' ORDER BY appointmentDate, timeSlot DESC LIMIT 5", $id); //past appointments only
       
        foreach ($appointments as &$apptRecord) {
            $apptRecord['doctorsNotes'] = strip_tags($apptRecord['doctorsNotes']);
        }
        $medications = DB::query("SELECT * FROM medications WHERE patientId = %i ORDER BY prescribedOn DESC LIMIT 5", $id);
        foreach ($medications as &$record) {
            $record['instructions'] = strip_tags($record['instructions']);
        }
        foreach ($appointments as &$appointmentRecord) {
            $appointmentRecord['time'] = timeSlots($appointmentRecord['timeSlot']);
            $phpdate = strtotime($appointmentRecord['appointmentDate']);
            $appointmentRecord += ['dateTime' => date('Y-M-d', $phpdate) . " " . timeSlots($appointmentRecord['timeSlot'])];
        }
        $referrals = DB::query("SELECT * FROM referrals WHERE patientId = %i", $id);
        $zoomLink = getZoomMeeting($currentAppointment['meetingId']);
        return $this->view->render($response, '/doctor/appointmentform.html.twig', ['patient' => $patient, 'appointments' => $appointments, 'medications' => $medications, 'referrals' => $referrals, 'currentAppointment' => $currentAppointment, 'zoomLink' => $zoomLink]);
    });

    $app->get('/walkinform/{id:[0-9]+}/{walkinid:[0-9]+}', function ($request, $response, $args) {
        $id = $args['id'];
        $walkinId = $args['walkinid'];
        $currentWalkin = DB::queryFirstRow("SELECT * FROM walkins WHERE id = %i and patientId = %i", $walkinId, $id);
        if (!$currentWalkin) {
            throw new \Slim\Exception\NotFoundException($request, $response);
        }

        $patient = DB::queryFirstRow("SELECT * FROM users WHERE id = %i AND `role` = 'patient'", $id);
        nullify($patient['photo']);
        if ($patient['photo']) {
            $patient['photo'] = "/files/profile/".$patient['id'];
        }
        $appointments = DB::query("SELECT *, b.description as patientDescription, p.description as doctorDescription FROM bookingslots b LEFT JOIN patientfiles p ON b.id = p.appointmentId WHERE b.patientId = %i AND `status` = 'completed' ORDER BY appointmentDate, timeSlot DESC LIMIT 5", $id); //past appointments only
        foreach ($appointments as &$apptRecord) {
            $apptRecord['doctorsNotes'] = strip_tags($apptRecord['doctorsNotes']);
        }
        $medications = DB::query("SELECT * FROM medications WHERE patientId = %i ORDER BY prescribedOn DESC LIMIT 5", $id);
        foreach ($medications as &$record) {
            $record['instructions'] = strip_tags($record['instructions']);
        }
        $referrals = DB::query("SELECT * FROM referrals WHERE patientId = %i", $id);
        return $this->view->render($response, '/doctor/walkinform.html.twig', ['patient' => $patient, 'records' => $appointments, 'medications' => $medications, 'referrals' => $referrals, 'currentWalkin' => $currentWalkin]);
    });

    $app->get('/upcomingappointments', function ($request, $response, $args) {
        $appointments = DB::query("SELECT *, bookingslots.id as bookingId, users.id as userId FROM bookingslots LEFT JOIN users ON bookingslots.patientId = users.id WHERE (`status`='upcoming' OR `status`= 'in progress') AND doctorId = %i ORDER BY appointmentDate, timeSlot", $_SESSION['user']['id']);
        foreach ($appointments as &$appointmentRecord) {
            $appointmentRecord['time'] = timeSlots($appointmentRecord['timeSlot']);
            $phpdate = strtotime($appointmentRecord['appointmentDate']);
            $appointmentRecord += ['time' => $appointmentRecord['timeSlot']]; //FIXME
            if (date('Y-m-d', $phpdate) == date('Y-m-d')) {
                $appointmentRecord += ['date' => 'today'];
            }
            $appointmentRecord += ['date' => date('Y-M-d', $phpdate)];
        }
        return $this->view->render($response, '/doctor/upcomingappointments.html.twig', ['records' => $appointments]);
    });

    $app->get('/api/upcomingappointments', function ($request, $response, $args) {
        $result = DB::query("SELECT `status`, bookingslots.id as bookingId, users.id as userId FROM bookingslots LEFT JOIN users ON bookingslots.patientId = users.id WHERE (`status`='upcoming' OR `status`= 'in progress') AND doctorId = %i", 1);
        if (!$result) {
            $response = $response->withStatus(404);
            return $response->getBody()->write(json_encode(FALSE));
        } else {
            $json = json_encode($result, JSON_PRETTY_PRINT);
            $response = $response->withStatus(200);
            $response->getBody()->write($json);
            return $response;
        }
    });

    $app->get('/api/patientfile/{id:[0-9]+}', function ($request, $response, $args) {
        $id = $args['id'];
        $patientRecord = DB::queryFirstRow("SELECT firstName, lastName, dateOfBirth, id, photo FROM users where id = %d", $id);
        if (!$patientRecord) {
            throw new \Slim\Exception\NotFoundException($request, $response);
        }
        global $appointmentsPerPage;
        $appointmentsPerPage = 4;
        $queryParams = $request->getQueryParams();
        $pageNo = isset($queryParams['apptPage']) ? $queryParams['apptPage'] : 1;

        $appointments = DB::query("SELECT *, bookingslots.description as patientDescription, patientfiles.description as fileDescription, bookingslots.patientId as patientId, bookingslots.id as apptId FROM bookingslots 
        LEFT JOIN patientfiles ON bookingslots.id = patientfiles.appointmentId WHERE bookingslots.patientId = %s 
        ORDER BY appointmentDate DESC, timeSlot ASC LIMIT %d OFFSET %d", $id, $appointmentsPerPage, ($pageNo - 1) * $appointmentsPerPage);

        foreach ($appointments as &$appointmentRecord) {
            $appointmentRecord['time'] = timeSlots($appointmentRecord['timeSlot']);
            $phpdate = strtotime($appointmentRecord['appointmentDate']);
            $appointmentRecord += ['dateTime' => date('Y-M-d', $phpdate) . " " . timeSlots($appointmentRecord['timeSlot'])]; //FIXME
        }
        return $this->view->render($response, '/doctor/_previousappointments.html.twig', ['appointments' => $appointments]);
    });

    $app->get('/patientfile/{id:[0-9]+}', function ($request, $response, $args) {
        global $appointmentsPerPage;
        $appointmentsPerPage = 4;
        //patient info
        $id = $args['id'];
        $patientRecord = DB::queryFirstRow("SELECT firstName, lastName, dateOfBirth, id, photo FROM users where id = %d", $id);
        if (!$patientRecord) {
            throw new \Slim\Exception\NotFoundException($request, $response);
        }

        //appointments per page
        $appointmentsCount = DB::queryFirstField("SELECT COUNT(*) AS `count` FROM bookingslots WHERE patientId = %i", $id);
        $maxPages = ceil($appointmentsCount / $appointmentsPerPage);

        $doctorFiles = DB::query("SELECT * FROM patientfiles WHERE patientId = %s AND (uploadedBy = 'doctor' OR uploadedBy = 'admin') AND `file` != 'NULL'", $id);
        $patientFiles = DB::query("SELECT * FROM patientfiles WHERE patientId = %s AND uploadedBy = 'patient'", $id);
        if ($patientRecord['photo']) {
            $patientRecord['photo'] = "/files/profile/" . $patientRecord['id'];
        }
        foreach ($doctorFiles as &$file) {
            $file['file'] = "/files/patientfiles/" . $file['id'];
        }
        foreach ($patientFiles as &$file) {
            $file['file'] = "/files/patientfiles/" . $file['id'];
        }

        return $this->view->render($response, '/doctor/patientfile.html.twig', ['patient' => $patientRecord, 'doctorFiles' => $doctorFiles, 'patientFiles' => $patientFiles, 'maxPages' => $maxPages]);
    });

    $app->get('/{form}/{id:[0-9]+}', function ($request, $response, $args) {
        $id = $args['id'];
        $patientRecord = DB::queryFirstRow("SELECT firstName, lastName, dateOfBirth, id, photo FROM users where id = %d", $id);
        if (!$patientRecord) {
            throw new \Slim\Exception\NotFoundException($request, $response);
        }
        if ($args['form'] == 'referral') {
            return $this->view->render($response, '/doctor/createreferral.html.twig', ['patient' => $patientRecord]);
        } else if ($args['form'] == 'uploadfile') {
            return $this->view->render($response, '/doctor/uploadfile.html.twig', ['patient' => $patientRecord]);
        } else if ($args['form'] == 'createprescription') {
            return $this->view->render($response, '/doctor/createprescription.html.twig', ['patient' => $patientRecord]);
        }else if ($args['form'] == 'medications'){
            $medsCount = DB::queryFirstField("SELECT COUNT(*) AS `count` FROM medications WHERE patientId = %i", $id);
            $maxPages = ceil($medsCount / 6);
            return $this->view->render($response, '/doctor/medicationrecords.html.twig', [ 'patient' => $patientRecord, 'maxPages' => $maxPages]);
        }else if ($args['form'] == 'referrals'){
            $refCount = DB::queryFirstField("SELECT COUNT(*) AS `count` FROM referrals WHERE patientId = %i", $id);
            $maxPages = ceil($refCount / 6);
            return $this->view->render($response, '/doctor/referralrecords.html.twig', ['patient' => $patientRecord, 'maxPages' => $maxPages]);
        } else {
            throw new \Slim\Exception\NotFoundException($request, $response);
        }
    });

    $app->get('/viewallpatients', function($request, $response, $args){
        $patientsCount = DB::queryFirstField("SELECT COUNT(*) AS `count` FROM users WHERE `role` = 'patient' AND familyDoctorId = %i", $_SESSION['user']['id']);
        $maxPages = ceil($patientsCount / 6);
        return $this->view->render($response, '/doctor/allpatients.html.twig', ['maxPages' => $maxPages]);
    });

    $app->get('/api/patients', function($request, $response, $args){
        global $patPerPage;
        $patPerPage = 6;
        $queryParams = $request->getQueryParams();
        $pageNo = isset($queryParams['patPage']) ? $queryParams['patPage'] : 1;
        $patients = DB::query("SELECT * FROM users WHERE `role` = 'patient' and familyDoctorId = %i ORDER BY lastName LIMIT %d OFFSET %d", $_SESSION['user']['id'], $patPerPage, ($pageNo - 1) * $patPerPage);
        return $this->view->render($response, '/doctor/_viewallpatients.html.twig', ['patients' => $patients]);
    });

    $app->get('/api/referrals/{id:[0-9]+}', function($request, $response, $args){
        $id = $args['id'];
        $patientRecord = DB::queryFirstRow("SELECT firstName, lastName, dateOfBirth, id, photo FROM users where id = %d", $id);
        if (!$patientRecord) {
            throw new \Slim\Exception\NotFoundException($request, $response);
        }
        global $refPerPage;
        $refPerPage = 6;
        $queryParams = $request->getQueryParams();
        $pageNo = isset($queryParams['refPage']) ? $queryParams['refPage'] : 1;
        $referrals = DB::query("SELECT * FROM referrals WHERE patientId = %i ORDER BY dateReferred DESC LIMIT %d OFFSET %d", $id, $refPerPage, ($pageNo - 1) * $refPerPage);
        return $this->view->render($response, '/doctor/_referralrecords.html.twig', ['referrals' => $referrals]);
    });

    $app->get('/api/medications/{id:[0-9]+}', function ($request, $response, $args) {
        $id = $args['id'];
        $patientRecord = DB::queryFirstRow("SELECT firstName, lastName, dateOfBirth, id, photo FROM users where id = %d", $id);
        if (!$patientRecord) {
            throw new \Slim\Exception\NotFoundException($request, $response);
        }
        global $medsPerPage;
        $medsPerPage = 6;
        $queryParams = $request->getQueryParams();
        $pageNo = isset($queryParams['medPage']) ? $queryParams['medPage'] : 1;
        $medications = DB::query("SELECT * FROM medications WHERE patientId = %i ORDER BY prescribedOn DESC LIMIT %d OFFSET %d", $id, $medsPerPage, ($pageNo - 1) * $medsPerPage);
        return $this->view->render($response, '/doctor/_medicationrecords.html.twig', ['medications' => $medications]);
    });


    $app->get('/calendar', function ($request, $response, $args) {
        return $this->view->render($response, '/doctor/calendar.html.twig');
    });

    //--------------
    //post controllers

    $app->post('/appointmentform/{apptId:[0-9]+}', function ($request, $response, $args) {
        $appointmentId = $args['apptId'];
        $appointment = DB::queryFirstRow("SELECT * FROM bookingslots WHERE id = %i", $appointmentId);
        if (!$appointment) {
            throw new \Slim\Exception\NotFoundException($request, $response);
        }
        $json = $request->getBody();
        $notes = json_decode($json, TRUE);
        if (($result = validateNotes($notes)) !== TRUE) {
            $response = $response->withStatus(400);
            $response->getBody()->write(json_encode($result));
            return $response;
        }
        $insert = [
            'patientId' => $appointment['patientId'],
            'doctorId' => $_SESSION['user']['id'],
            'uploadedBy' => 'doctor',
            'appointmentId' => $appointmentId,
            'doctorsNotes' => $notes['doctorsNotes']
        ];
        DB::insert('patientfiles', $insert);
        setFlashMessage("Patient file has been updated to include your notes");
        DB::update('bookingslots', ['status' => 'completed'], "id=%i", $appointmentId);
        $response = $response->withStatus(201);
        return $response->getBody()->write(json_encode(TRUE));
    });

    $app->post('/appointmentform/{apptId:[0-9]+}/start', function ($request, $response, $args) {
        $appointmentId = $args['apptId'];
        $appointment = DB::queryFirstRow("SELECT * FROM bookingslots WHERE id = %i", $appointmentId);
        if (!$appointment) {
            throw new \Slim\Exception\NotFoundException($request, $response);
        }
        $json = $request->getBody();
        $start = json_decode($json, TRUE);
        if (($result = validateStartApt($start)) !== TRUE) {
            $response = $response->withStatus(400);
            $response->getBody()->write(json_encode($result));
            return $response;
        }
        $insert = [
            'status' => $start['status'],
            'consultationTime' => date("Y-m-d H:i:s")
        ];
        DB::update('bookingslots', $insert, "id=%i", $appointmentId);
        $response = $response->withStatus(201);
        return $response->getBody()->write(json_encode(TRUE));
    });

    $app->post('/walkinform/{walkinId:[0-9]+}', function ($request, $response, $args) {
        $walkinId = $args['walkinId'];
        $walkin = DB::queryFirstRow("SELECT * FROM walkins WHERE id = %i", $walkinId);
        if (!$walkin) {
            throw new \Slim\Exception\NotFoundException($request, $response);
        }
        $json = $request->getBody();
        $notes = json_decode($json, TRUE);
        if (($result = validateNotes($notes)) !== TRUE) {
            $response = $response->withStatus(400);
            $response->getBody()->write(json_encode($result));
            return $response;
        }
        $insert = [
            'patientId' => $walkin['patientId'],
            'doctorId' => $_SESSION['user']['id'],
            'uploadedBy' => 'doctor',
            'walkinId' => $walkinId,
            'doctorsNotes' => $notes['doctorsNotes']
        ];
        DB::insert('patientfiles', $insert);
        setFlashMessage("Patient file has been updated to include your notes", "success");
        DB::update('walkins', ['activeStatus' => 'INACTIVE', 'doctorId' => $_SESSION['user']['id']], "id=%i", $walkinId);
        $response = $response->withStatus(201);
        return $response->getBody()->write(json_encode(TRUE));
    });


    $app->post('/walkinform/{walkinId:[0-9]+}/start', function ($request, $response, $args) {
        $walkinId = $args['walkinId'];
        $walkin = DB::queryFirstRow("SELECT * FROM walkins WHERE id = %i", $walkinId);
        if (!$walkin) {
            throw new \Slim\Exception\NotFoundException($request, $response);
        }
        $json = $request->getBody();
        $start = json_decode($json, TRUE);
        if (($result = validateStartWalkin($start)) !== TRUE) {
            $response = $response->withStatus(400);
            $response->getBody()->write(json_encode($result));
            return $response;
        }
        $insert = [
            'activeStatus' => $start['activeStatus'],
            'consultationTime' => date("Y-m-d H:i:s")
        ];
        DB::update('walkins', $insert, "id=%i", $walkinId);
        $response = $response->withStatus(201);
        return $response->getBody()->write(json_encode(TRUE));
    });

    $app->post('/uploadfile/{id:[0-9]+}', function ($request, $response, $args) {
        $id = $args['id'];
        $result = DB::queryFirstRow("SELECT firstName, lastName, dateOfBirth, id FROM users where id = %d", $id);
        if (!$result) {
            throw new \Slim\Exception\NotFoundException($request, $response);
        }

        $doctorsNotes = $request->getParam('doctorsNotes');
        $description = $request->getParam('description');
        $form = ['doctorsNotes' => $doctorsNotes, 'description' => $description];
        $errorlog = [];

        $file = $_FILES['file'];

        if ($file['error'] !== 4) {

            $fileName = null;
            $result = verifyUploadedFile($file, $fileName);
            if ($result !== TRUE) {
                $errorlog[] = $result;
            }

            $result = verifyFileNameExists($id, $fileName);
            if ($result !== TRUE) {
                $errorlog[] = $result;
            }

            $file = file_get_contents($file['tmp_name']);

            $result = verifyFileDescription($description);
            if ($result !== TRUE) {
                $errorlog[] = $result;
            }
        }
         else {
            $file = null;
            if(strlen($description) > 0) {
                $errorlog[] = "You must upload a file along with a file description.";
            }
        }

        if (strlen($doctorsNotes) > 3000) {
            $errorlog[] = "Doctor's note must be under 3000 characters";
        }
        // if ($file && strlen($description) < 5) {
        //     $errorlog[] = "Please include a description of the file";
        // }
        // if (strlen($description) > 250) {
        //     $errorlog[] = "The description must be under 250 characters";
        // }
        if (!$errorlog) {
            DB::insert('patientfiles', ['patientId' => $id, 'doctorsNotes' => $doctorsNotes, 'description' => $description, 'file' => $file, 'doctorId' => $_SESSION['user']['id'], 'uploadedBy' => 'doctor', 'fileName' => $fileName]);
            //redirect to patient file with flash message
            setFlashMessage("You have uploaded a file to " . $result['lastName'] . ", " . $result['firstName'] . ".");
            return $response->withRedirect("/doctor/patientfile/{$id}");
        } else {
            return $this->view->render($response, '/doctor/uploadfile.html.twig', ['patient' => $result, 'form' => $form, 'errors' => $errorlog]);
        }
    });

    $app->post('/createprescription/{id:[0-9]+}', function ($request, $response, $args) {
        $id = $args['id'];
        $result = DB::queryFirstRow("SELECT firstName, lastName, dateOfBirth, id FROM users where id = %d", $id);
        if (!$result) {
            throw new \Slim\Exception\NotFoundException($request, $response);
        }
        $medicationName = $request->getParam('medicationName');
        $dosage = $request->getParam('dosage');
        $numberOfRefills = $request->getParam('numberOfRefills');
        $instructions = $request->getParam('instructions');
        $prescribedOn = $request->getParam('prescribedOn');
        $values = [
            'medicationName' => $medicationName,
            'dosage' => $dosage,
            'numberOfRefills' => $numberOfRefills,
            'instructions' => $instructions,
            'prescribedOn' => $prescribedOn,
            'patientId' => $id,
            'doctorId' => $_SESSION['user']['id']
        ];
        $errorlog = [];
        //validate
        prescriptionValidate($values, $errorlog);
        if (!$errorlog) {
            DB::insert('medications', $values);
            //redirect to patient file with flash message
            setFlashMessage("You have recorded a medication for " . $result['lastName'] . ", " . $result['firstName'] . ".", "success");
            return $response->withRedirect("/doctor/patientfile/{$id}");
        }
        return $this->view->render($response, '/doctor/createprescription.html.twig', ['patient' => $result, 'values' => $values, 'errors' => $errorlog]);
    });

    $app->post('/appointmentprescription/{id:[0-9]+}', function ($request, $response, $args) {
        $id = $args['id'];
        $result = DB::queryFirstRow("SELECT firstName, lastName, dateOfBirth, id FROM users where id = %d", $id);
        if (!$result) {
            throw new \Slim\Exception\NotFoundException($request, $response);
        }
        $json = $request->getBody();
        $record = json_decode($json, TRUE);
        $record += ['patientId' => $id];
        $record += ['doctorId' => $_SESSION['user']['id']];
        $errorlog = [];
        //validate
        prescriptionValidate($record, $errorlog);
        if (!$errorlog) {
            DB::insert('medications', $record);
            $json = json_encode(TRUE, JSON_PRETTY_PRINT); // true or false
            $response = $response->withStatus(201);
            return $response->getBody()->write($json);
        }
        $response = $response->withStatus(400);
        return $response->getBody()->write(json_encode($errorlog));
    });

    $app->post('/referral/{id:[0-9]+}', function ($request, $response, $args) {
        $id = $args['id'];
        $result = DB::queryFirstRow("SELECT firstName, lastName, dateOfBirth, id FROM users where id = %d", $id);
        if (!$result) {
            throw new \Slim\Exception\NotFoundException($request, $response);
        }

        $specialistField = $request->getParam('specialistField');
        $dateReferred = $request->getParam('dateReferred');
        $priority = $request->getParam('priority');

        $values = [
            'specialistField' => $specialistField,
            'dateReferred' => $dateReferred,
            'priority' => $priority,
            'patientId' => $id,
            'doctorId' => $_SESSION['user']['id']
        ];
        $errorlog = [];
        //validate
        referralValidate($values, $errorlog);
        if (!$errorlog) {
            DB::insert('referrals', $values);
            //redirect to patient file with flash message
            setFlashMessage("You have recorded a referral for " . $result['lastName'] . ", " . $result['firstName'] . ".");
            return $response->withRedirect("/doctor/patientfile/{$id}");
        }
        return $this->view->render($response, '/doctor/createreferral.html.twig', ['patient' => $result, 'values' => $values, 'errors' => $errorlog]);
    });

    $app->post('/appointmentreferral/{id:[0-9]+}', function ($request, $response, $args) {
        $id = $args['id'];
        $result = DB::queryFirstRow("SELECT firstName, lastName, dateOfBirth, id FROM users where id = %d", $id);
        if (!$result) {
            throw new \Slim\Exception\NotFoundException($request, $response);
        }

        $json = $request->getBody();
        $record = json_decode($json, TRUE);
        $record += ['patientId' => $id];
        $record += ['doctorId' => $_SESSION['user']['id']];
        $errorlog = [];
        //validate
        referralValidate($record, $errorlog);
        if (!$errorlog) {
            DB::insert('referrals', $record);
            $response = $response->withStatus(201);
            $response = $response->withJson(json_encode(TRUE));
            return $response;
        }
        $response = $response->withStatus(400);
        return $response->getBody()->write(json_encode($errorlog));
    });

    $app->get('/modifyprofile', function ($request, $response, $args) {
        $id = $_SESSION['user']['id'];
        $profile = DB::queryFirstRow("SELECT * FROM users WHERE id=%s", $id);
        $profile['photo'] = nullify($profile['photo']);
        if ($profile['photo']) {
            $profile['photo'] = "/files/profile/" . $id;
        } else {
            $profile['photo'] = "/images/no_photo.png";
        }
        if (isset($profile['phone'])) {
            $profile['phone'] = formatPhone($profile['phone']);
        }
        return $this->view->render($response, "doctor/modify.html.twig", ['profile' => $profile]);
    });

    $app->post('/modify/formfield', function ($request, $response, $args) { //FIXME: no server side validation and no password hashing
        $userId = json_decode($request->getParam('userId'), true);
        $field = $request->getParam('field');

        switch ($field) {
            case 'password':
                /* PREVENT CHANGES TO DEMO ACCOUNTS */
                if(in_array($userId, DEMO_IDS)){
                    return $response->write("We're sorry. This is a demo profile, and the password cannot be changed");
                }
                /* -- */
                $newValue = validatePassword($request->getParam('newValue'));
                break;
            case 'address':
                $newValue = validateAddress($request->getParam('newValue'));
                break;
            case 'phone':
                $newValue = validatePhone($request->getParam('newValue'));
                break;
        }
        if ($newValue === false) {
            return $response->write("Invalid input");
        }
        $insert = DB::update('users', [$field => $newValue], 'id=%i', $userId);
        if ($insert) {
            $response = $response->withStatus(200);
            return $response->write("Record successfully changed. " . $newValue);
        } else {
            return $response->write("There was an error. Please refresh the page. Status: ");
        }
    });
})->add(function ($request, $response, $next) {
    if (!$_SESSION['user']) {
        return $response->withRedirect("/");
    } else if ($_SESSION['user']['role'] != "doctor") {
        return $response->withRedirect("/");
    }
    $response = $next($request, $response);

    return $response;
});

//-----------
//validattion methods

function referralValidate($values, &$errorlog)
{
    if (strlen($values['specialistField']) < 4) {
        $errorlog[] = "The specialization field must be at least 4 characters";
    }
    $dateReferredTS = strtotime($values['dateReferred']);
    if ($dateReferredTS > time() || !$dateReferredTS) {
        $errorlog[] = "Date referred is not valid";
    }
}

function prescriptionValidate($values, &$errorlog)
{
    if (strlen($values['medicationName'])  < 3 || strlen($values['medicationName'])  > 150) {
        $errorlog[] = "The name of the medication should be between 3 and 150 characters" . $values['medicationName'];
    }
    if (strlen($values['dosage']) > 50 || strlen($values['dosage']) < 3) {
        $errorlog[] = "The dosage must be at least 3 characters and no more than 50 characters" .  $values['dosage'];
    }
    if ($values['numberOfRefills'] > 12 || $values['numberOfRefills'] < 0) {
        $errorlog[] = "Number of refills must be 0 to 12";
    }
    if (strlen($values['instructions']) < 10 || strlen($values['instructions']) > 300) {
        $errorlog[] = "The instructions must be 10 to 300 characters long" . $values['instructions'];
    }
    $prescribedOnTS = strtotime($values['prescribedOn']);
    if ($prescribedOnTS > time() || $prescribedOnTS == false) {
        $errorlog[] = "Prescribed on date is not valid";
    }
}

function validateNotes($notes)
{
    if ($notes === NULL) {
        return "Invalid JSON data provided";
    }
    $expectedFields = ['doctorsNotes'];
    $formFields = array_keys($notes);
    if ($diff = array_diff($formFields, $expectedFields)) {
        return "Invalid fields in notes: [" . implode(',', $diff) . "]";
    }
    if ($diff = array_diff($expectedFields, $formFields)) {
        return "Missing fields in notes: [" . implode(',', $diff) . "]";
    }
    if (isset($notes['doctorsNotes'])) {
        if (strlen($notes['doctorsNotes']) < 20) {
            return "Please add more complete notes to this appointment";
        }
    }
    return TRUE;
}

function validateStartApt($start)
{
    if ($start === NULL) {
        return "Invalid JSON data provided";
    }
    $expectedFields = ['status'];
    $formFields = array_keys($start);
    if ($diff = array_diff($formFields, $expectedFields)) {
        return "Invalid fields in start: [" . implode(',', $diff) . "]";
    }
    if ($diff = array_diff($expectedFields, $formFields)) {
        return "Missing fields in start: [" . implode(',', $diff) . "]";
    }

    $statusValues = ['upcoming', 'in progress', 'completed'];
    if (!in_array($start['status'], $statusValues)) {
        return "Invalid status";
    }
    return TRUE;
}

function validateStartWalkin($start)
{
    if ($start === NULL) {
        return "Invalid JSON data provided";
    }
    $expectedFields = ['activeStatus'];
    $formFields = array_keys($start);
    if ($diff = array_diff($formFields, $expectedFields)) {
        return "Invalid fields in start: [" . implode(',', $diff) . "]";
    }
    if ($diff = array_diff($expectedFields, $formFields)) {
        return "Missing fields in start: [" . implode(',', $diff) . "]";
    }
    $statusValues = ['ACTIVE', 'IN PROGRESS', 'INACTIVE'];
    if (!in_array($start['activeStatus'], $statusValues)) {
        return "Invalid status";
    }
    return TRUE;
}

