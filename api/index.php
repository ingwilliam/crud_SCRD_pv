<?php
error_reporting(E_ALL);
ini_set('display_errors', '1');
use Phalcon\Mvc\Micro;

$app = new Micro();

// Aquí definimos las rutas 

$app->handle();