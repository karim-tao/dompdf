<?php
namespace Dompdf\Tests\LayoutTest;

use DOMElement;
use Dompdf\Canvas;
use Dompdf\Dompdf;
use Dompdf\FrameDecorator\AbstractFrameDecorator;
use Dompdf\Options;
use Dompdf\Tests\TestCase;

class ContainingBlockHeightTest extends TestCase
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
    margin: 0;
}

div {
    margin: 0;
    padding: 0;
}
CSS;

    /**
     * Render the document and collect the content boxes of the elements with
     * an id.
     *
     * @param string $body
     * @param string $style Additional style rules
     * @return array{0: array<string, array{x: float, y: float, w: float, h: float, page: int}>, 1: int}
     */
    private function layout(string $body, string $style = ""): array
    {
        $boxes = [];
        $style = self::STYLE . "\n" . $style;
        $html = "<!DOCTYPE html><html><head><meta charset=\"UTF-8\"><style>$style</style></head><body>$body</body></html>";

        $dompdf = new Dompdf(new Options(["chroot" => realpath(__DIR__ . "/../_files")]));
        $dompdf->setCallbacks([
            [
                "event" => "begin_frame",
                "f" => function (AbstractFrameDecorator $frame, Canvas $canvas) use (&$boxes) {
                    $node = $frame->get_node();

                    if ($node instanceof DOMElement && $node->getAttribute("id") !== "") {
                        $box = $frame->get_content_box();
                        $boxes[$node->getAttribute("id")] = ["x" => $box["x"], "y" => $box["y"], "w" => $box["w"], "h" => $box["h"], "page" => $canvas->get_page_number()];
                    }
                }
            ]
        ]);
        $dompdf->loadHtml($html);
        $dompdf->render();

        return [$boxes, $dompdf->getCanvas()->get_page_count()];
    }

    /**
     * The height of a line of text.
     */
    private function lineHeight(): float
    {
        [$boxes] = $this->layout("<div id=\"line\">text</div>");

        return $boxes["line"]["h"];
    }

    public function testPercentageHeightResolvesAgainstADefiniteParent(): void
    {
        [$boxes] = $this->layout("<div style=\"height: 250pt\"><div id=\"child\" style=\"height: 80%\">child</div></div>");

        $this->assertEqualsWithDelta(200.0, $boxes["child"]["h"], 0.01);
    }

    public function testPercentageHeightInAnAutoParentIsAuto(): void
    {
        [$boxes] = $this->layout("<div id=\"parent\">parent<div id=\"child\" style=\"height: 50%\">child</div></div>");

        $line = $this->lineHeight();
        $this->assertEqualsWithDelta($line, $boxes["child"]["h"], 0.01);
        $this->assertEqualsWithDelta(2 * $line, $boxes["parent"]["h"], 0.01);
    }

    public function testPercentageHeightInTheBodyIsAutoUnlessTheBodyHasAHeight(): void
    {
        [$boxes] = $this->layout("<div id=\"auto\" style=\"height: 50%\">auto</div>");
        [$definite] = $this->layout("<div id=\"half\" style=\"height: 50%\">half</div>", "html, body { height: 100%; }");

        $this->assertEqualsWithDelta($this->lineHeight(), $boxes["auto"]["h"], 0.01);
        $this->assertEqualsWithDelta(180.0, $definite["half"]["h"], 0.01);
    }

    public function testPercentageMaxHeightInAnAutoParentIsIgnored(): void
    {
        $image = realpath(__DIR__ . "/../_files/jamaica.jpg");
        [$boxes] = $this->layout("<div style=\"height: 80%\"></div><div id=\"parent\"><img id=\"image\" src=\"$image\" style=\"max-width: 100%; max-height: 100%\"></div>");

        // The image keeps its intrinsic aspect ratio at the width of the page
        $this->assertEqualsWithDelta(360.0, $boxes["image"]["w"], 0.01);
        $this->assertEqualsWithDelta(270.0, $boxes["image"]["h"], 0.01);
        $this->assertSame(1, $boxes["image"]["page"]);
    }
}
