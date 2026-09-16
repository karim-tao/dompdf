<?php
namespace Dompdf\Tests\LayoutTest;

use DOMElement;
use Dompdf\Canvas;
use Dompdf\Dompdf;
use Dompdf\FrameDecorator\AbstractFrameDecorator;
use Dompdf\LineBox;
use Dompdf\Options;
use Dompdf\Tests\TestCase;

class VerticalAlignTest extends TestCase
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

p {
    margin: 0;
}

.box {
    display: inline-block;
    width: 30pt;
    height: 40pt;
}

td {
    padding: 0;
}
CSS;

    /**
     * Render the document and collect the content boxes of the elements with
     * an id and of the text frames, keyed by id or by text.
     *
     * @param string $body
     * @return array<string, array{x: float, y: float, w: float, h: float}>
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
                    $boxes[$key] = ["x" => $box["x"], "y" => $box["y"], "w" => $box["w"], "h" => $box["h"]];
                }
            ]
        ]);
        $dompdf->loadHtml($html);
        $dompdf->render();

        return $boxes;
    }

    /**
     * The height of a line of text.
     */
    private function lineHeight(): float
    {
        $boxes = $this->layout("<p id=\"line\">text</p>");

        return $boxes["line"]["h"];
    }

    public function testTextOnlyLineIsOneLineHigh(): void
    {
        $boxes = $this->layout("<p id=\"p\">text with <b>bold</b> and <i>italic</i> text</p>");

        $this->assertEqualsWithDelta($this->lineHeight(), $boxes["p"]["h"], 0.01);
    }

    public function testTallInlineBlockStandsOnTheBaselineWithinTheLine(): void
    {
        $boxes = $this->layout("<p id=\"p\">before <span id=\"box\" class=\"box\"></span> after</p>");
        $plain = $this->layout("<p id=\"p\">before <span>after</span></p>");

        // The text moves down to the baseline of the box, from its position
        // on the baseline of a line of text
        $line = $this->lineHeight();
        $this->assertEqualsWithDelta($boxes["p"]["y"], $boxes["box"]["y"], 0.01);
        $this->assertEqualsWithDelta(40.0 + (1 - LineBox::ASCENT_RATIO) * $line, $boxes["p"]["h"], 0.01);
        $this->assertEqualsWithDelta($plain["after"]["y"] + 40.0 - LineBox::ASCENT_RATIO * $line, $boxes["after"]["y"], 0.01);
    }

    public function testLineWithoutTextIsAsHighAsItsBoxes(): void
    {
        $boxes = $this->layout("<p id=\"p\"><span id=\"box\" class=\"box\"></span> <span class=\"box\" style=\"height: 20pt\"></span></p>");

        $this->assertEqualsWithDelta($boxes["p"]["y"], $boxes["box"]["y"], 0.01);
        $this->assertEqualsWithDelta(40.0, $boxes["p"]["h"], 0.01);
    }

    public function testInlineBlockWithTextIsAlignedByTheBaselineOfItsLastLine(): void
    {
        $boxes = $this->layout("<p id=\"p\">before <span id=\"box\" class=\"box\" style=\"height: auto\">one<br>two</span> after</p>");

        $this->assertEqualsWithDelta($boxes["p"]["y"], $boxes["box"]["y"], 0.01);
        $this->assertEqualsWithDelta($boxes["two"]["y"], $boxes["after"]["y"], 0.01);
        $this->assertEqualsWithDelta(2 * $this->lineHeight(), $boxes["p"]["h"], 0.01);
    }

    public function testMiddleAlignedBoxIsCentredAboveTheBaseline(): void
    {
        $boxes = $this->layout("<p id=\"p\">before <span id=\"box\" class=\"box\" style=\"vertical-align: middle\"></span> after</p>");

        $plain = $this->layout("<p id=\"p\">before <span>after</span></p>");

        // The middle of the box is half an x-height, a quarter of the font
        // size, above the baseline, so the box stands 2.5pt below where it
        // would on the baseline
        $line = $this->lineHeight();
        $this->assertEqualsWithDelta($boxes["p"]["y"], $boxes["box"]["y"], 0.01);
        $this->assertEqualsWithDelta(40.0, $boxes["p"]["h"], 0.01);
        $this->assertEqualsWithDelta($plain["after"]["y"] + 20.0 + 2.5 - LineBox::ASCENT_RATIO * $line, $boxes["after"]["y"], 0.01);
    }

    public function testTopAndBottomAlignedBoxesTouchTheEdgesOfTheLine(): void
    {
        $boxes = $this->layout("<p id=\"p\">text <span id=\"top\" class=\"box\" style=\"vertical-align: top\"></span> <span id=\"bottom\" class=\"box\" style=\"vertical-align: bottom; height: 20pt\"></span></p>");

        $this->assertEqualsWithDelta($boxes["p"]["y"], $boxes["top"]["y"], 0.01);
        $this->assertEqualsWithDelta($boxes["p"]["y"] + $boxes["p"]["h"], $boxes["bottom"]["y"] + 20.0, 0.01);
    }

    public function testShiftedTextGrowsTheLine(): void
    {
        $boxes = $this->layout("<p id=\"p\">text <span style=\"vertical-align: 10pt\">up</span></p>");

        $this->assertEqualsWithDelta($this->lineHeight() + 10.0, $boxes["p"]["h"], 0.01);
        $this->assertEqualsWithDelta($boxes["text"]["y"] - 10.0, $boxes["up"]["y"], 0.01);
    }

    public function testImageAloneInACellFillsTheCell(): void
    {
        $image = realpath(__DIR__ . "/../_files/jamaica.jpg");
        $boxes = $this->layout("<table><tr><td id=\"cell\"><img id=\"image\" src=\"$image\" style=\"width: 40pt\"></td></tr></table>");

        $this->assertEqualsWithDelta($boxes["cell"]["y"], $boxes["image"]["y"], 0.01);
        $this->assertEqualsWithDelta(30.0, $boxes["cell"]["h"], 0.01);
    }

    public function testImageWithTextInACellStandsOnTheBaseline(): void
    {
        $image = realpath(__DIR__ . "/../_files/jamaica.jpg");
        $boxes = $this->layout("<table><tr><td id=\"cell\"><img id=\"image\" src=\"$image\" style=\"width: 40pt\"> text</td></tr></table>");

        $this->assertEqualsWithDelta($boxes["cell"]["y"], $boxes["image"]["y"], 0.01);
        $this->assertEqualsWithDelta(30.0 + (1 - LineBox::ASCENT_RATIO) * $this->lineHeight(), $boxes["cell"]["h"], 0.01);
    }
}
