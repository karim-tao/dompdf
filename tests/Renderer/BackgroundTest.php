<?php
namespace Dompdf\Tests\Renderer;

use Dompdf\Adapter\CPDF;
use Dompdf\Dompdf;
use Dompdf\Options;
use Dompdf\Tests\TestCase;

class BackgroundTest extends TestCase
{
    /**
     * Render a document on a canvas that records the images drawn and the
     * clipping rectangles.
     */
    private function render(string $body, int $dpi = 72): CPDF
    {
        $options = new Options();
        $options->setChroot(realpath(__DIR__ . "/../_files"));
        $options->setDpi($dpi);

        $dompdf = new Dompdf($options);
        $dompdf->setPaper([0, 0, 1000, 1000]);
        $dompdf->setBasePath(realpath(__DIR__ . "/../_files"));

        $canvas = new class([0, 0, 1000, 1000], "portrait", $dompdf) extends CPDF {
            public $images = [];
            public $clips = [];

            public function image($img, $x, $y, $w, $h, $resolution = "normal")
            {
                $this->images[] = [$img, $x, $y, $w, $h];
            }

            public function clipping_rectangle($x1, $y1, $w, $h)
            {
                $this->clips[] = [$x1, $y1, $w, $h];

                parent::clipping_rectangle($x1, $y1, $w, $h);
            }
        };

        $dompdf->setCanvas($canvas);
        $dompdf->loadHtml(<<<HTML
<html>
<head><style>@page { margin: 0; } body { margin: 0; }</style></head>
<body>$body</body>
</html>
HTML
        );
        $dompdf->render();

        return $canvas;
    }

    public static function singleImageProvider(): array
    {
        // The image is 2048 x 1536 and the box 200pt x 100pt at the top left
        // of the page. The expected image and clipping rectangles are x, y,
        // width and height in pt

        return [
            "cover, centered" => [
                "background-size: cover; background-position: center", 72,
                [0.0, -25.0, 200.0, 150.0], [0.0, 0.0, 200.0, 100.0]
            ],
            "cover, at the top left" => [
                "background-size: cover", 72,
                [0.0, 0.0, 200.0, 150.0], [0.0, 0.0, 200.0, 100.0]
            ],
            "contain, centered, not repeated" => [
                "background-size: contain; background-position: center; background-repeat: no-repeat", 72,
                [34.0, 0.0, 133.0, 100.0], [34.0, 0.0, 133.0, 100.0]
            ],
            "explicit size at the bottom right, not repeated" => [
                "background-size: 100pt auto; background-position: right bottom; background-repeat: no-repeat", 72,
                [100.0, 25.0, 100.0, 75.0], [100.0, 25.0, 100.0, 75.0]
            ],
            "cover, centered, rounded to the pixels of the document resolution" => [
                "background-size: cover; background-position: center", 96,
                [0.0, -25.5, 200.25, 150.0], [0.0, 0.0, 200.0, 100.0]
            ],
        ];
    }

    /**
     * @dataProvider singleImageProvider
     */
    #[\PHPUnit\Framework\Attributes\DataProvider('singleImageProvider')]
    public function testSingleBackgroundImageIsDrawnFromTheOriginalFile(string $style, int $dpi, array $image, array $clip): void
    {
        $canvas = $this->render("<div style=\"width: 200pt; height: 100pt; background-image: url('jamaica.jpg'); $style\"></div>", $dpi);

        $this->assertCount(1, $canvas->images);
        $this->assertSame("file://" . realpath(__DIR__ . "/../_files/jamaica.jpg"), $canvas->images[0][0]);

        foreach ($image as $index => $value) {
            $this->assertEqualsWithDelta($value, $canvas->images[0][$index + 1], 0.001);
        }

        $this->assertCount(1, $canvas->clips);

        foreach ($clip as $index => $value) {
            $this->assertEqualsWithDelta($value, $canvas->clips[0][$index], 0.001);
        }
    }

    public function testRepeatedBackgroundImageIsStillComposed(): void
    {
        $canvas = $this->render("<div style=\"width: 200pt; height: 100pt; background-image: url('red-dot.png'); background-size: 20pt 20pt\"></div>");

        // The tiles are composed into a temporary image
        $this->assertCount(1, $canvas->images);
        $this->assertNotSame("file://" . realpath(__DIR__ . "/../_files/red-dot.png"), $canvas->images[0][0]);
    }

    public function testBackgroundImageIsEmbeddedOnceAsTheOriginalFile(): void
    {
        $options = new Options();
        $options->setChroot(realpath(__DIR__ . "/../_files"));
        $options->setDpi(300);

        $boxes = str_repeat("<div style=\"width: 100pt; height: 60pt; margin-bottom: 10pt; background-image: url('jamaica.jpg'); background-size: cover; background-position: center\"></div>", 3);
        $dompdf = new Dompdf($options);
        $dompdf->setBasePath(realpath(__DIR__ . "/../_files"));
        $dompdf->loadHtml("<html><body>$boxes<div style=\"page-break-before: always\"></div>$boxes</body></html>");
        $dompdf->render();
        $pdf = $dompdf->output();

        $this->assertSame(2, $dompdf->getCanvas()->get_page_count());
        $this->assertSame(1, substr_count($pdf, "/Subtype /Image"));
        $this->assertStringContainsString(file_get_contents(__DIR__ . "/../_files/jamaica.jpg"), $pdf);
    }
}
