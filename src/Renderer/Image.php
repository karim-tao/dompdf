<?php
/**
 * @package dompdf
 * @link    https://github.com/dompdf/dompdf
 * @license http://www.gnu.org/copyleft/lesser.html GNU Lesser General Public License
 */
namespace Dompdf\Renderer;

use Dompdf\Frame;
use Dompdf\FrameDecorator\Image as ImageFrameDecorator;
use Dompdf\Image\Cache;

/**
 * Image renderer
 *
 * @package dompdf
 */
class Image extends Block
{
    /**
     * @param ImageFrameDecorator $frame
     */
    function render(Frame $frame)
    {
        $style = $frame->get_style();
        $node = $frame->get_node();
        $border_box = $frame->get_border_box();

        $this->_set_opacity($frame->get_opacity($style->opacity));

        // Render background & borders
        $this->_render_background($frame, $border_box);
        $this->_render_border($frame, $border_box);
        $this->_render_outline($frame, $border_box);

        $content_box = $frame->get_content_box();
        [$x, $y, $w, $h] = $content_box;

        $src = $frame->get_image_url();

        if (Cache::is_broken($src) && ($alt = $node->getAttribute("alt")) !== "") {
            $font = $style->font_family;
            $size = $style->font_size;
            $word_spacing = $style->word_spacing;
            $letter_spacing = $style->letter_spacing;

            $this->_canvas->text(
                $x,
                $y,
                $alt,
                $font,
                $size,
                $style->color,
                $word_spacing,
                $letter_spacing
            );
        } elseif ($w > 0 && $h > 0) {
            if ($style->has_border_radius()) {
                [$tl, $tr, $br, $bl] = $style->resolve_border_radius($border_box, $content_box);
                $this->_canvas->clipping_roundrectangle($x, $y, $w, $h, $tl, $tr, $br, $bl);
            }

            [$ix, $iy, $iw, $ih] = $this->fit_object($frame, $content_box);
            $clip = $iw > $w || $ih > $h;

            if ($clip) {
                $this->_canvas->clipping_rectangle($x, $y, $w, $h);
            }

            $this->_canvas->image($src, $ix, $iy, $iw, $ih, $style->image_resolution);

            if ($clip) {
                $this->_canvas->clipping_end();
            }

            if ($style->has_border_radius()) {
                $this->_canvas->clipping_end();
            }
        }

        $this->addNamedDest($node);
        $this->debugBlockLayout($frame, "blue");
    }

    /**
     * Resolve the area the image is drawn to within the content box,
     * centered, according to the `object-fit` property.
     *
     * @link https://www.w3.org/TR/css-images-3/#the-object-fit
     *
     * @param ImageFrameDecorator $frame
     * @param float[]             $content_box
     *
     * @return float[] The x and y position, the width, and the height.
     */
    protected function fit_object(ImageFrameDecorator $frame, array $content_box): array
    {
        [$x, $y, $w, $h] = $content_box;
        $fit = $frame->get_style()->object_fit;

        if ($fit === "fill") {
            return [$x, $y, $w, $h];
        }

        [$img_w, $img_h] = $frame->get_intrinsic_dimensions();

        if ($img_w <= 0 || $img_h <= 0) {
            return [$x, $y, $w, $h];
        }

        $scale = $fit === "cover"
            ? max($w / $img_w, $h / $img_h)
            : min($w / $img_w, $h / $img_h);
        $iw = $img_w * $scale;
        $ih = $img_h * $scale;

        return [$x + ($w - $iw) / 2, $y + ($h - $ih) / 2, $iw, $ih];
    }
}
