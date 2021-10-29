<?php

namespace App\Todo;

use App\Model\Page;
use App\Model\Todo;
use App\SharedTraits;
use DOMDocument;
use DOMElement;
use JsonException;
use League\CommonMark\Environment\Environment;
use League\CommonMark\Extension\CommonMark\CommonMarkCoreExtension;
use League\CommonMark\Extension\GithubFlavoredMarkdownExtension;
use League\CommonMark\MarkdownConverter;

/**
 * Class TodoOverviewGenerator
 */
class Generator
{
    use SharedTraits;

    private array  $configuration;
    private array  $pages;
    private array  $tagConfig;
    private string $cacheFile;

    //private array  $todos;

    public function __construct()
    {
        $this->configuration = [];
        $this->logger        = null;
        $this->pages         = [];
        $this->parseTagConfig();
    }

    /**
     * @return array
     */
    public function getPages(): array
    {
        return $this->pages;
    }


    /**
     * @throws JsonException
     */
    public function parse(): void
    {
        $valid = $this->cacheValid();
        if (!$valid) {
            $directories = [
                sprintf('%s/pages', $this->configuration['local_directory']),
                sprintf('%s/journals', $this->configuration['local_directory']),
            ];

            /** @var string $directory */
            foreach ($directories as $directory) {
                $this->debug(sprintf('TodoGenerator will locally parse %s', $directory));
                $this->loadDirectory($directory);
            }
            $this->saveToCache();
        }
        if ($valid) {
            $this->loadFromCache();
        }
    }

    /**
     * @throws JsonException
     */
    private function loadFromCache(): void
    {
        $this->debug('TodoGenerator loaded JSON from cache.');
        $content = file_get_contents($this->cacheFile);
        $json    = json_decode($content, true, 8, JSON_THROW_ON_ERROR);
        foreach ($json['pages'] as $page) {
            $this->pages[] = Page::fromArray($page, $this->tagConfig);
        }
    }

    /**
     *
     */
    private function saveToCache(): void
    {
        $data = [
            'moment' => time(),
            'pages'  => [],
        ];
        /** @var Page $page */
        foreach ($this->pages as $page) {
            $data['pages'][] = $page->toArray();
        }
        $result = file_put_contents($this->cacheFile, json_encode($data, JSON_PRETTY_PRINT));
        if (false === $result) {
            die('Could not write to cache.');
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
     * @param string $directory
     */
    protected function loadDirectory(string $directory): void
    {
        // list all files, don't do a deep dive:
        $files = scandir($directory);
        /** @var string $file */
        foreach ($files as $file) {
            $this->debug(sprintf('Todo Generator found local file %s', $file));
            $parts = explode('.', $file);
            if (count($parts) > 1 && 'md' === strtolower($parts[count($parts) - 1])) {
                // filter on extension:
                $this->loadFile($directory, $file);
            }
        }
    }

    /**
     * @param string $directory
     * @param string $file
     */
    private function loadFile(string $directory, string $file): void
    {
        $shortName   = str_replace('.md', '', $file);
        $fullName    = sprintf('%s/%s', $directory, $file);
        $fileContent = file_get_contents($fullName);

        // check content:
        // contains either "TO DO" or LATER
        $search = ['TODO', 'LATER'];
        if ($this->contains($fileContent, $search)) {
            $this->parseFile($fileContent, $shortName);
        }
    }

    /**
     * @param string $text
     * @param array  $words
     *
     * @return bool
     */
    private function contains(string $text, array $words): bool
    {
        foreach ($words as $word) {
            if (str_contains($text, $word)) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param string $fileContent
     * @param string $shortName
     */
    private function parseFile(string $fileContent, string $shortName): void
    {
        $page = Page::createFromString($fileContent, $shortName);
        $page->setTagConfig($this->tagConfig);
        $todos = $this->parsePageTodos($fileContent, $shortName);
        $page->setTodos($todos);

        $this->pages[] = $page;
    }

    /**
     * @param string $fileContent
     * @param string $shortName
     * @return array
     */
    private function parsePageTodos(string $fileContent, string $shortName): array
    {
        $result      = [];
        $environment = new Environment([]);
        $environment->addExtension(new CommonMarkCoreExtension());
        $environment->addExtension(new GithubFlavoredMarkdownExtension());

        $converter = new MarkdownConverter($environment);
        $html      = trim($converter->convertToHtml($fileContent));
        if ('' === $html) {
            return $result;
        }

        $dom = new DOMDocument;
        $dom->loadHtml($html);
        /** @var DOMElement $listItem */
        foreach ($dom->getElementsByTagName('li') as $listItem) {
            $text = $listItem->textContent;
            if (str_starts_with($text, 'TODO ')) {
                // loop over each line in markdown file
                $todos  = $this->processTodoLine(trim($text), $shortName);
                $result = array_merge($todos, $result);
            }
            if (str_starts_with($text, 'LATER ')) {
                // loop over each line in markdown file
                $laters = $this->processLaterLine(trim($text), $shortName);
                $result = array_merge($laters, $result);
            }
//            if (str_starts_with($text, 'DONE ')) {
//                // loop over each line in markdown file
//                //$this->processLaterLine(trim($text), $shortName);
//                die('Cannot handle this case.');
//            }
        }
        return $result;
    }

    /**
     * Process a line that already known to be a to do item.
     *
     * @param string $line
     * @param string $shortName
     * @return array
     */
    protected function processLaterLine(string $line, string $shortName): array
    {
        // cut off the "LATER " part:
        $shortLine = substr($line, 6);

        $parts = explode("\n", $line);
        if (1 === count($parts)) {

            // it's a basic to do with no date
            // add it to array of items but keep the date NULL:
            $todo       = new Todo;
            $todo->page = str_replace('.md', '', $shortName);
            $todo->parseFromSingleTodo($shortLine, $shortName);
            $todo->type = 'LATER';
            return [$todo];

        }
        // since complex lines could return a lot of to do's, have to use a static function
        if ($this->isRepeatingTodo($shortLine)) {
            die('here A');
            // return Todo::parseRepeatingTodo($line, $shortName);
        }

        return Todo::parseFromComplexTodo($line, $shortName);
    }

    /**
     * @param array $configuration
     */
    public function setConfiguration(array $configuration): void
    {
        $this->configuration = $configuration;
        $this->cacheFile     = sprintf('%s/%s', $this->configuration['cache'], 'todo-pages.json');
    }


    /**
     *
     */
    private function parseTagConfig(): void
    {
        $this->tagConfig = [];
        $config          = $_ENV['TAG_DIVISION'];
        $entries         = explode('|', $config);
        foreach ($entries as $line) {
            $parts                  = explode(':', $line);
            $tags                   = explode(',', $parts[0]);
            $page                   = $parts[1];
            $this->tagConfig[$page] = $tags;
        }
    }
}