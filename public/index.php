<?php
session_start();
require __DIR__ . '/../secrets.php';
require __DIR__ . '/../src/config.php';
require __DIR__ . '/../src/Strava.php';
require __DIR__ . '/../src/Suggestion.php';
require __DIR__ . '/../src/Controller.php';

(new Controller())->dispatch();
