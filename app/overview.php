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
use App\Model\Todo;
use App\Todo\Chart;
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
    'cache'     => __DIR__ . '/cache',
    'local_directory' => $_ENV['NEXTCLOUD_LOCAL_DIRECTORY'],
    'use_cache'       => 'never',
];
$generator->setConfiguration($config);
try {
    $generator->parse();
} catch (JsonException $e) {
    echo $e->getMessage();
    exit;
}
$pages = $generator->getPages();

$chartConfig = Chart::generate($pages);


$list = [];

/** @var Page $page */
foreach ($pages as $page) {
    /** @var Todo $todo */
    foreach ($page->getTodos() as $todo) {
        $key       = '9999-99-00';
        $dateTitle = '(geen datum)';

        // to do with date
        if (null !== $todo->date && 'TODO' === $todo->type) {
            $key       = $todo->date->format('Y-m-d');
            $dateTitle = str_replace('  ', ' ', $todo->date->formatLocalized('%A %e %B %Y'));
        }

        // to do without date
        if (null === $todo->date && 'TODO' === $todo->type) {
            $key       = '0000-99-00';
            $dateTitle = '(geen datum todo)';
        }

        $list[$key]            = $list[$key] ?? [
                'title' => $dateTitle,
                'todos' => [],
            ];
        $list[$key]['todos'][] = $todo;
    }
}
ksort($list);

foreach ($list as $i => $info) {
    $todos = $info['todos'];
    usort($todos, function (Todo $left, Todo $right) {
        if ($left->priority == $right->priority) {
            return 0;
        }
        return ($left->priority < $right->priority) ? -1 : 1;
    });
    $list[$i]['todos'] = $todos;
}


// sort pages by type, then count to do's by priority:
/** @var Page $page */
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

?>
<!doctype html>
<html lang="en">
<head>
    <!-- Required meta tags -->
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">

    <!-- Bootstrap CSS -->
    <link href="./lib/bootstrap/css/bootstrap.min.css" rel="stylesheet">

    <title>TODO (local)</title>
</head>
<body>
<div class="container-fluid">
    <h1>TODO</h1>
    <div class="row">
        <div class="col-lg-6">
            <h2>Lijst <a class="small text-muted show-all" style="display:none;text-decoration: none;" href="#">(laat alles zien)</a></h2>
            <?php
            foreach ($list as $i => $info) { ?>
                <div class="date-block" data-date="<?php echo $i; ?>">
                    <h3><?php
                        echo $info['title']; ?> (<span data-date="<?php echo $i; ?>" class="date-count"><?php
                        echo count($info['todos']) ?></span>)</h3>
                    <ol class="date-list" data-date="<?php echo $i; ?>">
                        <?php
                        foreach ($info['todos'] as $todo) { ?>
                            <li data-page="<?php
                            echo $todo->getPageClass(); ?>" class="todo-item"><small><?php
                                    echo $todo->renderAsHtml(); ?></small></li>
                            <?php
                        } ?>
                    </ol>
                </div>

                <?php
            } ?>
        </div>
        <div class="col-lg-6">
            <h2>Verdeling</h2>
            <div style="width:600px;height:200px;">
            <canvas id="myChart" width="600" style="width:600px;height:200px;"height="200"></canvas>
            </div>
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
                        <tr <?php
                            if (0 === $page->getWeight()) { ?>class="text-muted"<?php
                        } ?> >
                            <td>
                                <a href="#" style="text-decoration: none;" class="<?php if(0===$page->getWeight()) { ?>text-muted<?php } ?> filter-page" data-page="<?php
                                echo $page->getClass(); ?>">
                                    <?php
                                    echo $page->title ?>
                                </a>

                            </td>
                            <td>
                                <span class="badge bg-light text-dark rounded-pill">
                                <?php
                                echo $page->prioCount(10); ?>
                            </span>
                            </td>
                            <td><span class="badge bg-light text-dark rounded-pill">
                                <?php
                                echo $page->prioCount(20); ?>
                            </span>
                            </td>

                            <td>
                                <span class="badge bg-light text-dark rounded-pill">
                                <?php
                                echo $page->prioCount(30); ?>
                            </span>
                            </td>

                            <td>
                                <span class="badge bg-light text-dark rounded-pill">
                                <?php
                                echo $page->prioCount(null); ?>
                            </span>
                            </td>

                        </tr>
                        <?php
                    } ?>
                    </tbody>
                </table>
                <?php
            } ?>
        </div>
    </div>


</div>
<script type="text/javascript">
    const chartConfig = <?php echo json_encode($chartConfig);?>;
</script>
<script src="./lib/jquery-3.6.0.min.js" type="text/javascript"></script>
<script src="./lib/chart.min.js" type="text/javascript"></script>
<!--suppress JSUnresolvedFunction -->
<script type="text/javascript">
    $(function () {
        "use strict";
        $('.filter-page').click(filterTodo);
        $('.show-all').click(showAll);
        plotChart();
    });

    function plotChart() {
        const ctx = document.getElementById('myChart');
        new Chart(ctx, chartConfig);
    }

    function showAll() {
        $('.show-all').hide();
        $('li.todo-item').show();
        $('div.date-block').show();
        countBlocks();
        return false;
    }

    function filterTodo(e) {
        const tg = $(e.currentTarget);
        const page = tg.data('page');
        $('li.todo-item').hide();
        $('li.todo-item[data-page="' + page + '"]').show();
        $('.show-all').show();
        countBlocks();
        return false;
    }
    function countBlocks() {
        $('.date-block').each(function(i,v) {
            let block = $(v);
            let date = block.data('date');
            let count = $('ol.date-list[data-date="'+date+'"] li:visible').length;
            $('span.date-count[data-date="'+date+'"]').text(count);
            if(0===count) {
                $('div.date-block[data-date="'+date+'"]').hide();
            }
        });
    }

</script>
</body>
</html>

