<?php
namespace Dompdf\Tests\LayoutTest;

use DOMElement;
use Dompdf\Canvas;
use Dompdf\Dompdf;
use Dompdf\FrameDecorator\AbstractFrameDecorator;
use Dompdf\Options;
use Dompdf\Tests\TestCase;

class WritingModeTest extends TestCase
{
    private const STYLE = <<<CSS
@page {
    size: 400pt 400pt;
    margin: 20pt;
}

body {
    font-family: DejaVu Sans;
    font-size: 10pt;
    line-height: 1;
}

.box {
    display: inline-block;
    vertical-align: top;
    border: 1pt solid black;
    padding: 0;
    margin: 0;
}

p {
    margin: 0;
}
CSS;

    /**
     * Render the document and collect the content boxes of the elements with
     * an id and of the text frames, keyed by id or by text.
     *
     * @param string $body
     * @return array<string, array{x: float, y: float, w: float, h: float, page: int}>
     */
    private function layout(string $body): array
    {
        $boxes = [];
        $style = self::STYLE;
        $html = "<!DOCTYPE html><html><head><meta charset=\"UTF-8\"><style>$style</style></head><body>$body</body></html>";

        $dompdf = new Dompdf(new Options(["chroot" => realpath(__DIR__ . "/../_files")]));
        $dompdf->setCallbacks([
            [
                "event" => "begin_frame",
                "f" => function (AbstractFrameDecorator $frame, Canvas $canvas) use (&$boxes) {
                    $node = $frame->get_node();

                    if ($node instanceof DOMElement && $node->getAttribute("id") !== "") {
                        $key = $node->getAttribute("id");
                    } elseif ($frame->is_text_node() && trim($frame->get_text()) !== "") {
                        $key = trim($frame->get_text());
                    } else {
                        return;
                    }

                    $box = $frame->get_content_box();
                    $boxes[$key] = ["x" => $box["x"], "y" => $box["y"], "w" => $box["w"], "h" => $box["h"], "page" => $canvas->get_page_number()];
                }
            ]
        ]);
        $dompdf->loadHtml($html);
        $dompdf->render();

        return $boxes;
    }

    public static function orthogonalInlineBlockProvider(): array
    {
        return [
            "vertical-rl" => ["vertical-rl"],
            "vertical-lr" => ["vertical-lr"],
            "sideways-rl" => ["sideways-rl"],
            "sideways-lr" => ["sideways-lr"],
            "legacy tb-rl" => ["tb-rl"]
        ];
    }

    /**
     * The width of an orthogonal box is the height of its line, its height
     * the width of its text.
     *
     * @dataProvider orthogonalInlineBlockProvider
     */
    #[\PHPUnit\Framework\Attributes\DataProvider('orthogonalInlineBlockProvider')]
    public function testOrthogonalInlineBlockSize(string $writingMode): void
    {
        $boxes = $this->layout("<span class=\"box\" id=\"h\">Vertical label</span> <span class=\"box\" id=\"v\" style=\"writing-mode: $writingMode\">Vertical label</span>");

        $this->assertEqualsWithDelta($boxes["h"]["h"], $boxes["v"]["w"], 0.01);
        $this->assertEqualsWithDelta($boxes["h"]["w"], $boxes["v"]["h"], 0.01);
    }

    public function testBlockLevelOrthogonalBoxIsShrinkWrapped(): void
    {
        $boxes = $this->layout("<div id=\"v\" style=\"writing-mode: vertical-rl\">Vertical label</div><p id=\"next\">after</p>");

        $this->assertLessThan(20, $boxes["v"]["w"]);
        $this->assertGreaterThan(50, $boxes["v"]["h"]);
        $this->assertEqualsWithDelta($boxes["v"]["y"] + $boxes["v"]["h"], $boxes["next"]["y"], 0.01);
    }

    public function testVerticalRlStacksLinesFromTheRight(): void
    {
        $boxes = $this->layout("<div class=\"box\" style=\"writing-mode: vertical-rl; height: 100pt\"><p>first</p><p>second</p></div>");

        // Positions of the lines are in the horizontal layout, which is
        // rotated clockwise: the first line, at the top, ends up on the right
        $this->assertLessThan($boxes["second"]["y"], $boxes["first"]["y"]);
    }

    public function testVerticalLrStacksLinesFromTheLeft(): void
    {
        $boxes = $this->layout("<div class=\"box\" style=\"writing-mode: vertical-lr; height: 100pt\"><p>first</p><p>second</p></div>");

        // The order of the lines is reversed before the rotation, so that the
        // first line ends up on the left
        $this->assertGreaterThan($boxes["second"]["y"], $boxes["first"]["y"]);
    }

    public function testHorizontalChildInVerticalFlowKeepsItsOrientation(): void
    {
        $boxes = $this->layout("<div class=\"box\" style=\"writing-mode: vertical-rl; height: 100pt\"><p>vertical</p><div id=\"h\" style=\"writing-mode: horizontal-tb\">horizontal child</div></div>");

        // The horizontal child is orthogonal to its vertical parent: its
        // physical width, the length of its text, is its height in the
        // rotated layout of the parent
        $this->assertGreaterThan($boxes["h"]["w"], $boxes["h"]["h"]);
        $this->assertEqualsWithDelta($boxes["horizontal child"]["w"], $boxes["h"]["h"], 0.01);
    }
}
