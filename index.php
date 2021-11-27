<?php



require_once 'vendor/autoload.php';
require_once 'init.php';
require_once 'utils.php';

// Define app routes below
require_once 'user.php';
require_once 'admin.php';
require_once 'patient.php';
require_once 'doctor.php';
require_once 'files.php';


/* copypasta 

- for running without templates
$app->get('/url/{fromURL}', function($request, $response, $args){
   return $response->write("words " .$args['fromURL']);
});

- for running templates
$app->get('/url', function($request, $response, $args){
   return $this->view->render($response, "template.html.twig", ['variable' => $variable]);
});

- for getting post form information
$app->post('/url', function($request, $response, $args){
   $variable = $requst->getParam('formVariable');
   return $this->view->render($response, "template.html.twig")
});

 -- */


// landing page
$app->get('/', function($request, $response, $args){
   if(isset($_SESSION['user'])){
      return $response->withRedirect("/".$_SESSION['user']['role']);
   }
   return $this->view->render($response, "login.html.twig");
});



 //register
//  $app->get('/register', function($request, $response, $args){
//     return $this->view->render($response, "register.html.twig");
//  });
 
//  $app->post('/register', function($request, $response, $args){    
//     return $this->view->render($response, "register.html.twig");
//  });
 
  

// Run app - must be the last operation
// if you forget it all you'll see is a blank page


$app->run();