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

use JetBrains\PhpStorm\ArrayShape;
use JetBrains\PhpStorm\Pure;

class Page
{
    public string $title;
    public array  $todos;
    public array  $tags;
    public array  $tagConfig;

    /**
     *
     */
    public function __construct()
    {
        $this->todos     = [];
        $this->tags      = [];
        $this->tagConfig = [];
    }

    /**
     * @param array $tagConfig
     */
    public function setTagConfig(array $tagConfig): void
    {
        $this->tagConfig = $tagConfig;
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
            $tags[$index] = strtolower(trim($tag));
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
     * @return string
     */
    public function getClass(): string
    {
        return self::asClass($this->title);

    }

    public static function asClass(string $title): string
    {
        $search = [' ', '(', ')'];

        return strtolower(str_replace($search, '-', $title));
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
     *
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
            10  => 50,
            20  => 10,
            30  => 2,
            50  => 2,
            100 => 1,
        ];
        $total   = 0;

        /** @var Todo $todo */
        foreach ($this->todos as $todo) {
            if (false === $todo->repeater && 'TODO' === $todo->type) {
                $weight = $weights[$todo->priority];
                $total  += $weight;
            }
        }

        return $total;
    }

    /**
     * @return string
     */
    #[Pure]
    public function getType(): string
    {
        if (0 === count($this->tags)) {
            return 'zz - Overige';
        }
        foreach ($this->tagConfig as $page => $tags) {
            if ($this->hasAllTags($tags)) {
                return $page;
            }
        }

        return 'zz - Onbekend';
    }

    /**
     * @param string $tag
     *
     * @return bool
     */
    private function hasTag(string $tag): bool
    {
        return in_array(strtolower($tag), $this->tags, true);
    }

    /**
     * @param array $tags
     *
     * @return bool
     */
    #[Pure]
    private function hasAllTags(array $tags): bool
    {
        $count = 0;
        foreach ($tags as $tag) {
            if ($this->hasTag($tag)) {
                $count++;
            }
        }

        return $count === count($tags);
    }

    /**
     * @param array $page
     * @param       $tagConfig
     * @return static
     */
    public static function fromArray(array $page, $tagConfig): self
    {
        $object        = new self;
        $object->setTagConfig($tagConfig);
        $object->title = $page['title'];
        $object->tags  = $page['tags'];
        $todos         = [];
        foreach ($page['todos'] as $todo) {
            $todos[] = Todo::fromArray($todo);
        }
        $object->setTodos($todos);

        return $object;
    }

    /**
     * @return array
     */
    #[ArrayShape(['title' => "string", 'tags' => "array", 'todos' => "array"])]
    public function toArray(): array
    {
        $arr = [
            'title' => $this->title,
            'tags'  => $this->tags,
            'todos' => [],
        ];
        /** @var Todo $todo */
        foreach ($this->todos as $todo) {
            $arr['todos'][] = $todo->toArray();
        }

        return $arr;
    }
}