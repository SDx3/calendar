<?php

/*
 * index.php
 * Copyright (c) 2021 Sander Dorigo
 *
 * Permission is hereby granted, free of charge, to any person obtaining a copy of
 * this software and associated documentation files (the "Software"), to deal in
 * the Software without restriction, including without limitation the rights to
 * use, copy, modify, merge, publish, distribute, sublicense, and/or sell copies
 * of the Software, and to permit persons to whom the Software is furnished to do
 * so, subject to the following conditions:
 *
 * The above copyright notice and this permission notice shall be included in all
 * copies or substantial portions of the Software.
 *
 * THE SOFTWARE IS PROVIDED "AS IS", WITHOUT WARRANTY OF ANY KIND, EXPRESS OR
 * IMPLIED, INCLUDING BUT NOT LIMITED TO THE WARRANTIES OF MERCHANTABILITY,
 * FITNESS FOR A PARTICULAR PURPOSE AND NONINFRINGEMENT. IN NO EVENT SHALL THE
 * AUTHORS OR COPYRIGHT HOLDERS BE LIABLE FOR ANY CLAIM, DAMAGES OR OTHER
 * LIABILITY, WHETHER IN AN ACTION OF CONTRACT, TORT OR OTHERWISE, ARISING FROM,
 * OUT OF OR IN CONNECTION WITH THE SOFTWARE OR THE USE OR OTHER DEALINGS IN THE
 * SOFTWARE.
 */

declare(strict_types=1);

require_once __DIR__ . '/vendor/autoload.php';

use App\Calendar\Appointments;
use Carbon\Carbon;
use Dotenv\Dotenv;
use Monolog\Handler\StreamHandler;
use Monolog\Logger;

$dotenv = Dotenv::createImmutable(__DIR__);
$dotenv->load();

// if must always require secret and secret isn't correct, don't work:
$secret = array_key_exists('secret', $_GET) ? $_GET['secret'] : false;
if('true' === $_ENV['ALWAYS_REQUIRE_SECRET'] && $secret !== $_ENV['CALENDAR_SECRET']) {
    header('HTTP/1.0 403 Forbidden');
    die('NOK');
}


$debug = array_key_exists('debug', $_GET) && 'true' === $_GET['debug'];
if ($debug) {
    echo '<pre>';
}

// start of calendar
$start = Carbon::now($_ENV['TZ']);
$start->subDays(4);

// end of calendar
$end = Carbon::now($_ENV['TZ']);
$end->addMonths(6);

$calendar = $_GET['calendar'] ?? false;
if (false === $calendar) {
    echo 'OK';
    exit;
}

try {
    $generator = new Appointments(sprintf('%s/schedules', __DIR__), $calendar);
} catch (JsonException $e) {
    echo $e->getMessage();
    exit;
}
$log = new Logger('index');
$log->pushHandler(new StreamHandler(__DIR__.'/logs/index-calendar.log', Logger::DEBUG));
//$generator->setLogger($log);

$generator->setStart($start);
$generator->setEnd($end);

$result = $generator->generate();
if (!$debug) {
    header('Content-Type: text/calendar; charset=utf-8');
    header(sprintf('Content-Disposition: attachment; filename="%s.ics"', $calendar));
}
echo $result;
