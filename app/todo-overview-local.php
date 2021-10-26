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

use App\TodoOverviewGenerator;
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

$generator = new TodoOverviewGenerator;
$config    = [
    'cache'           => '.',
    'local_directory' => $_ENV['NEXTCLOUD_LOCAL_DIRECTORY'],
    'use_cache'       => true,
];

$generator->setConfiguration($config);
$generator->parseTodosLocal();
$pages = $generator->getPages();

ksort($pages);

$grouped = [];
foreach ($pages as $page) {
    $type             = $page['type'];
    $grouped[$type]   = $grouped[$type] ?? [];
    $grouped[$type][] = $page;
}
ksort($grouped);

?>
<!doctype html>
<html lang="en">
<head>
    <!-- Required meta tags -->
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">

    <!-- Bootstrap CSS -->
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.1.1/dist/css/bootstrap.min.css" rel="stylesheet"
          integrity="sha384-F3w7mX95PdgyTmZZMECAngseQB83DfGTowi0iMjiWaeVhAn4FJkqJByhZMI3AhiU" crossorigin="anonymous">

    <title>TODO (local)</title>
</head>
<body>
<div class="container-fluid">
    <h1>TODO</h1>
    <div class="row">
        <div class="col-lg-4">
            <h2>Lijst</h2>
            <?php echo $generator->generateHtml(); ?>
        </div>
        <div class="col-lg-4">
            <h2>Dossiers</h2>
            <?php foreach ($grouped as $type => $pages) { ?>
                <h3><?php echo $type; ?></h3>
                <table class="table">
                    <?php foreach ($pages as $page) { ?>
                        <?php if (count($page['todos']) !== 0) { ?>
                            <tr>
                                <td><?php echo $page['title']; ?></td>
                                <td style="width:10%;"><?php echo count($page['todos']); ?></td>
                            </tr>
                        <?php } ?>
                    <?php } ?>
                </table>
            <?php } ?>
        </div>
        <div class="col-lg-4">
            <h2>Zonder datum</h2>
        </div>
    </div>


</div>
</body>
</html>

