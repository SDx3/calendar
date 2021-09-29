<?php
/*
 * todo.php
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

/*
 * A special script to collect to do's from Logseq (hosted on Nextcloud)
 * and create a special calendar for them. Requires a cache directory for performance.
 */

require_once __DIR__ . '/vendor/autoload.php';

use App\TodoCalendarGenerator;
use Dotenv\Dotenv;

$dotenv = Dotenv::createImmutable(__DIR__);
$dotenv->load();

setlocale(LC_ALL, ['nl', 'nl_NL.UTF-8', 'nl-NL']);

if (!isset($_GET['secret'])) {
    die();
}

if ($_GET['secret'] !== $_ENV['CALENDAR_SECRET']) {
    die();
}

$generator = new TodoCalendarGenerator;
$config    = [
    'cache'    => __DIR__ . '/cache',
    'host'     => $_ENV['NEXTCLOUD_HOST'],
    'path'     => $_ENV['NEXTCLOUD_LOGSEQ_PARENT'],
    'username' => $_ENV['NEXTCLOUD_USERNAME'],
    'password' => $_ENV['NEXTCLOUD_PASS'],
    'use_cache' => 'always',
];

$generator->setConfiguration($config);
$generator->parseTodos();
?>
<!doctype html>
<html lang="en">
<head>
    <!-- Required meta tags -->
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">

    <!-- Bootstrap CSS -->
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.1.1/dist/css/bootstrap.min.css" rel="stylesheet" integrity="sha384-F3w7mX95PdgyTmZZMECAngseQB83DfGTowi0iMjiWaeVhAn4FJkqJByhZMI3AhiU" crossorigin="anonymous">

    <title>TODO (online)</title>
</head>
<body>
<div class="container">
    <h1>TODO (online)</h1>
    <?php echo $generator->generateHtml(); ?>
</div>
</body>
</html>