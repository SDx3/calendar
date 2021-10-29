<?php
/*
 * SharedTraits.php
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

namespace App;

use App\Model\Todo;
use JsonException;
use Monolog\Logger;

/**
 * Trait SharedTraits
 */
trait SharedTraits
{
    protected ?Logger $logger;

    /**
     * @param Logger|null $logger
     */
    public function setLogger(?Logger $logger): void
    {
        $this->logger = $logger;
    }

    /**
     * @param string $message
     */
    protected function debug(string $message): void
    {
        $this->logger?->debug($message);
    }

    /**
     * Process a line that already known to be a to do item.
     *
     * @param string $line
     * @param string $shortName
     * @return array
     */
    protected function processTodoLine(string $line, string $shortName): array
    {
        $parts = explode("\n", $line);
        if (1 === count($parts)) {
            // it's a basic to do with no date
            // add it to array of items but keep the date NULL:
            $todo       = new Todo;
            $todo->page = str_replace('.md', '', $shortName);
            $todo->parseFromSingleTodo($line, $shortName);
            return [$todo];

        }
        // since complex lines could return a lot of to do's, have to use a static function
        if ($this->isRepeatingTodo($line)) {
            return Todo::parseRepeatingTodo($line, $shortName);
        }
        return Todo::parseFromComplexTodo($line, $shortName);
    }


    /**
     * @param string $line
     * @return bool
     */
    protected function isRepeatingTodo(string $line): bool
    {
        $parts = explode("\n", $line);
        foreach ($parts as $part) {
            if (str_starts_with($part, 'SCHEDULED')) {
                if (str_contains($part, '++')) {
                    return true;
                }
                if (str_contains($part, '.+')) {
                    return true;
                }
            }
        }
        return false;
    }


    /**
     * @param string $appointment
     *
     * @return string|null
     */
    protected function getTypeLabel(string $appointment): ?string
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
     * @return bool
     * @throws JsonException
     */
    protected function cacheValid(): bool
    {
        if (!file_exists($this->cacheFile)) {
            $this->debug('TodoGenerator cache not valid because file does not exist.');

            return false;
        }
        if ('never' === $this->configuration['use_cache']) {
            $this->debug('TodoGenerator cache not valid because set to "never".');

            return false;
        }
        if ('always' === $this->configuration['use_cache']) {
            $this->debug('TodoGenerator cache valid because set to "always".');

            return true;
        }
        $content = file_get_contents($this->cacheFile);
        $json    = json_decode($content, true, 8, JSON_THROW_ON_ERROR);
        $moment  = $json['moment'];
        if (time() - $moment < 3599) {
            $this->debug('TodoGenerator cache valid because young file');

            return true;
        }
        $this->debug('TodoGenerator cache invalid because old file.');

        return false;
    }

}