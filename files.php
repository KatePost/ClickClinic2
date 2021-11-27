<?php

require_once 'vendor/autoload.php';

require_once 'init.php';

$app->group('/files', function ($app) {

    $app->get('/profile/{userId}', function ($request, $response, $args) {
        $userId = $args['userId'];

        $image = DB::queryFirstField("SELECT photo FROM users WHERE id=%i", $userId);

        // note: i didn't save the mime type of these images, i'm just converting them all to png
        
        $response->getBody()->write($image);
        $response = $response->withHeader("Content-Type", "image/png");
        return $response->withHeader("Content-Disposition", "inline; filename='profile$userId-photo.png'"); //filename is usually included with "attachment" disposition. I was just trying to get it to display
    });

    $app->get('/patientfiles/{fileId}', function($request, $response, $args) {
        $fileId = $args['fileId'];
        $file = DB::queryFirstRow("SELECT `file`, `fileName` FROM patientfiles WHERE id=%i", $fileId);
        $fileName = $file['fileName'];
        $ext = explode(".", $fileName)[1];
        $response->getBody()->write($file['file']);
        if($ext == 'pdf'){
            $response = $response->withHeader("Content-Type", "application/pdf");
        } else { //if it's not a pdf, it's an image
            $response = $response->withHeader("Content-Type", "image/$ext");
        }
        return $response->withHeader("Content-Disposition", "attachment; filename='$fileName'");
    });

});

// ->add(function ($request, $response, $next) { //middleware to authenticate login

//     if(!$_SESSION['user']){
//         return $response->withStatus(404);
//     }
//     $response = $next($request, $response);

//     return $response;
// });

