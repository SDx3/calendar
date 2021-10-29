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

$dotenv = Dotenv::createImmutable(__DIR__);
$dotenv->load();

$debug = array_key_exists('debug', $_GET) && 'true' === $_GET['debug'];
if ($debug) {
    echo '<pre>';
}

// start of calendar
$start = Carbon::now($_ENV['TZ']);
$start->startOfMonth()->subMonth();

// end of calendar
$end = Carbon::now($_ENV['TZ']);
$end->addMonths(4);

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

$generator->setStart($start);
$generator->setEnd($end);

$result = $generator->generate();
if (!$debug) {
    header('Content-Type: text/calendar; charset=utf-8');
    header(sprintf('Content-Disposition: attachment; filename="%s.ics"', $calendar));
}
echo $result;
