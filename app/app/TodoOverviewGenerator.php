<?php

namespace App;

use DOMDocument;
use DOMElement;
use League\CommonMark\Environment\Environment;
use League\CommonMark\Extension\CommonMark\CommonMarkCoreExtension;
use League\CommonMark\Extension\GithubFlavoredMarkdownExtension;
use League\CommonMark\MarkdownConverter;
use Monolog\Logger;

/**
 * Class TodoOverviewGenerator
 */
class TodoOverviewGenerator
{
    use ParseTodos;

    private string  $cacheFile;
    private array   $configuration;
    private array   $laters;
    private ?Logger $logger;
    private array   $pages;
    private array   $todos;

    public function __construct()
    {
        $this->configuration = [];
        $this->todos         = [];
        $this->laters        = [];
        $this->logger        = null;
    }

    /**
     * @return array
     */
    public function getPages(): array
    {
        return $this->pages;
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
     * @param array $configuration
     */
    public function setConfiguration(array $configuration): void
    {
        $this->configuration = $configuration;
        $this->cacheFile     = sprintf('%s/%s', $this->configuration['cache'], 'todo.json');
    }

    /**
     * @param Logger|null $logger
     */
    public function setLogger(?Logger $logger): void
    {
        $this->logger = $logger;
        $this->debug('TodoOverviewGenerator has a logger!');
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
     * @param string $message
     */
    private function debug(string $message): void
    {
        $this->logger?->debug($message);
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
     * @param string $file
     */
    private function loadFromLocalFile(string $directory, string $file): void
    {
        $shortName   = str_replace('.md', '', $file);
        $fullName    = sprintf('%s/%s', $directory, $file);
        $fileContent = file_get_contents($fullName);

        // contains either "TO DO" or LATER
        $search = ['TODO', 'LATER'];
        if ($this->contains($fileContent, $search)) {
            $this->parseFileContent($fileContent, $shortName);
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

        // TODO process pagina ook!
        $this->pages[$shortName]
                   = [
            'title' => $shortName,
            'type'  => 'zz - overigen',
            'todos' => [],
        ];
        $topMatter = $this->parseTopMatter($fileContent);

        // add type:
        if (in_array('projects', $topMatter['tags'], true) && in_array('ordina', $topMatter['tags'], true)) {
            $this->pages[$shortName]['type'] = 'project';
        }

        if (in_array('people', $topMatter['tags'], true)) {
            $this->pages[$shortName]['type'] = 'mensen';
        }

        if (in_array('people', $topMatter['tags'], true) && in_array('strategyone', $topMatter['tags'], true)) {
            $this->pages[$shortName]['type'] = 'team';
        }

        $dom = new DOMDocument;
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
                //$this->processLaterLine(trim($text), $shortName);
            }
        }
    }

    /**
     * @param string $fileContent
     *
     * @return array
     */
    private function parseTopMatter(string $fileContent): array
    {
        $return = [
            'tags' => [],
        ];
        $parts  = explode('---', $fileContent);
        if (!isset($parts[1])) {
            return $return;
        }
        $lines = explode("\n", $parts[1]);
        foreach ($lines as $line) {
            $sections = explode(':', $line);
            if ('tags' === $sections[0]) {
                $return['tags'] = explode(',', $sections[1]);
            }
        }

        foreach ($return['tags'] as $index => $tag) {
            $return['tags'][$index] = trim($tag);
        }

        return $return;
    }


}