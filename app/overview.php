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

foreach ($list as $key => $info) {
    $todos = $info['todos'];
    usort($todos, function (Todo $left, Todo $right) {
        if ($left->priority == $right->priority) {
            return 0;
        }
        return ($left->priority < $right->priority) ? -1 : 1;
    });
    $list[$key]['todos'] = $todos;
}


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
        <div class="col-lg-6">
            <h2>Lijst <a class="small text-muted show-all" style="display:none;" href="#">laat alles zien</a></h2>
            <?php
            foreach ($list

                     as $key => $info) { ?>
                <div class="date-block" data-date="<?php
                echo $key; ?>">
                    <h3><?php
                        echo $info['title']; ?> (<span data-date="<?php echo $key; ?>" class="date-count"><?php
                        echo count($info['todos']) ?></span>)</h3>
                    <ol class="date-list" data-date="<?php echo $key; ?>">
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
                                <?php
                                if (0 !== $page->getWeight()) { ?>
                                <a href="#" style="text-decoration: none;" class="filter-page" data-page="<?php
                                echo $page->getClass(); ?>">
                                    <?php
                                    } ?>
                                    <?php
                                    echo $page->title ?>
                                    <?php
                                    if (0 !== $page->getWeight()) { ?>
                                </a>
                            <?php
                            } ?>
                            </td>
                            <td>
                                <span class="badge bg-light text-dark rounded-pill">
                                <?php
                                echo $page->prioCount(10); ?>
                            </span>
                            </td>
                            <td><span class="badge bg-light text-dark rounded-pill">
                                <?php
                                echo $page->prioCount(20); ?></td>
                            </span>
                            <td>
                                <span class="badge bg-light text-dark rounded-pill">
                                <?php
                                echo $page->prioCount(30); ?></td>
                            </span>
                            <td>
                                <span class="badge bg-light text-dark rounded-pill">
                                <?php
                                echo $page->prioCount(null); ?></td>
                            </span>
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
<script src="https://code.jquery.com/jquery-3.6.0.min.js" integrity="sha256-/xUj+3OJU5yExlq6GSYGSHk7tPXikynS7ogEvDej/m4=" crossorigin="anonymous"></script>
<script type="text/javascript">
    $(function () {
        "use strict";
        $('.filter-page').click(filterTodo);
        $('.show-all').click(showAll);
    });

    function showAll() {
        $('.show-all').hide();
        $('li.todo-item').show();
        $('div.date-block').show();
        countBlocks();
        return false;
    }

    function filterTodo(e) {
        var tg = $(e.currentTarget);
        var page = tg.data('page');
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
            // loop all ol's and count them:
            //console.log();
            //class="date-list" data-date="<?php echo $key; ?>">
        });
    }

</script>
</body>
</html>

