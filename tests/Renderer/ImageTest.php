<?php
namespace Dompdf\Tests\Renderer;

use Dompdf\Adapter\CPDF;
use Dompdf\Dompdf;
use Dompdf\Options;
use Dompdf\Tests\TestCase;

class ImageTest extends TestCase
{
    public static function objectFitProvider(): array
    {
        // The image is 2048 x 1536, the page margins are 0

        return [
            "fill by default" => ["", 200.0, 100.0, [0.0, 0.0, 200.0, 100.0], false],
            "fill" => ["fill", 200.0, 100.0, [0.0, 0.0, 200.0, 100.0], false],
            "contain in a wide box" => ["contain", 400.0, 150.0, [100.0, 0.0, 200.0, 150.0], false],
            "contain in a tall box" => ["contain", 200.0, 300.0, [0.0, 75.0, 200.0, 150.0], false],
            "contain scales up" => ["contain", 4096.0, 4096.0, [0.0, 512.0, 4096.0, 3072.0], false],
            "cover in a wide box" => ["cover", 400.0, 150.0, [0.0, -75.0, 400.0, 300.0], true],
            "cover in a tall box" => ["cover", 200.0, 300.0, [-100.0, 0.0, 400.0, 300.0], true],
            "same ratio" => ["cover", 400.0, 300.0, [0.0, 0.0, 400.0, 300.0], false],
            "unsupported value" => ["scale-down", 400.0, 150.0, [0.0, 0.0, 400.0, 150.0], false]
        ];
    }

    /**
     * @dataProvider objectFitProvider
     */
    #[\PHPUnit\Framework\Attributes\DataProvider('objectFitProvider')]
    public function testObjectFit(string $fit, float $width, float $height, array $expected, bool $clipped): void
    {
        $options = new Options();
        $options->setChroot(realpath(__DIR__ . "/../_files"));

        $dompdf = new Dompdf($options);
        $dompdf->setPaper([0, 0, 5000, 5000]);
        $dompdf->setBasePath(realpath(__DIR__ . "/../_files"));

        $canvas = new class([0, 0, 5000, 5000], "portrait", $dompdf) extends CPDF {
            public $images = [];
            public $clips = [];

            public function image($img, $x, $y, $w, $h, $resolution = "normal")
            {
                $this->images[] = [$x, $y, $w, $h];
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
<body><img src="jamaica.jpg" style="display: block; width: {$width}pt; height: {$height}pt; object-fit: $fit;"></body>
</html>
HTML
        );
        $dompdf->render();

        $this->assertCount(1, $canvas->images);

        foreach ($expected as $index => $value) {
            $this->assertEqualsWithDelta($value, $canvas->images[0][$index], 0.001);
        }

        $this->assertSame($clipped ? [[0.0, 0.0, $width, $height]] : [], $canvas->clips);
    }
}
