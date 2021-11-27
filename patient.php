<?php

require_once 'vendor/autoload.php';

require_once 'init.php';

require_once 'utils.php';

$filesPerPage = 3;



$app->group('/patient', function ($app) use ($log) {

    $app->get('[/]', function ($request, $response, $args) use ($log) {

        $id = $_SESSION['user']['id'];

        $appointments = DB::query("SELECT b.id, b.timeSlot, b.appointmentDate, appointmentType, `status`, `description`, `type`, 
        firstName, lastName FROM bookingslots b, users u where b.doctorId = u.id AND b.patientId = %i AND `status` = 'upcoming'", $id);

        if($appointments){
            foreach ($appointments as &$appointmentRecord) {
                $phpdate = strtotime($appointmentRecord['appointmentDate']);
                $appointmentRecord['time'] = timeSlots($appointmentRecord['timeSlot']); 
                $appointmentRecord += ['date' => date('M d, Y', $phpdate)];
            }
        }
        //TODO: CUTOFF FOR DESCRIPTION
        $walkin = DB::queryFirstRow("SELECT * FROM `walkins` WHERE patientId = %i AND activeStatus = 'active' AND DATE(queueStart) = CURRENT_DATE()", $id);

        return $this->view->render($response, "patient/patient.html.twig", ['records' => $appointments, 'walkin' => $walkin]);
    });

    $app->get('/account/api', function ($request, $response, $args) use ($log) {

        $id = $_SESSION['user']['id'];
        $doctorId = $_SESSION['user']['familyDoctorId'];

        $walkin = DB::queryFirstRow("SELECT * FROM `walkins` WHERE patientId = %i AND DATE(queueStart) = CURRENT_DATE()", $id);

        if(!$walkin){
            $json = json_encode(FALSE); // true or false
            return $response->getBody()->write($json);
        }
        
        $walkinId = $walkin['id'];
        $description = $walkin['description'];
        $activeStatus = $walkin['activeStatus'];
        $patientWaitTime = patientWalkinWaitTime($walkinId);
        $log->info(sprintf("Patient wait time %i", $patientWaitTime, $_SERVER['REMOTE_ADDR']));
        $position = findQueuePosition($walkinId);
        $walkinWaitTime = averageWaitTimeWalkin();

        $stats = ['position' => $position, 'walkinWaitTime' => $walkinWaitTime, 'description' => $description, 
        'activeStatus' => $activeStatus, 'patientWaitTime' => $patientWaitTime];

        return $response->getBody()->write(json_encode($stats));
    });

    $app->get('/account/api/chart', function ($request, $response, $args) use ($log) {
        $id = $_SESSION['user']['id'];
        $appointmentTypes = DB::query("SELECT count(*) as `count`, appointmentType FROM `bookingslots` 
        WHERE patientId = %i GROUP BY appointmentType;", $id);
        if( $appointmentTypes){
            return $response->getBody()->write(json_encode($appointmentTypes));
        }return $response->getBody()->write(json_encode(false));
        
    });

    $app->get('/account/api/walkinchart', function ($request, $response, $args) use ($log) {
        $id = $_SESSION['user']['id'];
        $appointmentTypes = DB::query("SELECT count(*) as `count`, appointmentType FROM walkins WHERE patientId = %i 
        GROUP BY appointmentType;", $id);
        if( $appointmentTypes){
            return $response->getBody()->write(json_encode($appointmentTypes));
        }return $response->getBody()->write(json_encode(false));
    });


    $app->get('/account/update', function ($request, $response, $args) use ($log) {

        $id = $_SESSION['user']['id'];
        $patient = DB::queryFirstRow("SELECT * FROM users WHERE id=%i", $id);
        if ($patient) {
            if(isset($patient['familyDoctorId'])){
                $patient = DB::queryFirstRow("SELECT a.id, a.address, a.firstName, a.lastName, a.email, a.familyDoctorId, a.healthCardNo, a.phone, a.photo, a.dateOfBirth, b.firstName as doctorFirstName, 
                b.lastName as doctorLastName FROM `users` a, users b WHERE a.familyDoctorId = b.id and a.id=%i", $id);
            }
            return $this->view->render($response, 'patient/account_update.html.twig', ['p' => $patient]);
        } else { // not found - cause 404 here
            $log->info(sprintf("Patient id %i does not exist from %s", $id, $_SERVER['REMOTE_ADDR']));
            throw new \Slim\Exception\NotFoundException($request, $response);
        }
    });

    $app->post('/account/update', function ($request, $response, $args) use ($log) {
        $id = $_SESSION['user']['id'];
        $address = strip_tags($request->getParam('address'));
        $phone = $request->getParam('phone');
        $email = $request->getParam('email');
        $pass1 = $request->getParam('pass1');
        $pass2 = $request->getParam('pass2');
        $errorList = [];

        $patient = DB::queryFirstRow("SELECT * FROM users WHERE id=%i", $id);

        if (!$patient) {
            throw new \Slim\Exception\NotFoundException($request, $response);
        }

        $result = verifyPhoneNo($phone);
        if ($result !== TRUE) {
            $errorList[] = $result;
        }

        if (!$address) {
            $errorList[] = "You must provide an address";
        }
        if (!$email) {
            $errorList[] = "You must provide an email";
        } else if (in_array($id, DEMO_IDS)) { // PREVENT CHANGES TO DEMO ACCOUNTS
            $oldemail = DB::queryFirstField("SELECT email FROM users WHERE id=%i", $id);
            if($email != $oldemail){
                $errorList[] = "We're sorry. This is a demo profile, and the email address cannot be changed";
            }
        }

        $result = verifyEmailQuality($email);
        if ($result !== TRUE) {
            $errorList[] = $result;
        }

        if ($pass1 || $pass2) {
            $result = verifyPasswordQuality($pass1, $pass2);
            if (in_array($id, DEMO_IDS)){ // PREVENT CHANGES TO DEMO ACCOUNTS
                $errorList[] = "We're sorry. This is a demo profile, and the password cannot be changed";
            }
            if ($result !== TRUE) {
                $errorList[] = $result;
            }
        }

        if ($errorList) { // STATE 2: errors
            $log->info(sprintf("Patient %d account update failed from %s", $id, $_SERVER['REMOTE_ADDR']));
            return $this->view->render($response, 'patient/account_update.html.twig', ['errorList' => $errorList, 'p' => $patient]);
        } else {
            if (!$pass1) {
                $valuesList = ['address' => $address, 'phone' => $phone, 'email' => $email
            ];
            } else {
                $passwordHash = password_hash($pass1, PASSWORD_DEFAULT);
                $valuesList = ['address' => $address, 'phone' => $phone, 'email' => $email, 'password' =>  $passwordHash
            ];
            }
            DB::update('users', $valuesList, "id=%i", $id);
            $log->debug(sprintf("Patient info with id=%d updated", $id));
            // return $this->view->render($response, 'patient/register_success.html.twig');
            setFlashMessage("Your account info was updated successfully!", "success");
            return $response->withRedirect("/patient");
        }
    });


    // prescriptions
    $app->get('/prescriptions[/{pageNo:[0-9]+}]', function ($request, $response, $args)  use ($log) {
        global $filesPerPage;
        $pageNo = $args['pageNo'] ?? 1;
        $id = $_SESSION['user']['id'];
        $prescriptionsCount = DB::queryFirstField("SELECT COUNT(*) AS COUNT FROM medications where patientId = %i;", $id);
        $prescriptions = DB::query("SELECT u.firstName, u.lastName, m.dosage, m.instructions, m.medicationName, m.numberOfRefills, m.prescribedOn 
                        FROM `users` as u, `medications`as m WHERE m.doctorId = u.id AND m.patientId=%i ORDER BY m.id DESC LIMIT %d OFFSET %d",
                         $id, $filesPerPage, ($pageNo - 1) * $filesPerPage);

        $maxPages = ceil($prescriptionsCount / $filesPerPage);
        $prevNo = ($pageNo > 1) ? $pageNo-1 : '';
        $nextNo = ($pageNo < $maxPages) ? $pageNo+1 : '';                 
        
        return $this->view->render($response, 'patient/prescriptions.html.twig', [
            'prescriptions' => $prescriptions,
            'maxPages' => $maxPages,
            'pageNo' => $pageNo,
            'prevNo' => $prevNo,
            'nextNo' => $nextNo
        ]);

    });

    $app->get('/prescriptions/{search}', function ($request, $response, $args)  use ($log) {
        $id = $_SESSION['user']['id'];
        $search = $args['search'];

        $prescriptions = DB::query("SELECT u.firstName, u.lastName, m.dosage, m.instructions, m.medicationName, m.numberOfRefills, m.prescribedOn 
        FROM `users` as u, `medications`as m WHERE m.doctorId = u.id AND m.patientId=%i AND m.medicationName LIKE %ss ORDER BY m.id DESC", $id, $search);

        if (!$prescriptions) {
            $log->debug(sprintf("prescription query empty"));
            return $response->getBody()->write(json_encode(FALSE));
        } else {
            return $response->getBody()->write(json_encode($prescriptions));
        }
    });

    //referalls

    $app->get('/referrals[/{pageNo:[0-9]+}]', function ($request, $response, $args)  use ($log) {
        $id = $_SESSION['user']['id'];
        global $filesPerPage;
        $pageNo = $args['pageNo'] ?? 1;
        $referralsCount = DB::queryFirstField("SELECT COUNT(*) AS COUNT FROM referrals where patientId = %i;", $id);
        $referrals = DB::query("SELECT u.firstName, u.lastName, r.dateReferred, r.priority, r.specialistField
        FROM `users` as u, `referrals`as r WHERE r.doctorId = u.id AND r.patientId=%i ORDER BY r.id DESC LIMIT %d OFFSET %d", 
        $id, $filesPerPage, ($pageNo - 1) * $filesPerPage );

        $maxPages = ceil($referralsCount / $filesPerPage);
        $prevNo = ($pageNo > 1) ? $pageNo-1 : '';
        $nextNo = ($pageNo < $maxPages) ? $pageNo+1 : ''; 

        return $this->view->render($response, 'patient/referrals.html.twig', ['referrals' => $referrals,
            'maxPages' => $maxPages,
            'pageNo' => $pageNo,
            'prevNo' => $prevNo,
            'nextNo' => $nextNo 
        ]);

    });

    $app->get('/referrals/{search}', function ($request, $response, $args)  use ($log) {
        $id = $_SESSION['user']['id'];
        $search = $args['search'];

        $referrals = DB::query("SELECT u.firstName, u.lastName, r.dateReferred, r.priority, r.specialistField
    FROM `users` as u, `referrals`as r WHERE r.doctorId = u.id 
    AND r.patientId=%i AND r.specialistField LIKE %ss;", $id, $search);


        if (!$referrals) {
            $log->debug(sprintf("referrals query empty"));
            return $response->getBody()->write(json_encode(FALSE));
        } else {
            return $response->getBody()->write(json_encode($referrals));
        }
    });

    //book appointments
    $app->get('/bookappointment', function ($request, $response, $args)  use ($log) {
        if ($_SESSION['user']['familyDoctorId'] == NULL && $_SESSION['user']['role'] == "patient") {
            setFlashMessage("Only patients with family doctors may book appointments", "danger");
            return $response->withRedirect("/patient");
        }
        return $this->view->render($response, "patient/bookappointment.html.twig");
    });

    $app->post('/bookappointment', function ($request, $response, $args)  use ($log) {
        $id = $_SESSION['user']['id'];
        $doctorId = $_SESSION['user']['familyDoctorId'];
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

            $result = verifyFileNameExists($id, $fileName);
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

        // if(!$file){
        //     $file = null;
        //     if(strlen($fileDescription) > 0) {
        //         $errorList[] = "You must upload a file along with a file description.";
        //     }

        // } else if ($file['error'] !== 4) {

        //     $fileName = null;
        //     $result = verifyUploadedFile($file, $fileName);
        //     if ($result !== TRUE) {
        //         $errorList[] = $result;
        //     }
        //     $result = verifyFileNameExists($id, $fileName);
        //         if ($result !== TRUE) {
        //             $errorList[] = $result;
        //     }

        //     $file = file_get_contents($file['tmp_name']);

        //     $result = verifyFileDescription($fileDescription);
        //     if ($result !== TRUE) {
        //         $errorList[] = $result;
        //     }

        // }
        $valuesList = ['reason' => $reason, 'fileDescription' => $fileDescription];

        if ($errorList) {
            return $this->view->render($response, '/patient/bookappointment.html.twig', ['v' => $valuesList, 'errorList' => $errorList]);
        } else {

            $bookingSlotValues = [
                'timeSlot' => $timeSlot, 'patientId' => $id, 'doctorId' => $doctorId,
                'appointmentType' =>  $appointmentType, 'description' => $reason, 'type' => $type,
                'doctorScheduleId' => $doctorScheduleId, 'appointmentDate' => $timeSlotDate
            ];

            $message = "Your appointment has been booked.";
            if($type == 'virtual'){
                $start_time = $timeSlotDate . "T" . timeSlotsFormatted($timeSlot);
                $topic = "Appointment at Click Clinic";
                $meetingInfo = createZoomMeeting($start_time, $topic);
                $meetingId = $meetingInfo['meetingId'];
                $bookingSlotValues += ['meetingId' => $meetingId];
            }
            DB::insert('bookingslots', $bookingSlotValues);
            $date = date("Y-m-d");

            if ($file !== NULL) {
                $appointmentId = DB::insertId();

                $patientFileValues = [
                    'patientId' => $id, 'doctorId' => $doctorId, 'uploadedBy' =>  $_SESSION['user']['role'],
                    'file' => $file, 'appointmentId' => $appointmentId, 'description' => $fileDescription,
                    'date' => $date, 'fileName' => $fileName
                ];

                DB::insert('patientfiles', $patientFileValues);
                $message = $message . " Your file has also been uploaded.";

            }
            setFlashMessage($message, "success");
            return $response->withRedirect("/patient");
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
            setFlashMessage("Please try again between 8am and 3pm on weekdays");
            return $response->withRedirect("/patient");
        }

        if(capacity(walkinNumOfDoctors() == 0)) {
            $message = "No doctors are available today for walkins.";
            setFlashMessage($message, "danger");
            return $response->withRedirect("/patient");
    
        }

        if(walkinAvailableSpots() == 0){
            $message = "No more spots available in the walkin clinic for today. Please try again tomorrow. ";
            setFlashMessage($message, "danger");
            return $response->withRedirect("/patient");
        }

        return $this->view->render($response, "patient/walkinregister.html.twig");
    });


    $app->post('/walkinregister', function ($request, $response, $args)  use ($log) {
        $id = $_SESSION['user']['id'];
        $type = $request->getParam('type');
        $reason = strip_tags($request->getParam('reason'));
        $appointmentType = $request->getParam('appointmentType');
        $fileDescription = strip_tags($request->getParam('fileDescription'));
        $errorList = [];
 
        //verify if the patient has already registered for the day
        $result = DB::queryFirstField("SELECT id FROM walkins WHERE patientId = %i AND DATE(queueStart) = CURRENT_DATE()", $id);
        if ($result) {
            $errorList[] = "You have already been registered for today's walkin clinic";
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

            $result = verifyFileNameExists($id, $fileName);
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

        $valuesList = ['reason' => $reason, 'fileDescription' => $fileDescription];

        if ($errorList) {
            return $this->view->render($response, '/patient/walkinregister.html.twig', ['v' => $valuesList, 'errorList' => $errorList]);
        } else {

            $queueStart = date('Y-m-d H:i:s');
            $date = date("Y-m-d");
            $activeStatus = "ACTIVE";

            //insert into walkins
            $walkinValues = [
                'patientId' => $id, 'queueStart' => $queueStart, 'appointmentType' =>  $appointmentType,
                'description' => $reason, 'type' => $type, 'date' => $date, 'activeStatus' => $activeStatus
            ];
            DB::insert('walkins', $walkinValues);

            $walkinId = DB::insertId();
            $queueNumber = findQueuePosition($walkinId);
            $avgWaitTime = averageWaitTimeWalkin();

            $message = "You have been registered for today's walkin clinic.\n You are currently in position "
            . $queueNumber . " of the queue." . "The average wait time is currently " . $avgWaitTime . " minutes.";

            if ($file !== NULL) {
                $patientFileValues = [
                    'patientId' => $id, 'uploadedBy' =>  $_SESSION['user']['role'],
                    'file' => $file, 'walkinId' => $walkinId, 'description' => $fileDescription, 'date' => $date, 'fileName' => $fileName
                ];
                //insert into patient files
                DB::insert('patientfiles', $patientFileValues);
                $message = $message . " Your file has also been uploaded.";
            }
            setFlashMessage($message, "success");
            return $response->withRedirect("/patient");
        }
    });

    //view files

    // $app->get('/viewfiles', function ($request, $response, $args)  use ($log) {

    //     $id = $_SESSION['user']['id'];
    //     $results = DB::query("SELECT p.id, p.date, p.file, p.description, p.uploadedBy, p.fileName, u.firstName as patientFirstName, u.lastName as patientLastName, d.firstName , 
    // d.lastName FROM patientFiles as p, users as u, users as d WHERE p.file IS NOT NULL AND patientId=%i AND p.patientId = u.id AND 
    // u.familyDoctorId = d.id ORDER BY p.id DESC", $id);



    //     foreach ($results as &$file) {
    //         if ($file['file'] !== null) {
    //             $ext = explode(".", $file['fileName'])[1];
    //             $file += ['ext' => $ext];
    //             $file['file'] = "/files/patientfiles/" . $file['id'];
    //         } else {
    //             $file['file'] = "/images/no_photo.png";
    //         }
    //     }

    //     if ($results) {
    //         return $this->view->render($response, 'patient/viewfiles.html.twig', ['results' => $results]);
    //     } else { // not found - cause 404 here
    //         $log->info(sprintf("Failed to retrieve files for Patient %i from %s", $id, $_SERVER['REMOTE_ADDR']));
    //         throw new \Slim\Exception\NotFoundException($request, $response);
    //     }
    // });



    $app->get('/viewfiles/paginated[/{pageNo:[0-9]+}]', function ($request, $response, $args)  use ($log) {
        global $filesPerPage;
        $pageNo = $args['pageNo'] ?? 1;
        $id = $_SESSION['user']['id'];
        $filesCount = DB::queryFirstField("SELECT COUNT(*) AS COUNT FROM patientfiles WHERE patientId=%i AND `file` IS NOT NULL", $id);

        //file is not null
        $results = DB::query(
            "SELECT p.id, p.patientId, p.date, p.file, p.description, p.uploadedBy, p.fileName, p.doctorId, u.firstName as patientFirstName, 
            u.lastName as patientLastName, d.firstName as doctorFirstName, d.lastName as doctorLastName FROM patientfiles as p 
            LEFT JOIN users as d ON d.id=p.doctorId INNER JOIN users as u ON u.id = p.patientId WHERE p.patientId = %i AND p.file IS NOT NULL AND p.fileName IS NOT NULL
            ORDER BY p.id DESC LIMIT %i OFFSET %i",
            $id,
            $filesPerPage,
            ($pageNo - 1) * $filesPerPage
        );



        foreach ($results as &$file) {
            if ($file['file'] !== null) {
                $file['file'] = "/files/patientfiles/" . $file['id'];
                $ext = explode(".", $file['fileName'])[1];
                $file += ['ext' => $ext];
            }
        }

        $maxPages = ceil($filesCount / $filesPerPage);
        $prevNo = ($pageNo > 1) ? $pageNo - 1 : '';
        $nextNo = ($pageNo < $maxPages) ? $pageNo + 1 : '';

        return $this->view->render($response, 'patient/viewfiles_paginated.html.twig', [
                'results' => $results,
                'maxPages' => $maxPages,
                'pageNo' => $pageNo,
                'prevNo' => $prevNo,
                'nextNo' => $nextNo
            ]);
        
    });

    $app->get('/upcomingappointments', function ($request, $response, $args) {
        return $this->view->render($response, "patient/upcomingappointments.html.twig");
    });

    $app->get('/uploadfile/{id:[0-9]+}', function ($request, $response, $args) {
        $appointmentId = $args['id'];
        $id = $_SESSION['user']['id'];
        $result = DB::queryFirstRow("SELECT * FROM bookingslots where id = %i and patientId=%i", $appointmentId, $id);
        if (!$result) {
            throw new \Slim\Exception\NotFoundException($request, $response);
        }
        return $this->view->render($response, "patient/uploadfile.html.twig", ['v' => $result]);
    });

    $app->get('/uploadwalkinfile/{id:[0-9]+}', function ($request, $response, $args) {
        $appointmentId = $args['id'];
        $id = $_SESSION['user']['id'];
        $result = DB::queryFirstRow("SELECT * FROM walkins where id = %i and patientId=%i", $appointmentId, $id);
        if (!$result) {
            throw new \Slim\Exception\NotFoundException($request, $response);
        }
        return $this->view->render($response, "patient/uploadwalkinfile.html.twig", ['v' => $result]);
    });


    $app->post('/uploadfile/{id:[0-9]+}', function ($request, $response, $args) {
        $appointmentId = $args['id'];
        $id = $_SESSION['user']['id'];
        $result = DB::queryFirstRow("SELECT * FROM bookingslots where id = %i  and patientId=%i", $appointmentId, $id);
        if (!$result) {
            throw new \Slim\Exception\NotFoundException($request, $response);
        }
        $errorlog = [];
        $file = $_FILES['file'];
        $fileDescription = strip_tags($request->getParam('fileDescription'));

        $fileName = null;
        $result = verifyUploadedFile($file, $fileName);
        if ($result !== TRUE) {
            $errorList[] = $result;
        }

        $result = verifyFileNameExists($id, $fileName);
        if ($result !== TRUE) {
            $errorList[] = $result;
        }

        if ($file['error'] !== 4){
            $file = file_get_contents($file['tmp_name']);

        }

        $result = verifyFileDescription($fileDescription);
        if ($result !== TRUE) {
            $errorList[] = $result;
        }

        // $appDate = date('M d, Y', strtotime($result['appointmentDate']));
        $valuesList = ['fileDescription' => $fileDescription];

        if ($errorList) {
            return $this->view->render($response, '/patient/uploadfile.html.twig', ['v' => $valuesList, 'errorList' => $errorList]);
        } else {

            $date = date("Y-m-d");
            $patientFileValues = [
                'patientId' => $id, 'uploadedBy' =>  $_SESSION['user']['role'],
                'file' => $file, 'appointmentId' => $appointmentId , 'description' => $fileDescription, 'date' => $date, 'fileName' => $fileName
            ];
            //insert into patient files
            DB::insert('patientfiles', $patientFileValues);
            $message = " Your file has been uploaded.";
            setFlashMessage($message, "success");
            return $response->withRedirect("/patient");

        }

    });

    $app->post('/uploadwalkinfile/{id:[0-9]+}', function ($request, $response, $args) {
        $walkinId = $args['id'];
        $id = $_SESSION['user']['id'];
        $result = DB::queryFirstRow("SELECT * FROM walkins where id = %i  and patientId=%i", $walkinId, $id);
        if (!$result) {
            throw new \Slim\Exception\NotFoundException($request, $response);
        }
        $errorlog = [];
        $file = $_FILES['file'];
        $fileDescription = strip_tags($request->getParam('fileDescription'));

        $fileName = null;
        $result = verifyUploadedFile($file, $fileName);
        if ($result !== TRUE) {
            $errorList[] = $result;
        }

        $result = verifyFileNameExists($id, $fileName);
        if ($result !== TRUE) {
            $errorList[] = $result;
        }

        if ($file['error'] !== 4){
            $file = file_get_contents($file['tmp_name']);

        }

        $result = verifyFileDescription($fileDescription);
        if ($result !== TRUE) {
            $errorList[] = $result;
        }

        // $appDate = date('M d, Y', strtotime($result['appointmentDate']));
        $valuesList = ['fileDescription' => $fileDescription];

        if ($errorList) {
            return $this->view->render($response, '/patient/uploadfile.html.twig', ['v' => $valuesList, 'errorList' => $errorList]);
        } else {

            $date = date("Y-m-d");
            $patientFileValues = [
                'patientId' => $id, 'uploadedBy' =>  $_SESSION['user']['role'],
                'file' => $file, 'walkinId' => $walkinId , 'description' => $fileDescription, 'date' => $date, 'fileName' => $fileName
            ];
            //insert into patient files
            DB::insert('patientfiles', $patientFileValues);
            $message = " Your file has been uploaded.";
            setFlashMessage($message, "success");
            return $response->withRedirect("/patient");
        }

    });

    $app->get('/deletefile/{id:[0-9]+}', function ($request, $response, $args) use ($log) {
        $fileId = $args['id'];
        $id = $_SESSION['user']['id'];
        $result = DB::queryFirstRow("SELECT * FROM patientfiles where id = %d and patientId=%i", $fileId, $id);
        if (!$result) {
            throw new \Slim\Exception\NotFoundException($request, $response);
        }
        DB::delete('patientfiles', "id=%i", $fileId);
        $log->debug("Record patientfiles deleted id=" .$fileId);
        $message = " Your file has been deleted.";
        setFlashMessage($message, "success");
        return $response->withRedirect("/patient");


    });

    

    $app->get('/upcomingappointments/api', function ($request, $response, $args) use ($log) {
        $id = $_SESSION['user']['id'];
        $log->debug($id);

        $appointments = DB::query("SELECT b.id, appointmentDate, appointmentType, b.`description`, b.`type`, b.doctorId, b.status, b.meetingId, f.fileName, f.file, u.firstName, f.id as fileId, f.description as fileDescription, 
        u.lastName, timeSlot FROM bookingslots b LEFT JOIN patientfiles f ON b.id= f.appointmentId INNER JOIN users u ON b.doctorId = u.id WHERE 
        b.patientId = %i AND status = 'upcoming' AND (uploadedBy IS NULL OR uploadedBy != 'doctor')", $id);
      
        if($appointments) {
            foreach ($appointments as &$appointmentRecord) {
                $appointmentRecord['time'] =timeSlots($appointmentRecord['timeSlot']); 
                if ($appointmentRecord['file'] !== null) {
                    $appointmentRecord['file'] = "/files/patientfiles/" . $appointmentRecord['fileId'];
                }
                if($appointmentRecord['type'] == 'virtual'){
                    if($meetingInfo = getZoomMeeting($appointmentRecord['meetingId'])){
                        $appointmentRecord += ['join_url' => $meetingInfo['join_url']];
                    }
                }
            }
            
            return $response->getBody()->write(json_encode($appointments));
        }
        return $response->getBody()->write(json_encode(false));        
    });

    $app->get('/upcomingappointments/api/{id:[0-9]+}', function ($request, $response, $args) use ($log) {
 
        $id = $args['id'];
        $appointment = DB::queryFirstRow("SELECT b.id, b.timeSlot, b.appointmentDate, appointmentType, b.`description`, b.`type`, b.doctorId, b.status, f.id as fileId, f.fileName, f.description as fileDescription,
        u.firstName, u.lastName FROM bookingslots b LEFT JOIN patientfiles f 
        ON b.id= f.appointmentId INNER JOIN users u ON b.doctorId = u.id WHERE b.id = %i AND b.status='upcoming' AND
        (uploadedBy IS NULL OR uploadedBy != 'doctor')", $id);
        if (!$appointment) {
            throw new \Slim\Exception\NotFoundException($request, $response);

        } else {
            $appointment['time'] = explode("-", timeSlots($appointment['timeSlot']))[0]; 
            return $response->getBody()->write(json_encode($appointment));
        } 
    });

    $app->patch('/upcomingappointments/api/{id:[0-9]+}', function ($request, $response, $args) use ($log) {
 
        $id = $args['id'];
        $result = DB::queryFirstRow("SELECT * FROM bookingslots WHERE id=%i ", $id);
        if (!$result) {
            throw new \Slim\Exception\NotFoundException($request, $response);
        }
        
        $json = $request->getBody();
        $item = json_decode($json, TRUE);
        $appointmentType = $item['appointmentType'];
        $description = strip_tags($item['description']);
        $type = $item['type'];
        $fileDescription = strip_tags($item['fileDescription']);
        $fileId = $item['fileId'];
        $errorList = [];

        $result = verifyAppointmentType($appointmentType);
        if ($result !== TRUE) {
            $errorList[] = $result;
        }
        $result = verifyType($type);
        if ($result !== TRUE) {
            $errorList[] = $result;
        }
        $result = verifyDescription($description);
        if ($result !== TRUE) {
            $errorList[] = $result;
        }

        if($fileId != 0){
            $result = DB::queryFirstRow("SELECT * FROM patientfiles WHERE id=%i ", $fileId);
            if (!$result) {
                throw new \Slim\Exception\NotFoundException($request, $response);
            }
            $result = verifyFileDescription($fileDescription);
            if ($result !== TRUE) {
                $errorList[] = $result;
            }
        } 

        $insert = ['appointmentType' => $appointmentType, 'description' => $description, 'type' => $type];
        if (!$errorList) {
            DB::update('bookingslots', $insert, "id=%i", $id);
            $log->debug("Record bookingslots updated, id=" . $id);

            if($fileId != 0){
                DB::update('patientfiles', ['description' =>  $fileDescription], "id=%i", $fileId);
                $log->debug("Record patientfiles updated, id=" . $fileId);
            }

            $json = json_encode(TRUE); // true or false
            return $response->getBody()->write($json);

        } else {
            $json = json_encode($errorList);
            return $response->getBody()->write($json);
        }
 
    });

    $app->delete('/upcomingappointments/api/{id:[0-9]+}', function ($request, $response, $args) use ($log) {
        $id = $args['id'];
        $result = DB::queryFirstRow("SELECT * FROM bookingslots where id = %d", $id);
        if (!$result) {
            throw new \Slim\Exception\NotFoundException($request, $response);
        }
        DB::delete('bookingslots', "id=%i", $id);
        $log->debug("Record bookingslots deleted id=" . $id);
        // code is always 200
        // return true if record actually deleted, false if it did not exist in the first place
        $count = DB::affectedRows();
        $json = json_encode($count != 0, JSON_PRETTY_PRINT); // true or false
        return $response->getBody()->write($json);
    });

    $app->get('/updatewalkin', function ($request, $response, $args) {
        return $this->view->render($response, "patient/updatewalkin.html.twig");
    });


    $app->get('/updatewalkin/api', function ($request, $response, $args) use ($log) {

        $id = $_SESSION['user']['id'];
        $walkin = DB::queryFirstRow("SELECT w.id, w.date, w.queueStart, w.appointmentType, w.patientId, w.`description`, w.`type`, 
        w.activeStatus, f.id as fileId, f.fileName, f.description as fileDescription FROM walkins w LEFT JOIN patientfiles f ON w.id= f.walkinId 
        WHERE w.patientId = %i AND DATE(queueStart) = CURRENT_DATE() AND w.activeStatus='ACTIVE' AND (uploadedBy IS NULL OR uploadedBy != 'doctor')", $id);

        if($walkin) {
            if ($walkin['fileId'] !== null) {
                $walkin['file'] = "/files/patientfiles/" . $walkin['fileId'];
            }
            return $response->getBody()->write(json_encode($walkin));
        }
        return $response->getBody()->write(json_encode(false));
    });

    $app->patch('/updatewalkin/api/{id:[0-9]+}', function ($request, $response, $args) use ($log) {
 
        $id = $args['id'];
        $result = DB::queryFirstRow("SELECT * FROM walkins WHERE id=%i ", $id);
        if (!$result) {
            throw new \Slim\Exception\NotFoundException($request, $response);
        }
        
        $json = $request->getBody();
        $item = json_decode($json, TRUE);
        

        $appointmentType = $item['appointmentType'];
        $description = strip_tags($item['description']);
        $type = $item['type'];
        $fileDescription = strip_tags($item['fileDescription']);
        $fileId = $item['fileId'];
        $log->debug($fileId);
        $errorList = [];

        $result = verifyAppointmentType($appointmentType);
        if ($result !== TRUE) {
            $errorList[] = $result;
        }
        $result = verifyType($type);
        if ($result !== TRUE) {
            $errorList[] = $result;
        }
        $result = verifyDescription($description);
        if ($result !== TRUE) {
            $errorList[] = $result;
        }

        if($fileId != 0){
            $result = DB::queryFirstRow("SELECT * FROM patientfiles WHERE id=%i ", $fileId);
            if (!$result) {
                throw new \Slim\Exception\NotFoundException($request, $response);
            }
            $result = verifyFileDescription($fileDescription);
            if ($result !== TRUE) {
                $errorList[] = $result;
            }
        }

        $insert = ['appointmentType' => $appointmentType, 'description' => $description, 'type' => $type];
        if (!$errorList) {
            DB::update('walkins', $insert, "id=%i", $id);
            $log->debug("Record walkins updated, id=" . $id);
            

            if($fileId != 0){
                DB::update('patientfiles', ['description' =>  $fileDescription], "id=%i", $fileId);
                $log->debug("Record patientfiles updated, id=" . $fileId);
            }

            $json = json_encode(TRUE); // true or false
            return $response->getBody()->write($json);

        } else {
            $json = json_encode($errorList);
            return $response->getBody()->write($json);
        }
 
    });

    $app->delete('/updatewalkin/api/{id:[0-9]+}', function ($request, $response, $args) use ($log) {
        $id = $args['id'];
        $result = DB::queryFirstRow("SELECT * FROM walkins where id = %d", $id);
        
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

    $app->get('/getcalendarinfo', function ($request, $response, $args) use ($log) {
        $date = $request->getQueryParams()['date'];
        $doctorId = $request->getQueryParams()['doctorId'];
        $log->debug($date.$doctorId);
        //we need to know when the doctor is scheduled for appointments, and then which booking slots are available from those days
        //select from doctorSchedules, bookingslots where bookingslots.doctorscheduleId = doctorschedules.ID
        $slots = DB::query("SELECT b.timeSlot, b.doctorScheduleId FROM `bookingslots` `b`, `doctorschedules` `d` WHERE b.doctorScheduleId = d.id AND d.date = %s AND d.doctorId = %i", $date, $doctorId);
        $schedules = DB::queryFirstField("SELECT id FROM doctorschedules WHERE `availability`= 'APPOINTMENTS' AND doctorId=%i AND `date`> CURDATE() AND `date`=%s", $doctorId, $date);
        if($schedules){
            $scheduled = true;
        }
        return $response->withJson(['slots' => $slots, 'scheduled' => $scheduled]);

    });

})->add(function ($request, $response, $next) {
    if (!$_SESSION['user']) {
        return $response->withRedirect("/");
    } else if ($_SESSION['user']['role'] != "patient") {
        return $response->withRedirect("/");
    }
    $response = $next($request, $response);

    return $response;
});
