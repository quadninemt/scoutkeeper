<?php

declare(strict_types=1);

namespace Tests\Core;

use App\Core\Application;
use App\Core\TwigRenderer;
use PHPUnit\Framework\TestCase;

/**
 * Compiles every Twig template in the application.
 *
 * Guards against data-dependent 500s from unknown filters/functions:
 * Twig only reports "Unknown filter" when the template is parsed, so a
 * branch that renders only when data exists (e.g. the status-changes
 * report used |json_decode before it was registered) can crash in
 * production while every smoke test passes on empty data.
 */
class TemplateCompilationTest extends TestCase
{
    protected function setUp(): void
    {
        Application::reset();
        Application::init(TEST_CONFIG);
    }

    protected function tearDown(): void
    {
        Application::reset();
    }

    public function testAllTemplatesCompile(): void
    {
        $renderer = new TwigRenderer(Application::getInstance());
        $twig = $renderer->getEnvironment();
        $loader = $twig->getLoader();

        $roots = [
            '' => ROOT_PATH . '/app/templates',
        ];
        foreach (glob(ROOT_PATH . '/app/modules/*/templates', GLOB_ONLYDIR) as $dir) {
            $module = strtolower(basename(dirname($dir)));
            $roots['@' . $module . '/'] = $dir;
        }

        $compiled = 0;
        $failures = [];

        foreach ($roots as $prefix => $root) {
            $iterator = new \RecursiveIteratorIterator(
                new \RecursiveDirectoryIterator($root, \FilesystemIterator::SKIP_DOTS)
            );
            foreach ($iterator as $file) {
                if (!str_ends_with($file->getFilename(), '.twig')) {
                    continue;
                }
                $name = $prefix . substr($file->getPathname(), strlen($root) + 1);
                $name = str_replace('\\', '/', $name);

                try {
                    $source = $loader->getSourceContext($name);
                    $twig->parse($twig->tokenize($source));
                    $compiled++;
                } catch (\Twig\Error\Error $e) {
                    $failures[] = $name . ': ' . $e->getMessage();
                }
            }
        }

        $this->assertSame(
            [],
            $failures,
            "Templates failed to compile:\n" . implode("\n", $failures)
        );
        $this->assertGreaterThan(100, $compiled, 'Template sweep should cover the whole app');
    }
}
