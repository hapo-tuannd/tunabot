<?php
use App\Http\Controllers\BotManController;
use BotMan\BotMan\Drivers\DriverManager;
use BotMan\Drivers\Line\LineDriver;

// Load the driver
DriverManager::loadDriver(LineDriver::class);
$botman = resolve('botman');

$botman->hears('Hi', function ($bot) {
    // $user = $bot->getUser();
    $bot->reply('Hello!');
})->driver(LineDriver::class);;

$botman->hears('Hello', function ($bot) {
    // $user = $bot->getUser();
    $bot->reply('Hello too!');
})->driver(LineDriver::class);;

$botman->hears('Start conversation', BotManController::class.'@startConversation');
