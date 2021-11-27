<?php

require_once 'vendor/autoload.php';

require_once 'init.php';



// $app->get('/register', function .....);
// $app->get('/profile', function .....);

// patient page
// /patientprofile
// /edit (modify own profile)
// /book
// /viewappointments
// /viewavailable
// /cancelappointment
// /viewfile (prescriptions/referrals/files/uploads)



$app->post('/', function ($request, $response, $args) use ($log) {

    $email = $request->getParam('email');
    $password = $request->getParam('password');
    $record = DB::queryFirstRow("SELECT * FROM users WHERE email=%s", $email);
    $loginSuccess = false;
    if ($record) {
        if (password_verify($password, $record['password'])) {
            $loginSuccess = true;
        }
    }

    if (!$loginSuccess || !$password || !$email) {
        $errorList[] = "Invalid login credentials";
        $log->info(sprintf("Login failed for email %s from %s", $email, $_SERVER['REMOTE_ADDR']));
        return $this->view->render($response, 'login.html.twig', ['errorList' => $errorList]);
    } else { // STATE 3: sucess
        unset($record['password']); // for security reasons remove password from session
        $_SESSION['user'] = $record;
        $log->debug(sprintf("Login successful for email %s, uid=%d, from %s", $email, $record['id'], $_SERVER['REMOTE_ADDR']));
        setFlashMessage("Login successful", "success");
        // return $this->view->render($response, 'login_success.html.twig', ['userSession' => $_SESSION['user']]);
        $id = $_SESSION['user']['id'];
        // if($_SESSION['user']['role'] == 'patient'){
        //     return $response->withRedirect("/patient/{$id}");    
        // }
        $url = "/" . $_SESSION['user']['role'];
        return $response->withRedirect($url);
    }
});

$app->get('/logout', function ($request, $response, $args) use ($log) {
    $log->debug(sprintf("Logout successful for uid=%d, from %s", @$_SESSION['user']['id'], $_SERVER['REMOTE_ADDR']));
    unset($_SESSION['user']);
    setFlashMessage("You've been logged out");
    // return $this->view->render($response, 'logout.html.twig');
    return $response->withRedirect("/");
});

$app->get('/createaccount', function ($request, $response, $args) {
    return $this->view->render($response, "createaccount.html.twig");
});

$app->post('/createaccount', function ($request, $response, $args) use ($log) {
    $errorList = [];
    //extract values submitted
    if (!isset($_POST['g-recaptcha-response'])) {
        $errorList[] = "Recaptcha not returned";
    } else if ($_POST['g-recaptcha-response'] == '') {
        $errorList[] = "Recaptcha not checked";
    } else {
        $secretKey = 'removed';
        // post request to server
        $url = 'https://www.google.com/recaptcha/api/siteverify?secret=' . urlencode($secretKey) .  '&response=' . urlencode($_POST['g-recaptcha-response']);
        $apiResponse = file_get_contents($url);
        $responseKeys = json_decode($apiResponse, true);

        // should return JSON with success as true
        if (!$responseKeys["success"]) {
            $errorList[] = "Recaptcha failed";
        }
    }
    $healthCardNo = $request->getParam('healthcard');
    $email = $request->getParam('email');
    $pass1 = $request->getParam('pass1');
    $pass2 = $request->getParam('pass2');


    $record = DB::queryFirstRow("SELECT * FROM users WHERE healthCardNo=%s", $healthCardNo);
    if (!$record) {
        $log->info(sprintf("Health card number %s does not exist from %s", $healthCardNo, $_SERVER['REMOTE_ADDR']));
        $errorList[] = "Health card number does not exist in our records";
    } else {

        $result = verifyHealthCardNo($healthCardNo);
        if ($result !== TRUE) {
            $errorList[] = $result;
        }

        if ($record['email']) {
            if ($email != $record['email']) {
                $errorList[] = "Email does not match email in our records";
            }
        }

        if ($record['password']) {
            $errorList[] = "A password already exits in our records. You have already created an account.";
        }
    }

    $result = verifyEmailQuality($email);
    if ($result !== TRUE) {
        $errorList[] = $result;
    }

    $result = verifyPasswordQuality($pass1, $pass2);
    if ($result !== TRUE) {
        $errorList[] = $result;
    }
    // handle it
    if ($errorList) { // STATE 2: errors
        $log->info(sprintf("Account creation failed email %s from %s", $email, $_SERVER['REMOTE_ADDR']));
        return $this->view->render($response, 'createaccount.html.twig', ['errorList' => $errorList]);
    } else { // STATE 3: success

        $passwordHash = password_hash($pass1, PASSWORD_DEFAULT);
        $valuesList = ['email' => $email, 'password' =>  $passwordHash];
        DB::update('users', $valuesList, "healthCardNo=%s", $healthCardNo);
        $log->debug(sprintf("Patient with healthCardNo=%s updated", $healthCardNo));
        // return $this->view->render($response, 'patient/register_success.html.twig');
        setFlashMessage("Account creation successful! You may now login.");
        return $response->withRedirect("/");
    }

});

$app->get('/error', function ($request, $response, $args) {
    return $this->view->render($response, "error_internal.html.twig");
});
