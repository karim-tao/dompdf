<?php
/**
 * @package dompdf
 * @link    https://github.com/dompdf/dompdf
 * @license http://www.gnu.org/copyleft/lesser.html GNU Lesser General Public License
 */
namespace Dompdf\Renderer;

use Dompdf\Adapter\CPDF;
use Dompdf\Frame;
use Dompdf\FrameDecorator\Text as TextFrameDecorator;

/**
 * Renders text frames
 *
 * @package dompdf
 */
class Text extends AbstractRenderer
{
    /** Thickness of underline. Screen: 0.08, print: better less, e.g. 0.04 */
    const DECO_THICKNESS = 0.02;

    //Tweaking if $base and $descent are not accurate.
    //Check method_exists( $this->_canvas, "get_cpdf" )
    //- For cpdf these can and must stay 0, because font metrics are used directly.
    //- For other renderers, if different values are wanted, separate the parameter sets.
    //  But $size and $size-$height seem to be accurate enough

    /** Relative to bottom of text, as fraction of height */
    const UNDERLINE_OFFSET = 0.0;

    /** Relative to top of text */
    const OVERLINE_OFFSET = 0.0;

    /** Relative to centre of text. */
    const LINETHROUGH_OFFSET = 0.0;

    /** How far to extend lines past either end, in pt */
    const DECO_EXTENSION = 0.0;

    /**
     * @param \Dompdf\FrameDecorator\Text $frame
     */
    function render(Frame $frame)
    {
        $style = $frame->get_style();
        $text = $frame->get_text();

        if ($text === "") {
            return;
        }

        $this->_set_opacity($frame->get_opacity($style->opacity));

        [$x, $y] = $frame->get_position();
        $cb = $frame->get_containing_block();

        $ml = $style->margin_left;
        $pl = $style->padding_left;
        $bl = $style->border_left_width;
        $x += (float) $style->length_in_pt([$ml, $pl, $bl], $cb["w"]);

        $font = $style->font_family;
        $size = $style->font_size;
        $frame_font_size = $frame->get_dompdf()->getFontMetrics()->getFontHeight($font, $size);
        $word_spacing = $frame->get_text_spacing() + $style->word_spacing;
        $letter_spacing = $style->letter_spacing;
        $width = (float) $style->width;

        /*$text = str_replace(
          array("{PAGE_NUM}"),
          array($this->_canvas->get_page_number()),
          $text
        );*/

        if ($style->has_upright_text()) {
            $this->_render_upright($frame, $x, $y, $text, $font, $size, $style->color, $word_spacing, $letter_spacing);
        } else {
            $this->_canvas->text($x, $y, $text,
                $font, $size,
                $style->color, $word_spacing, $letter_spacing);
        }

        $line = $frame->get_containing_line();

        // FIXME Instead of using the tallest frame to position,
        // the decoration, the text should be well placed
        if (false && $line->tallest_frame) {
            $base_frame = $line->tallest_frame;
            $style = $base_frame->get_style();
            $size = $style->font_size;
        }

        $line_thickness = $size * self::DECO_THICKNESS;
        $underline_offset = $size * self::UNDERLINE_OFFSET;
        $overline_offset = $size * self::OVERLINE_OFFSET;
        $linethrough_offset = $size * self::LINETHROUGH_OFFSET;
        $underline_position = -0.08;

        if ($this->_canvas instanceof CPDF) {
            $cpdf_font = $this->_canvas->get_cpdf()->fonts[$style->font_family];

            if (isset($cpdf_font["UnderlinePosition"])) {
                $underline_position = $cpdf_font["UnderlinePosition"] / 1000;
            }

            if (isset($cpdf_font["UnderlineThickness"])) {
                $line_thickness = $size * ($cpdf_font["UnderlineThickness"] / 1000);
            }
        }

        $descent = $size * $underline_position;
        $base = $frame_font_size;

        // Handle text decoration:
        // http://www.w3.org/TR/CSS21/text.html#propdef-text-decoration

        // Draw all applicable text-decorations.  Start with the root and work our way down.
        $p = $frame;
        $stack = [];
        while ($p = $p->get_parent()) {
            $stack[] = $p;
        }

        while (isset($stack[0])) {
            $f = array_pop($stack);

            if (($text_deco = $f->get_style()->text_decoration) === "none") {
                continue;
            }

            $deco_y = $y; //$line->y;
            $color = $f->get_style()->color;

            switch ($text_deco) {
                default:
                    continue 2;

                case "underline":
                    $deco_y += $base - $descent + $underline_offset + $line_thickness / 2;
                    break;

                case "overline":
                    $deco_y += $overline_offset + $line_thickness / 2;
                    break;

                case "line-through":
                    $deco_y += $base * 0.7 + $linethrough_offset;
                    break;
            }

            $dx = 0;
            $x1 = $x - self::DECO_EXTENSION;
            $x2 = $x + $width + $dx + self::DECO_EXTENSION;
            $this->_canvas->line($x1, $deco_y, $x2, $deco_y, $color, $line_thickness);
        }

        $options = $this->_dompdf->getOptions();

        if ($options->getDebugLayout() && $options->getDebugLayoutLines()) {
            $fontMetrics = $this->_dompdf->getFontMetrics();
            $textWidth = $fontMetrics->getTextWidth($text, $font, $size, $word_spacing, $letter_spacing);
            $this->debugLayout([$x, $y, $textWidth, $frame_font_size], "orange", [0.5, 0.5]);
        }
    }
    /**
     * Render text of a vertical writing mode whose glyphs may be upright: the
     * upright ones are drawn one by one, rotated counter-clockwise so that they
     * stand upright once the line is rotated clockwise, centered in a slot as
     * long as their vertical advance.
     *
     * https://www.w3.org/TR/css-writing-modes-4/#text-orientation
     *
     * @param TextFrameDecorator $frame
     * @param float $x
     * @param float $y
     * @param string $text
     * @param string $font
     * @param float $size
     * @param array $color
     * @param float $word_spacing
     * @param float $letter_spacing
     */
    protected function _render_upright(TextFrameDecorator $frame, float $x, float $y, string $text, $font, float $size, array $color, float $word_spacing, float $letter_spacing): void
    {
        $fontMetrics = $this->_dompdf->getFontMetrics();
        $verticalFontMetrics = $frame->get_reflower()->getFontMetrics();
        $height = $fontMetrics->getFontHeight($font, $size);
        $baseline = $fontMetrics->getFontBaseline($font, $size);

        foreach ($verticalFontMetrics->getRuns($text) as [$run, $upright]) {
            if (!$upright) {
                $this->_canvas->text($x, $y, $run, $font, $size, $color, $word_spacing, $letter_spacing);
                $x += $fontMetrics->getTextWidth($run, $font, $size, $word_spacing, $letter_spacing);
                continue;
            }

            foreach (mb_str_split($run, 1, "UTF-8") as $char) {
                $width = $fontMetrics->getTextWidth($char, $font, $size);
                $this->_canvas->text($x + $baseline, $y + ($height + $width) / 2 - $baseline, $char, $font, $size, $color, 0.0, 0.0, -90);
                $x += $verticalFontMetrics->getUprightAdvance($font, $size, $letter_spacing);
            }
        }
    }
}
