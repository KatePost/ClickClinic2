<?php

date_default_timezone_set('America/Montreal');

require_once 'vendor/autoload.php';

require_once 'utils.php';

session_start();

use Monolog\Logger;
use Monolog\Handler\StreamHandler;
use Respect\Validation\Validator as v;

// create a log channel
$log = new Logger('name');
$log->pushHandler(new StreamHandler('logs/everything.log', Logger::DEBUG));
$log->pushHandler(new StreamHandler('logs/errors.log', Logger::ERROR));
$log->pushHandler(new StreamHandler('logs/queries.log', Logger::INFO));


$log->pushProcessor(function ($record) {
    // $record['extra']['user'] = isset($_SESSION['user']) ? $_SESSION['user']['username'] : '=anonymous=';
    $record['extra']['ip'] = $_SERVER['REMOTE_ADDR'];
    return $record;
});

if (strpos($_SERVER['HTTP_HOST'], "fsd01.ca") !== false) {
    DB::$user = 'cp5016_clickclinic';
    DB::$password = 'cP727koW9NaF';
    DB::$dbName = 'cp5016_clickclinic';
} else {
    DB::$user = 'clickclinic';
    DB::$password = 'g16cdjM7@4-zngFh';
    DB::$dbName = 'clickclinic';
    DB::$host = 'localhost';
    DB::$port = 3333;
}

DB::$error_handler = 'db_error_handler'; // runs on mysql query errors
DB::$nonsql_error_handler = 'db_error_handler'; // runs on library errors (bad syntax, etc)

function db_error_handler($params)
{
    global $log, $container;
    $log->error("Database error: " . $params['error']);
    if (isset($params['query'])) {
        $log->error("SQL query: " . mb_strimwidth($params['query'], 0, 1000, ".. truncated .."));
    }
    // this was tricky to find - getting access to twig rendering directly, without PHP Slim
    http_response_code(500); // internal server error
    $twig = $container['view']->getEnvironment();
    die($twig->render('error_internal.html.twig'));
    // Note: the above trick may also be useful to render a template into an email body
    //header("Location: /internalerror"); // another possibility, not my favourite
}

DB::$success_handler = "db_success_handler";
function db_success_handler($params){
    global $log;
    if (isset($params['query'])) {
        $log->info("SQL query: " . mb_strimwidth($params['query'], 0, 1000, ".. truncated .."));
    }
}

// Create and configure Slim app
$config = ['settings' => [
    'addContentLengthHeader' => false,
    'displayErrorDetails' => true
]];
$app = new \Slim\App($config);

// Fetch DI Container
$container = $app->getContainer();

// Register Twig View helper
$container['view'] = function ($c) {
    $view = new \Slim\Views\Twig(dirname(__FILE__) . '/templates', [
        'cache' => dirname(__FILE__) . '/tmplcache',
        'debug' => true, // This line should enable debug mode
    ]);
    //
    $view->getEnvironment()->addGlobal('test1', 'VALUE');
    // Instantiate and add Slim specific extension
    $router = $c->get('router');
    $uri = \Slim\Http\Uri::createFromEnvironment(new \Slim\Http\Environment($_SERVER));
    $view->addExtension(new \Slim\Views\TwigExtension($router, $uri));
    return $view;
};

$container['view']->getEnvironment()->addGlobal('userSession', $_SESSION['user'] ?? null);
$container['view']->getEnvironment()->addGlobal('flashMessage', getAndClearFlashMessage());
$container['view']->getEnvironment()->addGlobal('flashStatus', getAndClearFlashstatus());
$container['view']->getEnvironment()->addGlobal('timeSlots', TIME_SLOTS);


