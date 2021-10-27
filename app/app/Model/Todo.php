<?php
/*
 * Todo.php
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

namespace App\Model;

use App\SharedTraits;
use Carbon\Carbon;
use Carbon\Exceptions\InvalidFormatException;

class Todo
{
    use SharedTraits;

    public string  $type; // TODO LATER DONE
    public string  $keyword;
    public string  $page;
    public string  $text;
    public ?Carbon $date;
    public int     $priority = 100;
    public bool    $repeater = false;

    public function __construct()
    {
        $this->type    = 'unknown';
        $this->keyword = '!!';
        $this->page    = '!!';
    }

    public function parseFromSingleTodo(string $text, string $shortName): void
    {
        $typeLabel      = $this->getTypeLabel($text) ?? '!!';
        $this->type     = 'TODO';
        $this->page     = $shortName;
        $this->text     = trim(str_replace(sprintf('%s:', $typeLabel), '', $this->filterTodoText($text)));
        $this->date     = null;
        $this->priority = $this->getPriority($text);
        $this->repeater = false;
        $this->keyword  = $typeLabel;
    }

    /**
     * @param string $line
     * @param string $shortName
     * @return array
     */
    public static function parseRepeatingTodo(string $line, string $shortName): array
    {
        $array = [];
        // find repeater and datestring
        $parts        = explode("\n", $line);
        $originalTodo = $parts[0];
        $dateString   = '??';
        $separator    = '??';
        // loop every line, look for a repeater:
        foreach ($parts as $part) {
            if (str_starts_with($part, 'SCHEDULED')) {
                $dateString = str_replace(['SCHEDULED: ', '<', '>'], '', $part);
                if (str_contains($part, '++')) {
                    $separator = '++';
                }
                if (str_contains($part, '.+')) {
                    $separator = '.+';
                }
            }
        }

        $today = Carbon::now($_ENV['TZ'])->startOfDay();
        $end   = Carbon::now($_ENV['TZ']);
        $end->addMonths(1);


        // lazy split to get repeater in place
        $parts = explode($separator, $dateString);
        // first date is this one:
        $dateObject = Carbon::createFromFormat('!Y-m-d D', trim($parts[0]), $_ENV['TZ']);
        $period     = (int) $parts[1][0];

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
                $todo       = new Todo;
                $todo->page = $shortName;
                $todo->parseFromSingleTodo($originalTodo, $shortName); // parse from first line.

                // add date
                $todo->date     = clone $start;
                $todo->repeater = true;
                $array[]        = $todo;
            }
            $start->$func($period);
        }
        return $array;
    }

    /**
     * @param string $line
     * @return array
     */
    public static function parseFromComplexTodo(string $line, string $shortName): array
    {
        $parts = explode("\n", $line);
        $todo  = new Todo;

        foreach ($parts as $part) {
            if (str_starts_with($part, 'TODO')) {
                $todo->parseFromSingleTodo($part, $shortName);
            }
            if (str_starts_with($part, 'LATER')) {
                $todo->parseFromSingleTodo($part, $shortName);
                $todo->type = 'LATER';
            }

            if (str_starts_with($part, 'SCHEDULED')) {
                $dateString = str_replace(['SCHEDULED: ', '<', '>'], '', $part);
                try {
                    $dateObject = Carbon::createFromFormat('!Y-m-d D', $dateString, $_ENV['TZ']);
                } catch (InvalidFormatException $e) {
                    echo 'Could not parse: "' . htmlentities($dateString) . '"!';
                    exit;
                }
                $todo->date = $dateObject;
            }
            if (str_starts_with($part, 'DEADLINE')) {
                $dateString = str_replace(['DEADLINE: ', '<', '>'], '', $part);
                try {
                    $dateObject = Carbon::createFromFormat('!Y-m-d D', $dateString, $_ENV['TZ']);
                } catch (InvalidFormatException $e) {
                    echo 'Could not parse: "' . htmlentities($dateString) . '"!';
                    exit;
                }
                $todo->date = $dateObject;
            }
        }
        return [$todo];
    }


    /**
     * @return string
     */
    public function getPageClass(): string
    {
        if('' === $this->page) {
            var_dump($this);
            exit;
        }
        return Page::asClass($this->page);
    }

    /**
     * @return string
     */
    public function renderAsHtml(): string
    {
        $html = $this->priorityBadge();
        $html .= ' ';
        $html .= sprintf('<span class="badge bg-secondary">%s</span>', $this->page);
        $html .= ' ';
        $html .= $this->keywordLabel();
        //$html .= '<br>';
        $html .= ' ';
        $html .= $this->text;

        return $html;
    }

    /**
     * @param string $line
     *
     * @return string
     */
    private function filterTodoText(string $line): string
    {
        $search  = ['- TODO', '- LATER', '#ready', '#nodate', '#5m', '- LATER', '[#A]', '[#A] ', '[#B]', '[#B] ', '[#C]', '[#C] '];
        $replace = '';
        if (str_starts_with($line, 'LATER ')) {
            $line = substr($line, 6);
        }
        if (str_starts_with($line, 'TODO ')) {
            $line = substr($line, 5);
        }

        return trim(str_replace($search, $replace, $line));
    }

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
        if (str_starts_with($line, 'LATER')) {
            return 50;
        }

        return 100;
    }

    private function priorityBadge(): string
    {
        if (10 === $this->priority) {
            return '<span class="badge bg-danger">A</span>';
        }
        if (20 === $this->priority) {
            return '<span class="badge bg-warning text-dark">B</span>';
        }
        if (30 === $this->priority) {
            return '<span class="badge bg-success">C</span>';
        }
        return '<span class="badge bg-info">?</span>';
    }

    /**
     * @return string
     */
    private function keywordLabel(): string
    {
        $todoTypes = [
            'Ensure'    => 'bg-warning text-dark',
            'Follow up' => 'bg-primary',
            'Meet'      => 'bg-info',
            'Discuss'   => 'bg-info',
            'Track'     => 'bg-warning text-dark',
            'Go-to'     => 'bg-primary',
            'Bring'     => 'bg-primary',
            'Get'       => 'bg-primary',
            'Share'     => 'bg-primary',
            '!!'        => 'bg-danger',
        ];

        // add type to $type with the right color:
        return sprintf('<span class="badge %s">%s</span>', $todoTypes[$this->keyword], $this->keyword);
    }
}