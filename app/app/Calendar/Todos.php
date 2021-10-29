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

namespace App\Calendar;

use App\Model\Todo;
use App\SharedTraits;
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
use GuzzleHttp\Client;
use GuzzleHttp\Exception\GuzzleException;
use JsonException;
use League\CommonMark\Environment\Environment;
use League\CommonMark\Extension\CommonMark\CommonMarkCoreExtension;
use League\CommonMark\Extension\GithubFlavoredMarkdownExtension;
use League\CommonMark\MarkdownConverter;

/**
 * Class Todos
 */
class Todos
{
    use SharedTraits;

    private string $cacheFile;
    private array  $configuration;
    private array  $todos;

    public function __construct()
    {
        $this->configuration = [];
        $this->todos         = [];
        $this->logger        = null;
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

            // make a block of times for the appointments:
            $prioritiesTimes = [
                100 => [
                    'start' => clone $date,
                    'end'   => clone $date,
                ],
                10  => [
                    'start' => clone $date,
                    'end'   => clone $date,
                ],
                20  => [
                    'start' => clone $date,
                    'end'   => clone $date,
                ],
                30  => [
                    'start' => clone $date,
                    'end'   => clone $date,
                ],
            ];
            // set the correct start times:
            $prioritiesTimes[10]['start']->setTime(6, 0);
            $prioritiesTimes[10]['end']->setTime(6, 30);

            $prioritiesTimes[20]['start']->setTime(12, 0);
            $prioritiesTimes[20]['end']->setTime(12, 30);

            $prioritiesTimes[30]['start']->setTime(15, 0);
            $prioritiesTimes[30]['end']->setTime(15, 30);

            $prioritiesTimes[100]['start']->setTime(17, 0);
            $prioritiesTimes[100]['end']->setTime(17, 30);

            /** @var array $appointment */
            foreach ($appointments as $appointment) {
                // correct time:
                $priority = $appointment['priority'];


                // stacked date and time:
                $string     = substr(hash('sha256', sprintf('%s-%s', $date->format('Y-m-d'), $appointment['todo'])), 0, 16);
                $uid        = new UniqueIdentifier($string);
                $occurrence = new TimeSpan(
                    new DateTime($prioritiesTimes[$priority]['start']->toDateTime(), true), new DateTime($prioritiesTimes[$priority]['end']->toDateTime(), true)
                );
                $organizer  = new Organizer(
                    new EmailAddress($_ENV['ORGANIZER_MAIL']), $_ENV['ORGANIZER_NAME'], null, new EmailAddress($_ENV['ORGANIZER_MAIL'])
                );

                // fix description:
                $appointment['todo'] = trim(str_replace(sprintf('%s:', $appointment['label']), '', $appointment['todo']));
                if (0 === strlen((string) $appointment['label'])) {
                    $appointment['label'] = '!';
                }
                $summary = sprintf('[%s] [%s] %s', $appointment['label'], $appointment['page'], $appointment['todo']);

                // adjust time for the next one:
                $prioritiesTimes[$priority]['start']->addMinutes(30);
                $prioritiesTimes[$priority]['end']->addMinutes(30);

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
     * @return array
     */
    private function groupItemsCalendar(): array
    {
        $newSet = [];
        /** @var Todo $item */
        foreach ($this->todos as $item) {
            if (null === $item->date) {
                continue;
            }
            // separate list of short to do's.
            $dateStr = $item->date->format('Y-m-d', 'Europe/Amsterdam');

            $newSet[$dateStr]   = $newSet[$dateStr] ?? [];
            $newSet[$dateStr][] = [
                'page'     => $item->page,
                'todo'     => $item->text,
                'label'    => $item->keyword,
                'priority' => $item->priority,
                'repeats'  => $item->repeater,
            ];
        }
        ksort($newSet);

        return $newSet;
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
     * @throws JsonException
     */
    private function loadFromCache(): void
    {
        $this->debug('TodoGenerator loaded JSON from cache.');
        $content = file_get_contents($this->cacheFile);
        $json    = json_decode($content, true, 8, JSON_THROW_ON_ERROR);
        /** @var array $array */
        foreach ($json['todo'] as $array) {
            $this->todos[] = Todo::fromArray($array);
        }
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
        $string = (string) $res->getBody();
        $array  = $this->XMLtoArray($string);
        if (!array_key_exists('d:multistatus', $array)) {
            var_dump($string);
            var_dump($array);
            exit;
        }
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

            $shortName = substr($shortName,0,-3);
            // get file content.
            $url         = sprintf('https://%s%s', $_ENV['NEXTCLOUD_HOST'], $filename);
            $fileRequest = $client->get($url, $opts);
            $fileContent = (string) $fileRequest->getBody();

            if (str_contains($fileContent, 'TODO')) {
                $this->debug(sprintf('TodoGenerator now loading from markdown file %s', $file['d:href']));
                // parse as html
                $this->parseFileContent($fileContent, $shortName);
            }
        }
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
        $result = [];
        /** @var DOMElement $listItem */
        foreach ($dom->getElementsByTagName('li') as $listItem) {
            $text = $listItem->textContent;
            if (str_starts_with($text, 'TODO ')) {
                // loop over each line in markdown file
                $todos = $this->processTodoLine(trim($text), $shortName);
                if (0 === count($todos)) {
                    $this->debug($text);
                    $this->debug('Parsing line resulted in zero todo\'s!');
                }
                $result = array_merge($todos, $result);
            }
        }
        $this->todos = array_merge($this->todos, $result);
    }

    /**
     *
     */
    private function saveToCache(): void
    {
        $data = [
            'moment' => time(),
            'todo'   => [],
        ];
        /** @var Todo $todo */
        foreach ($this->todos as $todo) {
            $data['todo'][] = $todo->toArray();
        }
        $result = file_put_contents($this->cacheFile, json_encode($data, JSON_PRETTY_PRINT));
        if (false === $result) {
            die('Could not write to cache.');
        }
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