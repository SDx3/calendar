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

use App\Model\Page;
use App\Todo\Generator;
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

$generator = new Generator();
$config    = [
    'cache'           => '.',
    'local_directory' => $_ENV['NEXTCLOUD_LOCAL_DIRECTORY'],
    'use_cache'       => true,
];

$generator->setConfiguration($config);
$generator->parse();
$pages = $generator->getPages();

$list = [];


/** @var \App\Model\Page $page */
foreach ($pages as $page) {
    /** @var \App\Model\Todo $todo */
    foreach ($page->getTodos() as $todo) {
        $key       = '9999-99-00';
        $dateTitle = '(geen datum)';

        if (null !== $todo->date) {
            $key       = $todo->date->format('Y-m-d');
            $dateTitle = str_replace('  ', ' ', $todo->date->formatLocalized('%A %e %B %Y'));
        }
        $list[$key]            = $list[$key] ?? [
                'title' => $dateTitle,
                'todos' => [],
            ];
        $list[$key]['todos'][] = $todo;
    }
}
ksort($list);

// sort pages by type, then count todo's by priority:
/** @var \App\Model\Page $page */
$pageList = [];
foreach ($pages as $page) {
    $type                       = $page->getType();
    $pageList[$type]            = $pageList[$type] ?? [
            'title' => $type,
            'pages' => [],
        ];
    $pageList[$type]['pages'][] = $page;
}
foreach ($pageList as $type => $info) {
    $pages = $info['pages'];
    usort($pages, function (Page $left, Page $right) {
        $weightLeft   = $left->getWeight();
        $weightReight = $right->getWeight();
        if ($weightLeft == $weightReight) {
            return 0;
        }
        return ($weightLeft < $weightReight) ? 1 : -1;
    });
    $pageList[$type]['pages'] = $pages;

}
ksort($pageList);

//
//
///** @var \App\Model\Page $page */
//foreach ($pages as $page) {
//    echo '<h3>' . $page->title . '</h3>';
//    echo '<ul>';
//    /** @var \App\Model\Todo $todo */
//    foreach ($page->getTodos() as $todo) {
//
//
//        echo '<li>[date: ' . $todo->date?->format('Y-m-d') . ']';
//
//        /*
//         *     public string  $type; // TODO LATER DONE
//    public string  $keyword;
//    public string  $page;
//    public string  $text;
//    public ?Carbon $date;
//    public int     $priority = 100;
//    public bool    $repeater = false;
//         */
//
//        echo '[page: ' . $todo->page ?? 'NOPAGE' . ']';
//        echo '[prio: ' . $todo->priority . ']';
//        echo '[type: ' . $todo->type . ']';
//        echo '[keyword: ' . $todo->keyword . ']';
//        echo '[text: ' . $todo->text . ']';
//        echo '[repeater: ' . $todo->repeater . ']';
//
//        echo '</li>';
//        //: '.$todo->text.'</li>';
//    }
//    echo '</ul>';
//}
//
//
//exit;


//$generator->parseTodosLocal();
//$pages = $generator->getPages();
//
//ksort($pages);
//
//$grouped = [];
//foreach ($pages as $page) {
//    $type             = $page['type'];
//    $grouped[$type]   = $grouped[$type] ?? [];
//    $grouped[$type][] = $page;
//}
//ksort($grouped);

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
            <?php
            foreach ($list as $key => $info) { ?>
                <h3><?php
                    echo $info['title']; ?> (<?php
                    echo count($info['todos']) ?>)</h3>
                <ol>
                    <?php
                    foreach ($info['todos'] as $todo) { ?>
                        <li><small><?php
                                echo $todo->renderAsHtml(); ?></small></li>
                        <?php
                    } ?>
                </ol>
                <?php
            } ?>
        </div>
        <div class="col-lg-4">
            <h2>Dossiers</h2>
            <?php
            foreach ($pageList as $set) { ?>
                <h3><?php
                    echo $set['title'] ?></h3>
                <table class="table table-hover table-sm">
                    <thead>
                    <tr>
                        <th style="width:60%;">Pagina</th>
                        <th style="width:10%;"><span class="badge bg-danger">A</span></th>
                        <th style="width:10%;"><span class="badge bg-warning text-dark">B</span></th>
                        <th style="width:10%;"><span class="badge bg-success">C</span></th>
                        <th style="width:10%;"><span class="badge bg-info">?</span></th>
                    </tr>
                    </thead>
                    <tbody>
                    <?php
                    foreach ($set['pages'] as $page) { ?>
                        <tr>
                            <td>
                                <?php
                                echo $page->title ?>
                            </td>
                            <td><?php
                                echo $page->prioCount(10); ?></td>
                            <td><?php
                                echo $page->prioCount(20); ?></td>
                            <td><?php
                                echo $page->prioCount(30); ?></td>
                            <td><?php
                                echo $page->prioCount(null); ?></td>
                        </tr>
                        <?php
                    } ?>
                    </tbody>
                </table>
                <?php
            } ?>
            Hier
        </div>
        <div class="col-lg-4">
            <h2>Zonder datum</h2>
            Hier
        </div>
    </div>


</div>
</body>
</html>

