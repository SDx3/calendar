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
use Carbon\Exceptions\InvalidFormatException;
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
use GuzzleHttp\Client;
use GuzzleHttp\Exception\GuzzleException;
use JsonException;
use League\CommonMark\Environment\Environment;
use League\CommonMark\Extension\CommonMark\CommonMarkCoreExtension;
use League\CommonMark\Extension\GithubFlavoredMarkdownExtension;
use League\CommonMark\MarkdownConverter;
use Monolog\Logger;

/**
 * Class TodoCalendarGenerator
 */
class TodoCalendarGenerator
{
    private array   $configuration;
    private array   $todos;
    private array   $laters;
    private string  $cacheFile;
    private ?Logger $logger;

    public function __construct()
    {
        $this->configuration = [];
        $this->todos         = [];
        $this->laters        = [];
        $this->logger        = null;
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
     * @throws JsonException
     * @throws GuzzleException
     */
    public function parseTodos(): void
    {
        $valid = $this->cacheValid();
        if ($valid) {
            $this->debug('TodoGenerator finds the cache is valid.');
            $this->loadFromCache();
        }
        if (!$valid) {
            $this->debug('TodoGenerator finds the cache is invalid');
            $this->loadFromNextcloud();
            $this->saveToCache();
        }
        $this->debug(sprintf('TodoGenerator has found %d todo\'s!', count($this->todos)));
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

    /**
     * @param string $directory
     */
    private function loadFromLocalDirectory(string $directory): void
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

        $this->parseFileContent($fileContent, $shortName);
    }

    /**
     * @param string $fileContent
     * @param string $shortName
     */
    private function parseFileContent(string $fileContent, string $shortName): void
    {
        // Define your configuration, if needed
        $config = [];

        // Configure the Environment with all the CommonMark and GFM parsers/renderers
        $environment = new Environment($config);
        $environment->addExtension(new CommonMarkCoreExtension());
        $environment->addExtension(new GithubFlavoredMarkdownExtension());

        $converter = new MarkdownConverter($environment);
        $html      = trim($converter->convertToHtml($fileContent));
        if ('' === $html) {
            return;
        }
        $dom = new DOMDocument();
        $dom->loadHtml($html);
        /** @var DOMElement $listItem */
        foreach ($dom->getElementsByTagName('li') as $listItem) {
            $text = $listItem->textContent;
            if (str_starts_with($text, 'TODO ')) {
                // loop over each line in markdown file
                $this->processTodoLine(trim($text), $shortName);
            }
            if (str_starts_with($text, 'LATER ')) {
                // loop over each line in markdown file
                $this->processLaterLine(trim($text), $shortName);
            }
        }
    }


    /**
     * @param string $message
     */
    private function debug(string $message): void
    {
        $this->logger?->debug($message);
    }


    /**
     * @return bool
     * @throws JsonException
     */
    private function cacheValid(): bool
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

    /**
     * @throws JsonException
     */
    private function loadFromCache(): void
    {
        $this->debug('TodoGenerator loaded JSON from cache.');
        $content      = file_get_contents($this->cacheFile);
        $json         = json_decode($content, true, 8, JSON_THROW_ON_ERROR);
        $this->todos  = $json['todo'];
        $this->laters = $json['laters'];
    }

    /**
     * Load to do's from Nextcloud by looping all files etc.
     *
     * @throws GuzzleException
     */
    private function loadFromNextcloud(): void
    {
        $urls = [
            sprintf(
                'https://%s/remote.php/dav/files/%s/%s/pages/', $this->configuration['host'], $this->configuration['username'], $this->configuration['path']
            ),
            sprintf(
                'https://%s/remote.php/dav/files/%s/%s/journals/', $this->configuration['host'], $this->configuration['username'], $this->configuration['path']
            ),
        ];
        /** @var string $url */
        foreach ($urls as $url) {
            $this->debug(sprintf('TodoGenerator now loading from URL %s', $url));
            $this->loadFromUrl($url);
        }
    }

    /**
     * @param string $url
     *
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
        $string = (string)$res->getBody();
        $array  = $this->XMLtoArray($string);
        /** @var array $file */
        foreach ($array['d:multistatus']['d:response'] as $file) {
            $this->loadFromFile($file);
        }
    }

    /**
     * @param string $xml
     *
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
     *
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
     *
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
            $fileContent = (string)$fileRequest->getBody();

            if (str_contains($fileContent, 'TODO')) {
                $this->debug(sprintf('TodoGenerator now loading from markdown file %s', $file['d:href']));
                // parse as html
                $this->parseFileContent($fileContent, $shortName);
            }
        }
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
                'priority' => $this->getPriority($line),
            ];
            $this->laters[] = $later;

            return;
        }
        $later = [
            'page'     => str_replace('.md', '', $shortName),
            'later'    => 'volgt',
            'short'    => false,
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
     * Process a line that alreadt known to be a to do item.
     *
     * @param string $line
     * @param string $shortName
     */
    private function processTodoLine(string $line, string $shortName): void
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
     *
     */
    private function saveToCache(): void
    {
        $data   = [
            'moment' => time(),
            'todo'   => $this->todos,
            'laters' => $this->laters,
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
        $grouped = $this->groupItemsCalendar();

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

            /** @var array $appointment */
            foreach ($appointments as $appointment) {
                // stacked date and time:
                $string     = substr(hash('sha256', sprintf('%s-%s', $date->format('Y-m-d'), $appointment['todo'])), 0, 16);
                $uid        = new UniqueIdentifier($string);
                $occurrence = new TimeSpan(new DateTime($appointmentStart->toDateTime(), true), new DateTime($appointmentEnd->toDateTime(), true));
                $organizer  = new Organizer(
                    new EmailAddress($_ENV['ORGANIZER_MAIL']), $_ENV['ORGANIZER_NAME'], null, new EmailAddress($_ENV['ORGANIZER_MAIL'])
                );

                // fix description:
                $appointment['todo'] = trim(str_replace(sprintf('%s:', $appointment['label']), '', $appointment['todo']));
                if (0 === strlen((string)$appointment['label'])) {
                    $appointment['label'] = '!';
                }
                $summary = sprintf('[%s] [%s] %s', $appointment['label'], $appointment['page'], $appointment['todo']);

                // adjust time for the next one:
                $appointmentStart->addMinutes(30);
                $appointmentEnd->addMinutes(30);

                $vEvent = new Event($uid);
                $vEvent->setOrganizer($organizer)
                       ->setSummary($summary)
                       ->setDescription($appointment['todo'])
                       ->setOccurrence($occurrence);
                $calendar->addEvent($vEvent);
            }
        }
        $componentFactory = new RefreshFactory(new TransparentEventFactory);

        return $componentFactory->createCalendar($calendar);
    }

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
                $date    = Carbon::createFromFormat(Carbon::W3C, $item['date'], 'Europe/Amsterdam');
                $dateStr = $date->format('Y-m-d', 'Europe/Amsterdam');
            }

            // separate list of short to do's.
            if (isset($item['short']) && $item['short']) {
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
     * @return array
     */
    private function groupItemsCalendar(): array
    {
        $newSet = [];
        /** @var array $item */
        foreach ($this->todos as $item) {
            if (null === $item['date']) {
                continue;
            }
            // separate list of short to do's.
            if (isset($item['short']) && $item['short']) {
                continue;
            }
            $date    = Carbon::createFromFormat(Carbon::W3C, $item['date'], 'Europe/Amsterdam');
            $dateStr = $date->format('Y-m-d', 'Europe/Amsterdam');

            $newSet[$dateStr]   = $newSet[$dateStr] ?? [];
            $newSet[$dateStr][] = ['page' => $item['page'], 'todo' => $item['todo'], 'label' => $this->getTypeLabel($item['todo'])];
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
     * @param array $appointments
     *
     * @return string
     */
    private function renderShortTodos(array $appointments): string
    {
        $html = '<h2>Very short TODO\'s</h2><ol>';
        /** @var array $appointment */
        foreach ($appointments as $appointment) {
            $html .= sprintf('<li>%s</li>', $this->colorizeTodo($appointment));
        }
        $html .= '</ol>';

        return $html;

    }

    /**
     * @param array $appointments
     *
     * @return string
     */
    private function renderDatelessTodos(array $appointments): string
    {
        $html = sprintf('<h2>TODO\'s with no date <small>(%d)</small></h2><ol>', count($appointments));
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

        $html = sprintf('<h2 style="color:%s;">%s <small>(%d)</small></h2><ol>', $color, str_replace('  ', ' ', $date->formatLocalized('%A %e %B %Y')), $count);
        /** @var array $appointment */
        foreach ($appointments as $appointment) {
            $html .= sprintf('<li>%s</li>', $this->colorizeTodo($appointment));
        }
        $html .= '</ol>';

        return $html;
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
     * @return string
     */
    private function renderLaters(): string
    {
        if (0 === count($this->laters)) {
            return '';
        }
        $html = '<h2>Later (ooit)</h2><ol>';

        foreach ($this->laters as $later) {
            $html .= sprintf('<li>%s</li>', $this->colorizeTodo($later));
        }
        $html .= '</ol>';

        return $html;
    }

    /**
     * @param array  $array
     * @param string $dateString
     * @param string $separator
     */
    private function parseRepeater(array $array, string $dateString, string $separator): void
    {
        $today = new Carbon;
        $end   = new Carbon;
        $end->addMonths(3);

        // lazy split to get repeater in place
        $parts = explode($separator, $dateString);

        // first date is this one:
        $dateObject = Carbon::createFromFormat('!Y-m-d D', trim($parts[0]), 'Europe/Amsterdam');
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
            $start->$func($period);
            if ($start >= $today) {
                // add to do!
                $currentTodo         = $array;
                $currentTodo['date'] = $start->toW3cString();
                $this->todos[]       = $currentTodo;
            }
        }
    }

}