<?php namespace TightenCo\Jigsaw\Handlers;

use TightenCo\Jigsaw\File\OutputFile;
use TightenCo\Jigsaw\PageData;
use TightenCo\Jigsaw\Parsers\FrontMatterParser;
use TightenCo\Jigsaw\View\ViewRenderer;

class MarkdownHandler
{
    private $temporaryFilesystem;
    private $parser;
    private $view;

    public function __construct($temporaryFilesystem, FrontMatterParser $parser, ViewRenderer $viewRenderer)
    {
        $this->temporaryFilesystem = $temporaryFilesystem;
        $this->parser = $parser;
        $this->view = $viewRenderer;
    }

    public function shouldHandle($file)
    {
        return in_array($file->getExtension(), ['markdown', 'md']);
    }

    public function handleCollectionItem($file, PageData $pageData)
    {
        return $this->buildOutput($file, $pageData);
    }

    public function handle($file, $pageData)
    {
        $pageData->page->addVariables($this->getPageVariables($file));

        return $this->buildOutput($file, $pageData);
    }

    private function getPageVariables($file)
    {
        return array_merge(['section' => 'content'], $this->parseFrontMatter($file));
    }

    private function buildOutput($file, PageData $pageData)
    {
        return collect($pageData->page->extends)
            ->map(function ($layout, $templateToExtend) use ($file, $pageData) {
                if ($templateToExtend) {
                    $pageData->setExtending($templateToExtend);
                }

                $extension = $this->view->getExtension($layout);

                return new OutputFile(
                    $file->getRelativePath(),
                    $file->getFilenameWithoutExtension(),
                    $extension == 'php' ? 'html' : $extension,
                    $this->render($file, $pageData, $layout),
                    $pageData
                );
            });
    }

    private function render($file, $pageData, $layout)
    {
        return $this->temporaryFilesystem->put(
            $this->getEscapedMarkdownContent($file),
            function ($path) use ($pageData, $layout) {
                $duplicatedMarkdownFilename = basename($path, '.blade.md');

                return $this->renderBladeWrapper($duplicatedMarkdownFilename, $pageData, $layout);
            },
            '.blade.md'
        );
    }

    private function getEscapedMarkdownContent($file)
    {
        $content = str_replace('<?php', "__PHP_OPEN__", $file->getContents());

        if ($file->getFullExtension() == 'md') {
            $content = str_replace(
                ['{{', '}}', '{!!', '!!}', '@'],
                ['__OPEN_CURLIES__', '__CLOSE_CURLIES__', '__OPEN_DANGER__', '__CLOSE_DANGER__', '__LONE_AT__'],
                $content
            );
        }

        return str_replace(
            ['__OPEN_CURLIES__', '__CLOSE_CURLIES__', '__OPEN_DANGER__', '__CLOSE_DANGER__', '__LONE_AT__', '__PHP_OPEN__'],
            ["<?= '{{' ?>", "<?= '}}' ?>", "<?= '{!!' ?>", "<?= '!!}' ?>", "<?= '@' ?>", "<?= '<?php' ?>"],
            $content
        );
    }

    private function renderBladeWrapper($duplicatedMarkdownFilename, $pageData, $layout)
    {
        return $this->temporaryFilesystem->put(
            $this->createBladeWrapper($duplicatedMarkdownFilename, $pageData, $layout),
            function ($path) use ($pageData) {
                return $this->view->render($path, $pageData);
            },
            '.blade.php'
        );
    }

    private function parseFrontMatter($file)
    {
        return $this->parser->getFrontMatter($file->getContents());
    }

    private function createBladeWrapper($path, $pageData, $layout)
    {
        return collect([
            "@extends('{$layout}')",
            "@section('{$pageData->page->section}')",
            "@include('{$path}')",
            '@endsection',
        ])->implode("\n");
    }
}
