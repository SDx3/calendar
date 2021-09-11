<?php
/*
 * TodoCalendarGenerator.php
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

use Carbon\Carbon;
use DOMDocument;
use DOMElement;
use Eluceo\iCal\Domain\Entity\Calendar;
use Eluceo\iCal\Domain\Entity\Event;
use Eluceo\iCal\Domain\Entity\TimeZone;
use Eluceo\iCal\Domain\ValueObject\DateTime;
use Eluceo\iCal\Domain\ValueObject\EmailAddress;
use Eluceo\iCal\Domain\ValueObject\Organizer;
use Eluceo\iCal\Domain\ValueObject\TimeSpan;
use Eluceo\iCal\Domain\ValueObject\UniqueIdentifier;
use Eluceo\iCal\Presentation\Factory\CalendarFactory;
use GuzzleHttp\Client;
use GuzzleHttp\Exception\GuzzleException;
use JsonException;

/**
 * Class TodoCalendarGenerator
 */
class TodoCalendarGenerator
{
    private array  $configuration;
    private array  $todos;
    private string $cacheFile;

    public function __construct()
    {
        $this->configuration = [];
        $this->todos         = [];
    }

    /**
     * @throws JsonException
     * @throws GuzzleException
     */
    public function parseTodos(): void
    {
        $valid = $this->cacheValid();
        if ($valid) {
            $this->loadFromCache();
        }
        if (!$valid) {
            $this->loadFromNextcloud();
            $this->saveToCache();
        }
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
            $this->loadFromLocalDirectory($directory);
        }
    }

    /**
     * @param string $directory
     */
    private function loadFromLocalDirectory(string $directory): void
    {
        // list all files, dont do a deep dive:
        $files = scandir($directory);
        /** @var string $file */
        foreach ($files as $file) {
            $parts = explode('.', $file);
            if (count($parts) > 1 && 'md' === strtolower($parts[count($parts) - 1])) {
                // filter on extension:
                $this->loadFromLocalFile($directory, $file);
            }

        }
    }

    /**
     * @param string $directory
     * @param string $file
     */
    private function loadFromLocalFile(string $directory, string $file): void
    {
        $shortName   = str_replace('.md', '', $file);
        $fullName    = sprintf('%s/%s', $directory, $file);
        $fileContent = file_get_contents($fullName);

        // loop over each line in markdown file
        $lines = explode("\n", $fileContent);
        foreach ($lines as $line) {
            $this->processLine(trim($line), $shortName);
        }
    }


    /**
     * @return bool
     * @throws JsonException
     */
    private function cacheValid(): bool
    {
        if (!file_exists($this->cacheFile)) {
            return false;
        }
        if ('never' === $this->configuration['use_cache']) {
            return false;
        }
        if ('always' === $this->configuration['use_cache']) {
            return true;
        }
        $content = file_get_contents($this->cacheFile);
        $json    = json_decode($content, true, 8, JSON_THROW_ON_ERROR);
        $moment  = $json['moment'];
        if (time() - $moment < 3599) {
            return true;
        }
        return false;
    }

    /**
     * @throws JsonException
     */
    private function loadFromCache(): void
    {
        $content     = file_get_contents($this->cacheFile);
        $json        = json_decode($content, true, 8, JSON_THROW_ON_ERROR);
        $this->todos = $json['todo'];
    }

    /**
     * Load to do's from Nextcloud by looping all files etc.
     * @throws GuzzleException
     */
    private function loadFromNextcloud(): void
    {
        $urls = [
            sprintf('https://%s/remote.php/dav/files/%s/%s/pages/', $this->configuration['host'], $this->configuration['username'], $this->configuration['path']),
            sprintf('https://%s/remote.php/dav/files/%s/%s/journals/', $this->configuration['host'], $this->configuration['username'], $this->configuration['path']),
        ];
        /** @var string $url */
        foreach ($urls as $url) {
            $this->loadFromUrl($url);
        }
    }

    /**
     * @param string $url
     * @throws GuzzleException
     */
    private function loadFromUrl(string $url): void
    {
        $client = new Client;
        $opts   = [
            'auth'    => [$this->configuration['username'], $this->configuration['password']],
            'headers' => [],
        ];
        $res    = $client->request('PROPFIND', $url, $opts);
        $string = (string) $res->getBody();
        $array  = $this->XMLtoArray($string);
        /** @var array $file */
        foreach ($array['d:multistatus']['d:response'] as $file) {
            $this->loadFromFile($file);
        }
    }

    /**
     * @param string $xml
     * @return array
     */
    private function XMLtoArray(string $xml): array
    {
        $previous_value          = libxml_use_internal_errors(true);
        $dom                     = new DOMDocument('1.0', 'UTF-8');
        $dom->preserveWhiteSpace = false;
        $dom->loadXml($xml);
        libxml_use_internal_errors($previous_value);
        if (libxml_get_errors()) {
            return [];
        }

        return $this->DOMtoArray($dom);
    }

    /**
     * @param DOMDocument|DOMElement $root
     * @return array|string
     */
    private function DOMtoArray(DOMDocument|DOMElement $root): array|string
    {
        $result = [];

        if ($root->hasAttributes()) {
            $attrs = $root->attributes;
            foreach ($attrs as $attr) {
                $result['@attributes'][$attr->name] = $attr->value;
            }
        }

        if ($root->hasChildNodes()) {
            $children = $root->childNodes;
            if ($children->length == 1) {
                $child = $children->item(0);
                if (in_array($child->nodeType, [XML_TEXT_NODE, XML_CDATA_SECTION_NODE])) {
                    $result['_value'] = $child->nodeValue;

                    return count($result) == 1
                        ? $result['_value']
                        : $result;
                }

            }
            $groups = [];
            foreach ($children as $child) {
                if (!isset($result[$child->nodeName])) {
                    $result[$child->nodeName] = $this->DOMtoArray($child);
                } else {
                    if (!isset($groups[$child->nodeName])) {
                        $result[$child->nodeName] = [$result[$child->nodeName]];
                        $groups[$child->nodeName] = 1;
                    }
                    $result[$child->nodeName][] = $this->DOMtoArray($child);
                }
            }
        }

        return $result;
    }

    /**
     * @param array $file
     * @throws GuzzleException
     */
    private function loadFromFile(array $file): void
    {
        $client   = new Client;
        $opts     = ['auth' => [$this->configuration['username'], $this->configuration['password']]];
        $filename = $file['d:href'];

        // get extension of file:
        $parts = explode('.', $filename);
        $ext   = $parts[count($parts) - 1];

        // get file name of file:
        $parts     = explode('/', $filename);
        $shortName = urldecode($parts[count($parts) - 1]);

        if ('md' === $ext) {
            // get file content.
            $url         = sprintf('https://%s%s', $_ENV['NEXTCLOUD_HOST'], $filename);
            $fileRequest = $client->get($url, $opts);
            $fileContent = (string) $fileRequest->getBody();

            // loop over each line in markdown file
            $lines = explode("\n", $fileContent);
            foreach ($lines as $line) {
                $this->processLine(trim($line), $shortName);
            }
        }
    }

    /**
     * @param string $line
     * @param string $shortName
     */
    private function processLine(string $line, string $shortName): void
    {
        $pattern = '/\[\[\w* \d\d \w* [0-9]{4}\]\]/';

        // if the line starts with "TODO" (whatever level)
        if (str_starts_with($line, '- TODO ') && $this->hasDateRef($line) && str_contains($line, '#ready')) {

            // do a lazy preg match
            $matches = [];
            preg_match($pattern, $line, $matches);
            if (isset($matches[0])) {
                // if it's also a valid date, continue!
                $dateStr = str_replace(['[', ']'], '', $matches[0]);
                $dateObj = Carbon::createFromFormat('!l d F Y', $dateStr, 'Europe/Amsterdam');

                // add it to array of todo's:
                $todo          = [
                    'page' => str_replace('.md', '', $shortName),
                    'todo' => trim(str_replace(['- TODO', '#ready', $matches[0]], '', $line)),
                    'date' => $dateObj->toW3cString(),
                ];
                $this->todos[] = $todo;
            }
        }
        // if it is a todo but no date ref! :(
        if (str_starts_with($line, '- TODO ') && !$this->hasDateRef($line) && str_contains($line, '#ready')) {
            // add it to array of todo's:
            $todo          = [
                'page' => str_replace('.md', '', $shortName),
                'todo' => trim(str_replace(['- TODO', '#ready'], '', $line)),
                'date' => null,
            ];
            $this->todos[] = $todo;
        }
    }

    /**
     * I can't believe im this lazy!
     *
     * @param string $string
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
     *
     */
    private function saveToCache(): void
    {
        $data   = [
            'moment' => time(),
            'todo'   => $this->todos,
        ];
        $result = file_put_contents($this->cacheFile, json_encode($data, JSON_PRETTY_PRINT));
        if (false === $result) {
            die('Could not write to cache.');
        }
    }

    /**
     * @return string
     */
    public function generateCalendar(): string
    {
        $grouped = $this->groupItems();

        // loop set and create calendar items
        $calendar = new Calendar;
        $timezone = new TimeZone($_ENV['TZ']);
        $calendar->addTimeZone($timezone);

        foreach ($grouped as $dateString => $appointments) {
            $date = Carbon::createFromFormat('Y-m-d', $dateString, 'Europe/Amsterdam');

            $appointmentStart = clone $date;
            $appointmentEnd   = clone $date;
            $appointmentStart->setTime(6, 0);
            $appointmentEnd->setTime(6, 30);

            foreach ($appointments as $appointment) {
                // stacked date and time:
                $string     = substr(hash('sha256', sprintf('%s-%s', $date->format('Y-m-d'), $appointment)), 0, 16);
                $uid        = new UniqueIdentifier($string);
                $occurrence = new TimeSpan(new DateTime($appointmentStart->toDateTime(), true), new DateTime($appointmentEnd->toDateTime(), true));
                $organizer  = new Organizer(new EmailAddress($_ENV['ORGANIZER_MAIL']), $_ENV['ORGANIZER_NAME'], null, new EmailAddress($_ENV['ORGANIZER_MAIL']));

                // adjust time for the next one:
                $appointmentStart->addMinutes(30);
                $appointmentEnd->addMinutes(30);

                $vEvent = new Event($uid);
                $vEvent->setOrganizer($organizer)->setSummary($appointment)->setDescription($appointment)->setOccurrence($occurrence);
                $calendar->addEvent($vEvent);
            }
        }
        $componentFactory = new CalendarFactory(new TransparentEventFactory);
        return $componentFactory->createCalendar($calendar);
    }

    /**
     * @return string
     */
    public function generateHtml(): string
    {
        $grouped = $this->groupItems();
        $html    = '';
        foreach ($grouped as $dateString => $appointments) {
            $count = count($appointments);
            if (0 === $count) {
                continue;
            }
            if ('0000-00-00' !== $dateString) {
                $date       = Carbon::createFromFormat('Y-m-d', $dateString, 'Europe/Amsterdam');
                $headerDate = str_replace('  ', ' ', $date->formatLocalized('%A %e %B %Y'));
            }

            if ('0000-00-00' === $dateString) {
                $headerDate = '(ready but no date)';
            }
            if ($count > 5) {
                $html .= sprintf('<h2 style="color:red;">%s</h2><ul>', $headerDate);
            }
            if (5 === $count) {
                $html .= sprintf('<h2 style="color:darkblue;">%s</h2><ul>', $headerDate);
            }
            if ($count < 5) {
                $html .= sprintf('<h2>%s</h2><ul>', $headerDate);
            }

            foreach ($appointments as $appointment) {
                $html .= sprintf('<li>%s</li>', $appointment);
            }
            $html .= '</ul>';
        }

        return $html;
    }

    /**
     * @return array
     */
    private function groupItems(): array
    {
        $newSet = [];
        /** @var array $item */
        foreach ($this->todos as $item) {
            if (null === $item['date']) {
                $dateStr = '0000-00-00';
            }
            if (null !== $item['date']) {
                $date    = Carbon::createFromFormat(Carbon::W3C, $item['date'], 'Europe/Amsterdam');
                $dateStr = $date->format('Y-m-d', 'Europe/Amsterdam');
            }

            $newSet[$dateStr]   = $newSet[$dateStr] ?? [];
            $newSet[$dateStr][] = $item['page'] . ': ' . $item['todo'];
        }
        ksort($newSet);
        return $newSet;
    }

    /**
     * @param array $configuration
     */
    public function setConfiguration(array $configuration): void
    {
        $this->configuration = $configuration;
        $this->cacheFile     = sprintf('%s/%s', $this->configuration['cache'], 'todo.json');
    }


}