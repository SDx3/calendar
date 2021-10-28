<?php

namespace App\Todo;

use App\Model\Page;
use App\Model\Todo;
use Carbon\Carbon;

class Chart
{
    /**
     * @param array $pages
     *
     * @return array
     */
    public static function generate(array $pages): array
    {
        $keys = [
            10  => 0,
            20  => 1,
            30  => 2,
            100 => 3,
        ];

        // date over three weeks
        $date = Carbon::now();
        $date->endOfWeek();
        $date->addWeeks(2);

        $config = self::defaultConfig();
        $arr    = [];
        $labels = [];
        // overrule with pages stuff:
        /** @var Page $page */
        foreach ($pages as $page) {
            /** @var Todo $todo */
            foreach ($page->getTodos() as $todo) {
                if (null !== $todo->date) {
                    $key               = $todo->date->format('Y-m-d');
                    $arr[$key]         = $arr[$key] ?? ['date' => $todo->date,];
                    $index             = $keys[$todo->priority];
                    $arr[$key][$index] = $arr[$key][$index] ?? 0;
                    $arr[$key][$index]++;
                }
            }
        }
        ksort($arr);
        foreach ($arr as $info) {
            if ($info['date']->lte($date)) {
                $labels[] = str_replace('  ', ' ', trim($info['date']->formatLocalized('%a %e-%b')));
                $currentIndex = count($labels)-1;
                // add data, depending on priority:
                foreach($keys as $prio => $keyIndex) {
                    if(isset($info[$keyIndex])) {
                        $config['data']['datasets'][$keyIndex]['data'][] = $info[$keyIndex];
                    }
                    if(!isset($info[$keyIndex])) {
                        $config['data']['datasets'][$keyIndex]['data'][] = 0;
                    }
                }

//                foreach ($info as $index => $value) {
//                    if ('date' !== $index) {
//                        $config['data']['datasets'][$index]['data'][$currentIndex] = $value;
//                    }
//                }
            }
        }
        $config['data']['labels'] = $labels;
//        var_dump($config['data']['labels']);
//        var_dump($config['data']['datasets']);
//        exit;

        return $config;
    }

    private static function defaultConfig(): array
    {
        /**
         * backgroundColor: [
         * 'rgba(153, 102, 255, 0.2)',
         * 'rgba(255, 159, 64, 0.2)'
         * ],
         * borderColor: [
         * 'rgba(153, 102, 255, 1)',
         * 'rgba(255, 159, 64, 1)'
         * ],
         * @returns {boolean}
         */
        $config = [
            'type'    => 'bar',
            'data'    => [
                'labels'   => ['a', 'b', 'c'],
                'datasets' => [
                    [
                        'label'           => 'Prio A',
                        'data'            => [],
                        'borderWidth'     => 1,
                        'backgroundColor' => 'rgba(255, 99, 132, 0.2)',
                        'borderColor'     => 'rgba(255, 99, 132, 1)',
                    ],
                    [
                        'label'           => 'Prio B',
                        'data'            => [],
                        'borderWidth'     => 1,
                        'backgroundColor' => 'rgba(255, 206, 86, 0.2)',
                        'borderColor'     => 'rgba(255, 206, 86, 1)',
                    ],
                    [
                        'label'           => 'Prio C',
                        'data'            => [],
                        'borderWidth'     => 1,
                        'backgroundColor' => 'rgba(75, 192, 192, 0.2)',
                        'borderColor'     => 'rgba(75, 192, 192, 1)',
                    ],
                    [
                        'label'           => '??',
                        'data'            => [],
                        'borderWidth'     => 1,
                        'backgroundColor' => 'rgba(54, 162, 235, 0.2)',
                        'borderColor'     => 'rgba(54, 162, 235, 1)',
                    ],
                ],
            ],
            'options' => [
                'animation' => false,
                'plugins'   => [
                    'legend' => ['display' => false],
                ],
                'scales'    => [
                    'x' => ['stacked' => true],
                    'y' => ['stacked' => true, 'beginAtZero' => true],
                ],
            ],
        ];

        return $config;
    }
}