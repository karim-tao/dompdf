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

    public function testTableHeightIncludesBordersAndPadding(): void
    {
        [$boxes] = $this->layout("<table id=\"t\" style=\"height: 100pt; border: 5pt solid black; border-collapse: separate; border-spacing: 0\"><tr><td>auto</td></tr></table>");

        $this->assertEqualsWithDelta(90.0, $boxes["t"]["h"], 0.01);
    }
}
