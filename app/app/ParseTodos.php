<?php

namespace App;

use Carbon\Carbon;
use Carbon\Exceptions\InvalidFormatException;

trait ParseTodos
{
    /**
     * @return string
     */
    public function generateHtml(): string
    {
        $grouped = $this->groupItemsHtml();
        $html    = '';

        /**
         * @var string $dateString
         * @var array  $appointments
         */
        foreach ($grouped as $dateString => $appointments) {
            $count = count($appointments);
            if (0 === $count) {
                continue;
            }

            // render short to do's:
            if ('0000-00-00-short' === $dateString) {
                $html .= $this->renderShortTodos($appointments);
                continue;
            }
            if ('0000-00-00' === $dateString) {
                $html .= $this->renderDatelessTodos($appointments);
                continue;
            }
            $date = Carbon::createFromFormat('Y-m-d', $dateString, 'Europe/Amsterdam');
            $html .= $this->renderTodos($appointments, $date);
        }

        // render laters
        $html .= $this->renderLaters();

        return $html;
    }

    /**
     * @param string $directory
     */
    protected function loadFromLocalDirectory(string $directory): void
    {
        // list all files, dont do a deep dive:
        $files = scandir($directory);
        /** @var string $file */
        foreach ($files as $file) {
            $this->debug(sprintf('TodoGenerator found local file %s', $file));
            $parts = explode('.', $file);
            if (count($parts) > 1 && 'md' === strtolower($parts[count($parts) - 1])) {
                // filter on extension:
                $this->loadFromLocalFile($directory, $file);
            }
        }
        foreach ($this->todos as $todo) {
            $this->pages[$todo['page']]['todos'][] = $todo;
        }
    }

    /**
     * Process a line that alreadt known to be a to do item.
     *
     * @param string $line
     * @param string $shortName
     */
    protected function processTodoLine(string $line, string $shortName): void
    {
        $parts = explode("\n", $line);
        if (1 === count($parts)) {
            // its a basic to do with no date
            // add it to array of to do's but keep the date NULL:

            $todo          = [
                'page'     => str_replace('.md', '', $shortName),
                'todo'     => $this->filterTodoText(substr($line, 4)),
                'date'     => null,
                'short'    => false,
                'priority' => $this->getPriority($line),
                'repeats'  => false,
            ];
            $this->todos[] = $todo;

            return;
        }
        // its a line with all kinds of meta stuff:
        $todo = [
            'page'     => str_replace('.md', '', $shortName),
            'todo'     => 'Not yet done!',
            'date'     => null,
            'short'    => false,
            'repeats'  => false,
            'priority' => $this->getPriority($line),
        ];
        foreach ($parts as $part) {
            if (str_starts_with($part, 'TODO')) {
                $todo['todo'] = $this->filterTodoText(substr($part, 4));
            }
            if (str_starts_with($part, 'SCHEDULED')) {
                $dateString = str_replace(['SCHEDULED: ', '<', '>'], '', $part);
                if (str_contains($dateString, '++')) {
                    $this->parseRepeater($todo, $dateString, '++');

                    return;
                }
                if (str_contains($dateString, '.+')) {
                    $this->parseRepeater($todo, $dateString, '.+');

                    return;
                }

                try {
                    $dateObject = Carbon::createFromFormat('!Y-m-d D', $dateString, 'Europe/Amsterdam');
                } catch (InvalidFormatException $e) {
                    echo 'Could not parse: "' . htmlentities($dateString) . '"!';
                    exit;
                }
                $todo['date'] = $dateObject->toW3cString();
            }

            // same but for deadline:
            if (str_starts_with($part, 'DEADLINE')) {
                $dateString = str_replace(['DEADLINE: ', '<', '>'], '', $part);
                try {
                    $dateObject = Carbon::createFromFormat('!Y-m-d D', $dateString, 'Europe/Amsterdam');
                } catch (InvalidFormatException $e) {
                    echo 'Could not parse: "' . htmlentities($dateString) . '"!';
                    exit;
                }
                $todo['date'] = $dateObject->toW3cString();
            }
        }
        $this->todos[] = $todo;
    }

    /**
     * @param array $appointment
     *
     * @return string
     */
    private function colorizeTodo(array $appointment): string
    {
        $color      = '#444';
        $typeLabel  = '';
        $todoText   = array_key_exists('later', $appointment) ? $appointment['later'] : $appointment['todo'];
        $todoTypes  = [
            'Ensure'    => 'bg-warning text-dark',
            'Follow up' => 'bg-primary',
            'Meet'      => 'bg-info',
            'Discuss'   => 'bg-info',
            'Track'     => 'bg-warning text-dark',
            'Go-to'     => 'bg-primary',
            'Bring'     => 'bg-primary',
            'Get'       => 'bg-primary',
            'Share'     => 'bg-primary',
        ];
        $foundLabel = $this->getTypeLabel($todoText);
        if (null !== $foundLabel) {
            // remove type from to do
            $search   = sprintf('%s:', $foundLabel);
            $todoText = str_replace($search, '', $todoText);

            // add type to $type with the right color:
            $typeLabel = sprintf('<span class="badge %s">%s</span>', $todoTypes[$foundLabel], $foundLabel);
        }
        if ('' === $typeLabel) {
            $typeLabel = '<span class="badge bg-danger">!!</span>';
        }
        $priority = '';
        if (10 === $appointment['priority']) {
            $priority = '<span class="badge bg-danger">A</span> ';
        }
        if (20 === $appointment['priority']) {
            $priority = '<span class="badge bg-warning text-dark">B</span> ';
        }
        if (30 === $appointment['priority']) {
            $priority = '<span class="badge bg-success">C</span> ';
        }

        return trim(
            sprintf(
                '%s<span class="badge bg-secondary">%s</span> <span style="color:%s">%s</span> %s', $priority, $appointment['page'], $color, $typeLabel,
                $todoText
            )
        );
    }

    /**
     * @param string $appointment
     *
     * @return string|null
     */
    private function getTypeLabel(string $appointment): ?string
    {
        $todoTypes = ['Ensure', 'Follow up', 'Meet', 'Discuss', 'Track', 'Go-to', 'Bring', 'Get', 'Share'];
        /** @var string $search */
        foreach ($todoTypes as $todoType) {
            $search = sprintf('%s:', $todoType);
            if (str_contains($appointment, $search)) {
                return $todoType;
            }
        }

        return null;
    }

    /**
     * @return array
     */
    private function groupItemsHtml(): array
    {
        $newSet = [];
        /** @var array $item */
        foreach ($this->todos as $item) {
            if (null === $item['date']) {
                $dateStr = '0000-00-00';
            }
            if (null !== $item['date']) {
                $date    = Carbon::createFromFormat(Carbon::W3C, $item['date'], $_ENV['TZ']);
                $dateStr = $date->format('Y-m-d', $_ENV['TZ']);
            }

            // separate list of short to do's.
            if (isset($item['short']) && true === $item['short']) {
                $dateStr = '0000-00-00-short';
            }

            $newSet[$dateStr]   = $newSet[$dateStr] ?? [];
            $newSet[$dateStr][] = [
                'full'     => sprintf('<span class="badge bg-secondary">%s</span> %s', $item['page'], $item['todo']),
                'page'     => $item['page'],
                'todo'     => $item['todo'],
                'priority' => $item['priority'],
            ];
        }
        ksort($newSet);

        // TODO im sure this can be done more efficiently:
        foreach ($newSet as $key => $array) {
            // first by page title
            usort($array, function (array $a, array $b) {
                return strcmp($a['page'], $b['page']);
            });

            usort($array, function (array $a, array $b) {
                return $a['priority'] - $b['priority'];
            });
            $newSet[$key] = $array;
        }

        return $newSet;
    }

    /**
     * @param array  $array
     * @param string $dateString
     * @param string $separator
     */
    private function parseRepeater(array $array, string $dateString, string $separator): void
    {
        $today = Carbon::now($_ENV['TZ'])->startOfDay();
        $end   = Carbon::now($_ENV['TZ']);
        $end->addMonths(3);

        // lazy split to get repeater in place
        $parts = explode($separator, $dateString);

        // first date is this one:
        $dateObject = Carbon::createFromFormat('!Y-m-d D', trim($parts[0]), $_ENV['TZ']);
        $period     = (int)$parts[1][0];

        // repeater is '1w' or '2d' or whatever.
        switch ($parts[1][1]) {
            default:
                die(sprintf('Cant handle period "%s"', $parts[1][1]));
            case 'w':
                $func = 'addWeeks';
                break;
            case 'm':
                $func = 'addMonths';
                break;
        }
        $start = clone $dateObject;
        while ($start <= $end) {
            //echo 'Start is now ' . $start->toRfc2822String().'<br>';
            if ($start >= $today) {
                //echo '<strong>Start is bigger</strong> than today! ' . $today->toRfc2822String().'<br>';
                // add to do!
                $currentTodo            = $array;
                $currentTodo['repeats'] = true;
                $currentTodo['date']    = $start->toW3cString();
                $this->todos[]          = $currentTodo;
            }
            $start->$func($period);
        }
    }

    /**
     * @param array $appointments
     *
     * @return string
     */
    private function renderDatelessTodos(array $appointments): string
    {
        $html = sprintf('<h3>TODO\'s with no date <small>(%d)</small></h3><ol>', count($appointments));
        /** @var array $appointment */
        foreach ($appointments as $appointment) {
            $html .= sprintf('<li>%s</li>', $this->colorizeTodo($appointment));
        }
        $html .= '</ol>';

        return $html;

    }

    /**
     * @return string
     */
    private function renderLaters(): string
    {
        if (0 === count($this->laters)) {
            return '';
        }
        $html = '<h3>Later (ooit)</h3><ol>';

        foreach ($this->laters as $later) {
            $html .= sprintf('<li>%s</li>', $this->colorizeTodo($later));
        }
        $html .= '</ol>';

        return $html;
    }

    /**
     * @param array  $appointments
     * @param Carbon $date
     *
     * @return string
     */
    private function renderTodos(array $appointments, Carbon $date): string
    {
        $count = count($appointments);
        $color = '#000;';
        if ($count > 10) {
            $color = '#a00';
        }

        $html = sprintf('<h3 style="color:%s;">%s <small>(%d)</small></h3><ol>', $color, str_replace('  ', ' ', $date->formatLocalized('%A %e %B %Y')), $count);
        /** @var array $appointment */
        foreach ($appointments as $appointment) {
            $html .= sprintf('<li>%s</li>', $this->colorizeTodo($appointment));
        }
        $html .= '</ol>';

        return $html;
    }


}