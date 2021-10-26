<?php
/*
 * Page.php
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

class Page
{
    public string $title;
    public array  $todos;
    public array  $tags;

    /**
     *
     */
    public function __construct()
    {
        $this->todos = [];
        $this->tags  = [];
    }

    public static function createFromString(string $content, string $title): self
    {
        $tags        = [];
        $page        = new self;
        $page->title = $title;
        // parse tags:
        $parts = explode('---', $content);
        if (!isset($parts[1])) {
            return $page;
        }

        // each line is a property:
        $lines = explode("\n", $parts[1]);
        foreach ($lines as $line) {
            $sections = explode(':', $line);
            if ('tags' === $sections[0]) {
                $tags = explode(',', $sections[1]);
            }
        }

        foreach ($tags as $index => $tag) {
            $tags[$index] = trim($tag);
        }
        asort($tags);

        $page->tags = $tags;


        return $page;
    }

    /**
     * @return array
     */
    public function getTodos(): array
    {
        return $this->todos;
    }

    /**
     * @param array $todos
     */
    public function setTodos(array $todos): void
    {
        $this->todos = $todos;
    }

    /**
     * @param int|null $prio
     * @return int
     */
    public function prioCount(?int $prio): int
    {
        $count = 0;

        if (null === $prio) {
            /** @var Todo $todo */
            foreach ($this->todos as $todo) {
                if ($todo->priority > 30 || 0 === $todo->priority && false === $todo->repeater) {
                    $count++;
                }
            }
            return $count;
        }


        /** @var Todo $todo */
        foreach ($this->todos as $todo) {
            if ($todo->priority === $prio && false === $todo->repeater) {
                $count++;
            }
        }
        return $count;
    }

    /**
     * @return int
     */
    public function getWeight(): int
    {

        $weights = [
            10  => 5,
            20  => 1,
            30  => 0.2,
            100 => 0,
        ];
        $total   = 0;

        /** @var Todo $todo */
        foreach ($this->todos as $todo) {
            $weight = $weights[$todo->priority] ?? 0;
            $total  += $weight;
        }
        return $total;
    }

    public function getType(): string
    {
        if (0 === count($this->tags)) {
            return 'zz - Overige';
        }
        if ($this->hasTag('ordina') && $this->hasTag('prospects')) {
            return '1. Prospects';
        }
        if ($this->hasTag('ordina') && $this->hasTag('projects')) {
            return '2. Project';
        }
        if ($this->hasTag('strategyone') && $this->hasTag('people')) {
            return '3. Team';
        }
        if ($this->hasTag('ordina') && $this->hasTag('aor')) {
            return '4. Ordina themes';
        }
        if ($this->hasTag('ordina') && $this->hasTag('people')) {
            return '5. Ordina people';
        }
        return 'zz - Onbekend';
    }

    private function hasTag(string $tag): bool
    {
        return in_array($tag, $this->tags, true);
    }

}