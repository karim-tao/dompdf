<?php
namespace Dompdf\Tests\LayoutTest;

use DOMElement;
use Dompdf\Canvas;
use Dompdf\Dompdf;
use Dompdf\FrameDecorator\AbstractFrameDecorator;
use Dompdf\Options;
use Dompdf\Tests\TestCase;

class PageTest extends TestCase
{
    public static function pageBreakProvider(): array
    {
        return [
            // TODO: Heredocs can be nicely indented starting with PHP 7.3
            "one page" => [
                <<<HTML
<!DOCTYPE html>
<html>
<head>
<meta charset="UTF-8">
<style>
    @page {
        size: 400pt 400pt;
        margin: 0;
    }

    body {
        background-color: rgb(0, 0, 0, 0.05);
    }

    .box {
        height: 400pt;
        background-color: lightblue;
    }
</style>
</head>
<body><div class="box"></div></body>
</html>
HTML
,
                1,
                ["box" => 1]
            ],
            "two pages" => [
                <<<HTML
<!DOCTYPE html>
<html>
<head>
<meta charset="UTF-8">
<style>
    @page {
        size: 400pt 400pt;
        margin: 0;
    }

    @page :first {
        margin-bottom: 100pt;
    }

    body {
        background-color: rgb(0, 0, 0, 0.05);
    }

    .box {
        height: 400pt;
        background-color: lightblue;
    }
</style>
</head>
<body><div class="box"></div></body>
</html>
HTML
,
                2,
                ["box" => 2]
            ],
        ];
    }

    /**
     * @dataProvider pageBreakProvider
     */
    #[\PHPUnit\Framework\Attributes\DataProvider('pageBreakProvider')]
    public function testPageBreak(
        string $html,
        int $pageCount,
        array $expectedPages
    ): void {
        $elementPages = [];

        $options = new Options();

        // Use callback to inspect frame tree
        $dompdf = new Dompdf($options);
        $dompdf->setCallbacks([
            [
                "event" => "begin_frame",
                "f" => function (AbstractFrameDecorator $frame, Canvas $canvas) use ($expectedPages, &$elementPages) {
                    $node = $frame->get_node();

                    if (!($node instanceof DOMElement)) {
                        return;
                    }

                    $class = $node->getAttribute("class");

                    if (isset($expectedPages[$class])) {
                        $elementPages[$class] = $canvas->get_page_number();
                    }
                }
            ]
        ]);

        $dompdf->loadHtml($html);
        $dompdf->render();

        $this->assertSame($pageCount, $dompdf->getCanvas()->get_page_count());

        foreach ($expectedPages as $class => $pageNumber) {
            $this->assertSame($pageNumber, $elementPages[$class] ?? 0);
        }
    }

    public function testFramesOfRenderedPagesAreFreed(): void
    {
        $rows = str_repeat("<tr><td>Cell</td><td style=\"background-color: red\">Cell</td></tr>", 200);
        $dompdf = new Dompdf();
        $dompdf->loadHtml(<<<HTML
<html>
<head><style>
    .fixed { position: fixed; top: 0; }
    td { border: 1px solid; }
</style></head>
<body>
<div class="fixed">Fixed</div>
<table style="border-collapse: collapse"><thead><tr><td>Head</td></tr></thead>$rows</table>
</body>
</html>
HTML
        );

        // Frames only held in reference cycles are not freed while the
        // garbage collector is disabled
        $frames = class_exists(\WeakMap::class) ? new \WeakMap() : null;
        $ids = [];
        $page = 0;
        $dompdf->setCallbacks([[
            "event" => "end_page_render",
            "f" => function (AbstractFrameDecorator $frame) use ($frames, &$ids, &$page) {
                if (++$page > 1) {
                    return;
                }

                foreach ($frame->get_children() as $child) {
                    $this->collectFrames($child, $frames, $ids);
                }
            }
        ]]);

        $gcEnabled = gc_enabled();
        gc_disable();

        try {
            $dompdf->render();
        } finally {
            if ($gcEnabled) {
                gc_enable();
            }
        }

        $this->assertGreaterThan(2, $page);
        $this->assertNotEmpty($ids);

        foreach ($ids as $id) {
            $this->assertNull($dompdf->getTree()->get_frame($id));
        }

        if ($frames !== null) {
            $this->assertCount(0, $frames);
        }
    }

    /**
     * @param AbstractFrameDecorator $frame
     * @param \WeakMap|null          $frames
     * @param array                  $ids
     */
    private function collectFrames(AbstractFrameDecorator $frame, $frames, array &$ids): void
    {
        $ids[] = $frame->get_id();

        if ($frames !== null) {
            $frames[$frame] = true;
            $frames[$frame->get_frame()] = true;
            $frames[$frame->get_style()] = true;
        }

        foreach ($frame->get_children() as $child) {
            $this->collectFrames($child, $frames, $ids);
        }
    }
}
