<?php
namespace Dompdf\Tests\LayoutTest;

use DOMElement;
use Dompdf\Canvas;
use Dompdf\Dompdf;
use Dompdf\FrameDecorator\AbstractFrameDecorator;
use Dompdf\Options;
use Dompdf\Tests\TestCase;

class TableHeightTest extends TestCase
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

table {
    width: 100%;
    border-collapse: collapse;
}

td {
    padding: 0;
    vertical-align: top;
}
CSS;

    /**
     * Render the document and collect the content boxes of the elements with
     * an id and the text of the text frames.
     *
     * @param string $body
     * @return array{0: array<string, array{x: float, y: float, w: float, h: float, page: int}>, 1: string[], 2: int}
     */
    private function layout(string $body): array
    {
        $boxes = [];
        $texts = [];
        $style = self::STYLE;
        $html = "<!DOCTYPE html><html><head><meta charset=\"UTF-8\"><style>$style</style></head><body>$body</body></html>";

        $dompdf = new Dompdf(new Options(["chroot" => realpath(__DIR__ . "/../_files")]));
        $dompdf->setCallbacks([
            [
                "event" => "begin_frame",
                "f" => function (AbstractFrameDecorator $frame, Canvas $canvas) use (&$boxes, &$texts) {
                    $node = $frame->get_node();

                    if ($frame->is_text_node()) {
                        if (trim($frame->get_text()) !== "") {
                            $texts[] = trim($frame->get_text());
                        }
                    } elseif ($node instanceof DOMElement && $node->getAttribute("id") !== "") {
                        $box = $frame->get_content_box();
                        $boxes[$node->getAttribute("id")] = ["x" => $box["x"], "y" => $box["y"], "w" => $box["w"], "h" => $box["h"], "page" => $canvas->get_page_number()];
                    }
                }
            ]
        ]);
        $dompdf->loadHtml($html);
        $dompdf->render();

        return [$boxes, $texts, $dompdf->getCanvas()->get_page_count()];
    }

    /**
     * The height of a line of text, which the auto rows take.
     *
     * @return float
     */
    private function lineHeight(): float
    {
        [$boxes] = $this->layout("<div id=\"line\">text</div>");

        return $boxes["line"]["h"];
    }

    public function testExtraHeightGoesToThePercentageRow(): void
    {
        [$boxes] = $this->layout("<table id=\"t\" style=\"height: 120pt\"><tr><td id=\"a\">auto</td></tr><tr style=\"height: 100%\"><td id=\"b\">rest</td></tr></table>");

        $line = $this->lineHeight();
        $this->assertEqualsWithDelta(120.0, $boxes["t"]["h"], 0.01);
        $this->assertEqualsWithDelta($line, $boxes["a"]["h"], 0.01);
        $this->assertEqualsWithDelta(120.0 - $line, $boxes["b"]["h"], 0.01);
        $this->assertEqualsWithDelta($boxes["a"]["y"] + $line, $boxes["b"]["y"], 0.01);
    }

    public function testExtraHeightIsSharedEquallyByAutoRows(): void
    {
        [$boxes] = $this->layout("<table style=\"height: 90pt\"><tr><td id=\"a\">one</td></tr><tr><td id=\"b\">two</td></tr><tr><td id=\"c\">three</td></tr></table>");

        foreach (["a", "b", "c"] as $id) {
            $this->assertEqualsWithDelta(30.0, $boxes[$id]["h"], 0.01);
        }

        $this->assertEqualsWithDelta($boxes["a"]["y"] + 30.0, $boxes["b"]["y"], 0.01);
        $this->assertEqualsWithDelta($boxes["b"]["y"] + 30.0, $boxes["c"]["y"], 0.01);
    }

    public function testPercentageRowsGetTheirShareOfTheTableHeight(): void
    {
        [$boxes] = $this->layout("<table style=\"height: 120pt\"><tr style=\"height: 25%\"><td id=\"a\">a</td></tr><tr style=\"height: 75%\"><td id=\"b\">b</td></tr></table>");

        $this->assertEqualsWithDelta(30.0, $boxes["a"]["h"], 0.01);
        $this->assertEqualsWithDelta(90.0, $boxes["b"]["h"], 0.01);
    }

    public function testPercentageOnTheCellCountsForItsRow(): void
    {
        [$boxes] = $this->layout("<table style=\"height: 80pt\"><tr><td id=\"a\">auto</td></tr><tr><td id=\"b\" style=\"height: 100%\">rest</td></tr></table>");

        $line = $this->lineHeight();
        $this->assertEqualsWithDelta($line, $boxes["a"]["h"], 0.01);
        $this->assertEqualsWithDelta(80.0 - $line, $boxes["b"]["h"], 0.01);
    }

    public function testRowSpanningCellCoversTheStretchedRow(): void
    {
        [$boxes] = $this->layout("<table style=\"height: 100pt\"><tr><td id=\"span\" rowspan=\"2\">span</td><td id=\"a\">a</td></tr><tr style=\"height: 100%\"><td id=\"rest\">rest</td></tr><tr><td id=\"b\">b</td><td id=\"c\">c</td></tr></table>");

        $line = $this->lineHeight();
        $this->assertEqualsWithDelta($line, $boxes["a"]["h"], 0.01);
        $this->assertEqualsWithDelta(100.0 - 2 * $line, $boxes["rest"]["h"], 0.01);
        $this->assertEqualsWithDelta(100.0 - $line, $boxes["span"]["h"], 0.01);
        $this->assertEqualsWithDelta($boxes["rest"]["y"] + 100.0 - 2 * $line, $boxes["b"]["y"], 0.01);
    }

    public function testCellContentIsAlignedInTheStretchedRow(): void
    {
        [$boxes] = $this->layout("<table style=\"height: 60pt\"><tr><td id=\"top\">top</td><td id=\"middle\" style=\"vertical-align: middle\"><div id=\"m\">middle</div></td><td id=\"bottom\" style=\"vertical-align: bottom\"><div id=\"b\">bottom</div></td></tr></table>");

        $line = $this->lineHeight();
        $this->assertEqualsWithDelta(60.0, $boxes["top"]["h"], 0.01);
        $this->assertEqualsWithDelta($boxes["middle"]["y"] + (60.0 - $line) / 2, $boxes["m"]["y"], 0.01);
        $this->assertEqualsWithDelta($boxes["bottom"]["y"] + 60.0 - $line, $boxes["b"]["y"], 0.01);
    }

    public function testTableTallerThanTheContentButNotSplitKeepsItsHeight(): void
    {
        [$boxes] = $this->layout("<table id=\"t\" style=\"height: 100pt; border-collapse: separate; border-spacing: 10pt\"><tr><td id=\"a\">auto</td></tr><tr style=\"height: 100%\"><td id=\"b\">rest</td></tr></table>");

        // The height applies to the border box, which includes the spacing;
        // the auto row and the stretched cell each hold two halves of it
        $line = $this->lineHeight();
        $this->assertEqualsWithDelta(100.0 - 10.0, $boxes["t"]["h"], 0.01);
        $this->assertEqualsWithDelta(100.0 - 10.0 - 10.0 - $line - 10.0, $boxes["b"]["h"], 0.01);
    }

    public function testSplitTableKeepsTheContentHeightOfItsFragments(): void
    {
        $rows = str_repeat("<tr><td>row</td></tr>", 60);
        [$boxes, , $pageCount] = $this->layout("<table style=\"height: 900pt\">$rows<tr style=\"height: 100%\"><td id=\"rest\">rest</td></tr></table><p id=\"after\">after</p>");

        $this->assertSame(3, $pageCount);
        $this->assertEqualsWithDelta($this->lineHeight(), $boxes["rest"]["h"], 0.01);
        $this->assertSame(3, $boxes["after"]["page"]);
    }

    public function testPercentageRowsBeyondTheTableHeightAreScaledDown(): void
    {
        [$boxes] = $this->layout("<table style=\"height: 100pt\"><tr style=\"height: 60%\"><td id=\"a\">a</td></tr><tr style=\"height: 60%\"><td id=\"b\">b</td></tr></table>");

        $this->assertEqualsWithDelta(50.0, $boxes["a"]["h"], 0.01);
        $this->assertEqualsWithDelta(50.0, $boxes["b"]["h"], 0.01);
    }

    public function testRemainingHeightGoesToEveryRowWhenThereIsNoAutoRow(): void
    {
        [$boxes] = $this->layout("<table style=\"height: 100pt\"><tr style=\"height: 20%\"><td id=\"a\">a</td></tr><tr style=\"height: 60%\"><td id=\"b\">b</td></tr></table>");

        $this->assertEqualsWithDelta(30.0, $boxes["a"]["h"], 0.01);
        $this->assertEqualsWithDelta(70.0, $boxes["b"]["h"], 0.01);
    }

    public function testRowsWithFixedHeightsOnlyGrowInEqualParts(): void
    {
        [$boxes] = $this->layout("<table style=\"height: 120pt\"><tr><td id=\"a\" style=\"height: 20pt\">a</td></tr><tr><td id=\"b\" style=\"height: 40pt\">b</td></tr></table>");

        $this->assertEqualsWithDelta(50.0, $boxes["a"]["h"], 0.01);
        $this->assertEqualsWithDelta(70.0, $boxes["b"]["h"], 0.01);
    }

    public function testRowWithAFixedHeightDoesNotGrow(): void
    {
        [$boxes] = $this->layout("<table style=\"height: 100pt\"><tr><td id=\"fixed\" style=\"height: 20pt\">fixed</td></tr><tr><td id=\"auto\">auto</td></tr></table>");

        $this->assertEqualsWithDelta(20.0, $boxes["fixed"]["h"], 0.01);
        $this->assertEqualsWithDelta(80.0, $boxes["auto"]["h"], 0.01);
    }

    public function testImageWithAPercentageMaxHeightStaysVisibleInTheStretchedRow(): void
    {
        $image = realpath(__DIR__ . "/../_files/jamaica.jpg");
        [$boxes] = $this->layout("<table style=\"height: 200pt\"><tr><td>title</td></tr><tr style=\"height: 100%\"><td><img id=\"image\" src=\"$image\" style=\"width: 40pt; max-height: 100%\"></td></tr></table>");

        $this->assertEqualsWithDelta(30.0, $boxes["image"]["h"], 0.01);
    }

    public function testRowsOfATableTallerThanThePageAreNotStretched(): void
    {
        [$boxes] = $this->layout("<table style=\"height: 600pt\"><tr><td id=\"a\">a</td></tr><tr><td id=\"b\">b</td></tr></table>");

        // The stretched rows would run off the page: the table is laid out as
        // without the distribution, and every row stays visible
        $line = $this->lineHeight();
        $this->assertEqualsWithDelta($line, $boxes["a"]["h"], 0.01);
        $this->assertEqualsWithDelta($line, $boxes["b"]["h"], 0.01);
    }

    public function testTableNotFittingTheRemainingSpaceIsStretchedOnTheNextPage(): void
    {
        [$boxes] = $this->layout("<div style=\"height: 250pt\"></div><table style=\"height: 200pt\"><tr><td id=\"a\">a</td></tr><tr><td id=\"b\">b</td></tr></table>");

        $this->assertSame(2, $boxes["a"]["page"]);
        $this->assertEqualsWithDelta(100.0, $boxes["a"]["h"], 0.01);
        $this->assertEqualsWithDelta(100.0, $boxes["b"]["h"], 0.01);
    }

    public function testRowspanCellFittingItsRowsLeavesThemAlone(): void
    {
        [$boxes] = $this->layout("<table><tr><td id=\"a\">Test</td><td rowspan=\"4\">1<br>2<br>3<br>4</td></tr><tr><td id=\"b\">Test</td></tr><tr><td id=\"c\">Test</td></tr><tr><td id=\"d\">Test</td></tr></table>");

        $line = $this->lineHeight();

        foreach (["a", "b", "c", "d"] as $id) {
            $this->assertEqualsWithDelta($line, $boxes[$id]["h"], 0.01);
        }
    }

    public function testRowspanExtraHeightGoesToTheAutoRowsNotToTheFixedOnes(): void
    {
        [$boxes] = $this->layout("<table><tr><td rowspan=\"4\">1<br>2<br>3<br>4<br>5<br>6<br>7<br>8</td><td id=\"a\" style=\"height: 1px\">1px</td></tr><tr style=\"height: 1px\"><td id=\"b\">1px</td></tr><tr><td id=\"c\">auto</td></tr><tr><td id=\"d\">auto</td></tr></table>");

        $line = $this->lineHeight();
        $this->assertEqualsWithDelta($line, $boxes["a"]["h"], 0.01);
        $this->assertEqualsWithDelta($line, $boxes["b"]["h"], 0.01);
        $this->assertEqualsWithDelta(3 * $line, $boxes["c"]["h"], 0.01);
        $this->assertEqualsWithDelta(3 * $line, $boxes["d"]["h"], 0.01);
        $this->assertEqualsWithDelta($boxes["c"]["y"] + 3 * $line, $boxes["d"]["y"], 0.01);
    }

    public function testRowHeightIsAMinimum(): void
    {
        [$boxes] = $this->layout("<table><tr id=\"a\" style=\"height: 40pt\"><td>40pt</td></tr><tr id=\"b\" style=\"height: 5pt\"><td>5pt</td></tr></table>");

        $this->assertEqualsWithDelta(40.0, $boxes["a"]["h"], 0.01);
        $this->assertEqualsWithDelta($this->lineHeight(), $boxes["b"]["h"], 0.01);
    }

    public function testPercentageHeightsResolveAgainstTheStretchedCell(): void
    {
        [$boxes] = $this->layout("<table style=\"height: 120pt\"><tr><td id=\"a\">auto</td></tr><tr style=\"height: 100%\"><td id=\"b\"><div id=\"half\" style=\"height: 50%\">half</div></td></tr></table>");

        $line = $this->lineHeight();
        $this->assertEqualsWithDelta(120.0 - $line, $boxes["b"]["h"], 0.01);
        $this->assertEqualsWithDelta((120.0 - $line) / 2, $boxes["half"]["h"], 0.01);
    }

    public function testImageFillsTheStretchedCell(): void
    {
        $image = realpath(__DIR__ . "/../_files/jamaica.jpg");
        [$boxes] = $this->layout("<table style=\"height: 200pt\"><tr><td id=\"a\">title</td></tr><tr style=\"height: 100%\"><td style=\"line-height: 0\"><img id=\"i\" src=\"$image\" style=\"max-width: 100%; max-height: 100%\"></td></tr></table>");

        // 2048 x 1536 px: the height of the cell limits the image
        $line = $this->lineHeight();
        $this->assertEqualsWithDelta(200.0 - $line, $boxes["i"]["h"], 0.01);
        $this->assertEqualsWithDelta((200.0 - $line) * 2048 / 1536, $boxes["i"]["w"], 0.01);
    }

    public function testNestedTableInTheStretchedCell(): void
    {
        [$boxes] = $this->layout("<table style=\"height: 150pt\"><tr><td id=\"a\">auto</td></tr><tr style=\"height: 100%\"><td id=\"b\"><table id=\"n\" style=\"height: 60pt\"><tr><td id=\"na\">auto</td></tr><tr style=\"height: 100%\"><td id=\"nb\">rest</td></tr></table></td></tr></table>");

        $line = $this->lineHeight();
        $this->assertEqualsWithDelta(150.0 - $line, $boxes["b"]["h"], 0.01);
        $this->assertEqualsWithDelta(60.0, $boxes["n"]["h"], 0.01);
        $this->assertEqualsWithDelta(60.0 - $line, $boxes["nb"]["h"], 0.01);
    }

    public function testCountersSurviveTheSecondLayoutPass(): void
    {
        [, $texts] = $this->layout("<style>ol { counter-reset: n; list-style: none; margin: 0; padding: 0; } li::before { counter-increment: n; content: counter(n) \". \"; }</style><table style=\"height: 80pt\"><tr><td>auto</td></tr><tr style=\"height: 100%\"><td><ol><li>one</li><li>two</li><li>three</li></ol></td></tr></table>");

        $this->assertSame(["auto", "1.", "one", "2.", "two", "3.", "three"], $texts);
    }

    public function testTableHeightIncludesBordersAndPadding(): void
    {
        [$boxes] = $this->layout("<table id=\"t\" style=\"height: 100pt; border: 5pt solid black; border-collapse: separate; border-spacing: 0\"><tr><td id=\"a\">auto</td></tr></table>");

        $this->assertEqualsWithDelta(90.0, $boxes["t"]["h"], 0.01);
        $this->assertEqualsWithDelta(90.0, $boxes["a"]["h"], 0.01);
    }
}
