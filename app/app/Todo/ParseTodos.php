<?php
/*
 * ParseTodos.php
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

namespace App\Todo;

use Carbon\Carbon;

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
     * @param array $appointments
     *
     * @return string
     */
    private function renderShortTodos(array $appointments): string
    {
        $html = '<h3>Very short TODO\'s</h3><ol>';
        /** @var array $appointment */
        foreach ($appointments as $appointment) {
            $html .= sprintf('<li>%s</li>', $this->colorizeTodo($appointment));
        }
        $html .= '</ol>';

        return $html;

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
     * @param Logger|null $logger
     */
    public function setLogger(?Logger $logger): void
    {
        $this->logger = $logger;
        $this->debug('TodoGenerator has a logger!');
    }

    /**
     */
    public function parseTodosLocal(): void
    {
        $directories = [
            sprintf('%s/pages', $this->configuration['local_directory']),
            sprintf('%s/journals', $this->configuration['local_directory']),
        ];

        /** @var string $directory */
        foreach ($directories as $directory) {
            $this->debug(sprintf('TodoGenerator will locally parse %s', $directory));
            $this->loadFromLocalDirectory($directory);
        }
    }

    /**
     * @return array
     */
    public function getPages(): array
    {
        return $this->pages;
    }

    /**
     * I can't believe im this lazy!
     *
     * @param string $string
     *
     * @return bool
     */
    private function hasDateRef(string $string): bool
    {
        $dateRefs = ['[[Monday', '[[Tuesday', '[[Wednesday', '[[Thursday', '[[Friday', '[[Saturday', '[[Sunday',];
        foreach ($dateRefs as $ref) {
            if (str_contains($string, $ref)) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param string $line
     *
     * @return bool
     */
    private function isShortTodo(string $line): bool
    {
        return str_contains($line, '#5m');
    }

    /**
     * Process a line that alreadt known to be a LATER item.
     *
     * @param string $line
     * @param string $shortName
     */
    private function processLaterLine(string $line, string $shortName): void
    {
        $this->debug('TodoGenerator found a LATER');
        $parts = explode("\n", $line);
        if (1 === count($parts)) {
            $later          = [
                'page'     => str_replace('.md', '', $shortName),
                'later'    => $this->filterTodoText(substr($line, 5)),
                'short'    => false,
                'repeats'  => false,
                'priority' => $this->getPriority($line),
            ];
            $this->laters[] = $later;

            return;
        }
        $later = [
            'page'     => str_replace('.md', '', $shortName),
            'later'    => 'volgt',
            'short'    => false,
            'repeats'  => false,
            'priority' => $this->getPriority($line),
        ];
        foreach ($parts as $part) {
            if (str_starts_with($part, 'LATER')) {
                $later['later'] = $this->filterTodoText(substr($part, 5));
            }
        }
        $this->laters[] = $later;
    }

    /**
     * @param string $line
     *
     * @return string
     */
    private function filterTodoText(string $line): string
    {
        $search  = ['- TODO', '#ready', '#nodate', '#5m', '- LATER', '[#A]', '[#A] ', '[#B]', '[#B] ', '[#C]', '[#C] '];
        $replace = '';

        return trim(str_replace($search, $replace, $line));
    }


//
//if (str_starts_with($text, 'LATER ')) {
//    // loop over each line in markdown file
//$this->processLaterLine(trim($text), $shortName);
//}

    /**
     * @param string $line
     *
     * @return int
     */
    private function getPriority(string $line): int
    {
        if (str_contains($line, '[#A]')) {
            return 10;
        }
        if (str_contains($line, '[#B]')) {
            return 20;
        }
        if (str_contains($line, '[#C]')) {
            return 30;
        }

        return 100;
    }


}