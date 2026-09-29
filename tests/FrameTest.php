<?php
namespace Dompdf\Tests;

use DOMDocument;
use Dompdf\Dompdf;
use Dompdf\Frame;

class FrameTest extends TestCase
{
    private function frame(): Frame
    {
        $dom = new DOMDocument();
        $frame = new Frame($dom->createElement("div"));
        $frame->set_style((new Dompdf())->getCss()->create_style());

        return $frame;
    }

    public function testContainingBlock(): void
    {
        $frame = $this->frame();
        $empty = ["x" => null, "y" => null, "w" => null, "h" => null, 0 => null, 1 => null, 2 => null, 3 => null];

        $this->assertSame($empty, $frame->get_containing_block());

        $frame->set_containing_block(1.0, 2.0, 3.0, 4.0);
        $this->assertSame(
            ["x" => 1.0, "y" => 2.0, "w" => 3.0, "h" => 4.0, 0 => 1.0, 1 => 2.0, 2 => 3.0, 3 => 4.0],
            $frame->get_containing_block()
        );

        // Only the given values change, but for an undefined height
        $frame->set_containing_block(null, 5.0, null, null);
        [$x, $y, $w, $h] = $frame->get_containing_block();
        $this->assertSame([1.0, 5.0, 3.0, null], [$x, $y, $w, $h]);
        $this->assertSame(5.0, $frame->get_containing_block("y"));
        $this->assertNull($frame->get_containing_block("h"));

        $frame->reset();
        $this->assertSame($empty, $frame->get_containing_block());
    }

    public function testPosition(): void
    {
        $frame = $this->frame();
        $empty = ["x" => null, "y" => null, 0 => null, 1 => null];

        $this->assertSame($empty, $frame->get_position());

        $frame->set_position(1.0, 2.0);
        $this->assertSame(["x" => 1.0, "y" => 2.0, 0 => 1.0, 1 => 2.0], $frame->get_position());

        $frame->set_position(null, 3.0);
        [$x, $y] = $frame->get_position();
        $this->assertSame([1.0, 3.0], [$x, $y]);
        $this->assertSame(3.0, $frame->get_position("y"));

        $frame->reset();
        $this->assertSame($empty, $frame->get_position());
    }

    public function testFramesDoNotShareTheirContainingBlock(): void
    {
        $a = $this->frame();
        $b = $this->frame();

        $a->set_containing_block(1.0, 2.0, 3.0, 4.0);
        $a->set_position(5.0, 6.0);

        $this->assertNull($b->get_containing_block("x"));
        $this->assertNull($b->get_position("x"));
    }
}
