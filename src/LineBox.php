<?php
/**
 * @package dompdf
 * @link    https://github.com/dompdf/dompdf
 * @license http://www.gnu.org/copyleft/lesser.html GNU Lesser General Public License
 */
namespace Dompdf;

use Dompdf\FrameDecorator\AbstractFrameDecorator;
use Dompdf\FrameDecorator\Block;
use Dompdf\FrameDecorator\ListBullet;
use Dompdf\FrameDecorator\Page;
use Dompdf\FrameDecorator\TableCell as TableCellFrameDecorator;
use Dompdf\FrameReflower\Text as TextFrameReflower;
use Dompdf\Positioner\Inline as InlinePositioner;
use Iterator;

/**
 * The line box class
 *
 * This class represents a line box
 * http://www.w3.org/TR/CSS2/visuren.html#line-box
 *
 * @package dompdf
 */
class LineBox
{
    /**
     * Share of the height of an inline box above its baseline. The rest is
     * the depth of the descenders below it
     */
    const ASCENT_RATIO = 0.8;

    /**
     * @var Block
     */
    protected $_block_frame;

    /**
     * The frames the line box has been made high enough for, with their
     * margin heights, see `fit()`
     *
     * @var array<array{0: Frame, 1: float}>
     */
    protected $_fitted = [];

    /**
     * @var AbstractFrameDecorator[]
     */
    protected $_frames = [];

    /**
     * @var ListBullet[]
     */
    protected $list_markers = [];

    /**
     * @var int
     */
    public $wc = 0;

    /**
     * @var float
     */
    public $y = 0.0;

    /**
     * @var float
     */
    public $w = 0.0;

    /**
     * @var float
     */
    public $h = 0.0;

    /**
     * Height of the line box above its baseline
     *
     * @var float
     */
    public $ascent = 0.0;

    /**
     * Depth of the line box below its baseline
     *
     * @var float
     */
    public $descent = 0.0;

    /**
     * @var float
     */
    public $left = 0.0;

    /**
     * @var float
     */
    public $right = 0.0;

    /**
     * @var AbstractFrameDecorator
     */
    public $tallest_frame = null;

    /**
     * @var bool[]
     */
    public $floating_blocks = [];

    /**
     * @var bool
     */
    public $br = false;

    /**
     * Whether the line box contains any inline-positioned frames.
     *
     * @var bool
     */
    public $inline = false;

    /**
     * @var int
     */
    public static $max_float_reflows = 10000;

    /**
     * FIXME smelly hack, used by the get_float_offsets method.
     *
     * @var int
     */
    private static $float_offset_anti_infinite_loop = 10000;

    /**
     * @param Block $frame the Block containing this line
     * @param float $y
     */
    public function __construct(Block $frame, float $y = 0.0)
    {
        $this->_block_frame = $frame;
        $this->_frames = [];
        $this->y = $y;

        $this->get_float_offsets();
    }

    /**
     * Returns the floating elements inside the first floating parent
     *
     * @param Page $root
     *
     * @return Frame[]
     */
    public function get_floats_inside(Page $root): array
    {
        $floating_frames = $root->get_floating_frames();

        if (count($floating_frames) == 0) {
            return $floating_frames;
        }

        // Find nearest floating element
        $p = $this->_block_frame;
        while ($p->get_style()->float === "none") {
            $parent = $p->get_parent();

            if (!$parent) {
                break;
            }

            $p = $parent;
        }

        if ($p == $root) {
            return $floating_frames;
        }

        $parent = $p;

        $childs = [];

        foreach ($floating_frames as $_floating) {
            $p = $_floating->get_parent();

            while (($p = $p->get_parent()) && $p !== $parent);

            if ($p) {
                $childs[] = $p;
            }
        }

        return $childs;
    }

    /**
     * Resets the anti-infinite-loop counter for the get_float_offsets method.
     */
    public static function reset_float_reflow_limit(): void
    {
        self::$float_offset_anti_infinite_loop = self::$max_float_reflows;
    }

    public function get_float_offsets(): void
    {
        $reflower = $this->_block_frame->get_reflower();

        if (!$reflower) {
            return;
        }

        $cb_w = null;

        $block = $this->_block_frame;
        $root = $block->get_root();

        if (!$root) {
            return;
        }

        $style = $this->_block_frame->get_style();
        $floating_frames = $this->get_floats_inside($root);
        $inside_left_floating_width = 0;
        $inside_right_floating_width = 0;
        $outside_left_floating_width = 0;
        $outside_right_floating_width = 0;

        foreach ($floating_frames as $child_key => $floating_frame) {
            $floating_frame_parent = $floating_frame->get_parent();
            $id = $floating_frame->get_id();

            if (isset($this->floating_blocks[$id])) {
                continue;
            }

            $float = $floating_frame->get_style()->float;
            $floating_width = $floating_frame->get_margin_width();

            if (!$cb_w) {
                $cb_w = $floating_frame->get_containing_block("w");
            }

            $line_w = $this->get_width();

            if (!$floating_frame->_float_next_line && ($cb_w <= $line_w + $floating_width) && ($cb_w > $line_w)) {
                $floating_frame->_float_next_line = true;
                continue;
            }

            // If the child is still shifted by the floating element
            if (self::$float_offset_anti_infinite_loop-- > 0 &&
                $floating_frame->get_position("y") + $floating_frame->get_margin_height() >= $this->y &&
                $block->get_position("x") + $block->get_margin_width() >= $floating_frame->get_position("x")
            ) {
                if ($float === "left") {
                    if ($floating_frame_parent === $this->_block_frame) {
                        $inside_left_floating_width += $floating_width;
                    } else {
                        $outside_left_floating_width += $floating_width;
                    }
                } elseif ($float === "right") {
                    if ($floating_frame_parent === $this->_block_frame) {
                        $inside_right_floating_width += $floating_width;
                    } else {
                        $outside_right_floating_width += $floating_width;
                    }
                }

                $this->floating_blocks[$id] = true;
            } // else, the floating element won't shift anymore
            else {
                $root->remove_floating_frame($child_key);
            }
        }

        $this->left += $inside_left_floating_width;
        if ($outside_left_floating_width > 0 && $outside_left_floating_width > ((float)$style->length_in_pt($style->margin_left) + (float)$style->length_in_pt($style->padding_left))) {
            $this->left += $outside_left_floating_width - (float)$style->length_in_pt($style->margin_left) - (float)$style->length_in_pt($style->padding_left);
        }
        $this->right += $inside_right_floating_width;
        if ($outside_right_floating_width > 0 && $outside_right_floating_width > ((float)$style->length_in_pt($style->margin_left) + (float)$style->length_in_pt($style->padding_right))) {
            $this->right += $outside_right_floating_width - (float)$style->length_in_pt($style->margin_right) - (float)$style->length_in_pt($style->padding_right);
        }
    }

    /**
     * @return float
     */
    public function get_width(): float
    {
        return $this->left + $this->w + $this->right;
    }

    /**
     * @return Block
     */
    public function get_block_frame(): Block
    {
        return $this->_block_frame;
    }

    /**
     * @return AbstractFrameDecorator[]
     */
    public function &get_frames(): array
    {
        return $this->_frames;
    }

    /**
     * @return bool
     */
    public function is_empty(): bool
    {
        return $this->_frames === [];
    }

    /**
     * @param AbstractFrameDecorator $frame
     */
    public function add_frame(Frame $frame): void
    {
        $this->_frames[] = $frame;

        if ($frame->get_positioner() instanceof InlinePositioner) {
            $this->inline = true;
        }
    }

    /**
     * Remove the frame at the given index and all following frames from the
     * line.
     *
     * @param int $index
     */
    public function remove_frames(int $index): void
    {
        $lastIndex = count($this->_frames) - 1;

        if ($index < 0 || $index > $lastIndex) {
            return;
        }

        for ($i = $lastIndex; $i >= $index; $i--) {
            $f = $this->_frames[$i];
            unset($this->_frames[$i]);
            $this->w -= $f->get_margin_width();
        }

        // Reset array indices
        $this->_frames = array_values($this->_frames);

        // Recalculate the height of the line
        $this->inline = false;
        $this->_fitted = [];

        foreach ($this->_frames as $f) {
            if ($f->get_positioner() instanceof InlinePositioner) {
                $this->inline = true;
            }

            $this->_fitted[] = [$f, $f->get_margin_height()];
        }

        $this->recalculate_height();
    }

    /**
     * Make the line box high enough for a frame.
     *
     * The line box grows around its baseline to fit the extents of the frame
     * as aligned by its `vertical-align`, or to the height of the frame when
     * it is aligned to the top or bottom of the line. Like browsers do for
     * legacy documents, a line without text, made of images or inline blocks
     * and white space, is only as high as its boxes.
     *
     * https://www.w3.org/TR/CSS21/visudet.html#line-height
     * https://quirks.spec.whatwg.org/#the-line-height-calculation-quirk
     *
     * @param Frame $frame
     * @param float $height The margin height of the frame
     */
    public function fit(Frame $frame, float $height): void
    {
        $this->_fitted[] = [$frame, $height];
        $this->recalculate_height();
    }

    /**
     * Recalculate the height of the line box from the frames it has been
     * made high enough for, see `fit()`.
     */
    protected function recalculate_height(): void
    {
        $text = false;
        $atomic = false;

        foreach ($this->_fitted as [$frame]) {
            if ($this->is_atomic($frame)) {
                $atomic = true;
            } elseif ($this->has_text($frame)) {
                $text = true;
            }
        }

        $this->h = 0.0;
        $this->ascent = 0.0;
        $this->descent = 0.0;

        foreach ($this->_fitted as [$frame, $height]) {
            // White space and line breaks do not count in a line without text
            if ($atomic && !$text && !$this->is_atomic($frame)) {
                continue;
            }

            $align = $this->get_alignment($frame);

            if ($align === "top" || $align === "bottom") {
                $this->h = max($this->h, $height);
            } else {
                [$ascent, $descent] = $this->get_extents($frame, $height);
                $this->ascent = max($this->ascent, $ascent);
                $this->descent = max($this->descent, $descent);
            }
        }

        $this->h = max($this->h, $this->ascent + $this->descent);
    }

    /**
     * The height of a line of text of the block, which `text-top` and
     * `text-bottom` align to.
     *
     * @return float
     */
    public function get_strut(): float
    {
        $style = $this->_block_frame->get_style();
        $size = $style->font_size;
        $fontHeight = $this->_block_frame->get_dompdf()->getFontMetrics()->getFontHeight($style->font_family, $size);

        return ($style->line_height / ($size > 0 ? $size : 1)) * $fontHeight;
    }

    /**
     * The `vertical-align` a frame is aligned by: its own for atomic inline
     * boxes, the one of its parent for text and inline boxes. The content of
     * table cells is aligned to the baseline; the cell aligns as a whole.
     *
     * @param Frame $frame
     *
     * @return string A keyword or a length
     */
    public function get_alignment(Frame $frame): string
    {
        if ($this->is_atomic($frame)) {
            return $frame->get_style()->vertical_align;
        }

        $parent = $frame->get_parent();

        if ($parent === null || $parent instanceof TableCellFrameDecorator) {
            return "baseline";
        }

        return $parent->get_style()->vertical_align;
    }

    /**
     * The extents of a frame above and below the baseline of the line box,
     * once aligned by its `vertical-align`.
     *
     * Text and inline boxes have their ascenders above the baseline and their
     * descenders below it. Atomic inline boxes stand on the baseline.
     *
     * @param Frame $frame
     * @param float $height The margin height of the frame
     *
     * @return float[] The height above and the depth below the baseline
     */
    public function get_extents(Frame $frame, float $height): array
    {
        if ($this->is_atomic($frame)) {
            $ascent = $this->get_baseline($frame, $height);
            $descent = $height - $ascent;
        } else {
            $ascent = self::ASCENT_RATIO * $height;
            $descent = (1 - self::ASCENT_RATIO) * $height;
        }

        $shift = $this->get_shift($frame, $height);

        return [$ascent - $shift, $descent + $shift];
    }

    /**
     * The distance of the baseline of a frame from its top.
     *
     * Text has its baseline a font height below the top of its glyph box. An
     * atomic inline box has the baseline of the last line of text of an
     * inline block, or its bottom margin edge if it has no line of text, like
     * an image.
     *
     * https://www.w3.org/TR/CSS21/visudet.html#propdef-vertical-align
     *
     * @param Frame $frame
     * @param float $height The margin height of the frame
     *
     * @return float
     */
    public function get_baseline(Frame $frame, float $height): float
    {
        $style = $frame->get_style();

        if (!$this->is_atomic($frame)) {
            return $this->_block_frame->get_dompdf()->getFontMetrics()->getFontBaseline($style->font_family, $style->font_size);
        }

        if ($frame instanceof Block && $style->overflow === "visible") {
            foreach (array_reverse($frame->get_line_boxes()) as $line) {
                if ($line->inline && !$line->is_empty()) {
                    return $line->y + $line->ascent - $frame->get_position("y");
                }
            }
        }

        return $height;
    }

    /**
     * How far down the baseline of a frame is moved from the baseline of the
     * line box by its `vertical-align`.
     *
     * @param Frame $frame
     * @param float $height The margin height of the frame
     *
     * @return float
     */
    public function get_shift(Frame $frame, float $height): float
    {
        $style = $frame->get_style();
        $align = $this->get_alignment($frame);
        $atomic = $this->is_atomic($frame);
        $ascent = $atomic ? $height : self::ASCENT_RATIO * $height;
        $descent = $height - $ascent;
        $strut = $this->get_strut();
        $baseline = $this->_block_frame->get_dompdf()->getFontMetrics()->getFontBaseline($style->font_family, $style->font_size);

        switch ($align) {
            case "middle":
                // Centre the box on the middle of the lowercase letters, half
                // an x-height above the baseline, taken as a quarter of the
                // font size
                return $atomic ? $height / 2 - 0.25 * $style->font_size : 0.0;

            case "sub":
                return 0.5 * $baseline;

            case "super":
                return -0.4 * $baseline;

            case "text-top":
                return $ascent - self::ASCENT_RATIO * $strut;

            case "text-bottom":
                return (1 - self::ASCENT_RATIO) * $strut - $descent;

            case "baseline":
            case "top":
            case "bottom":
                return 0.0;

            default:
                return -(float) $style->length_in_pt($align, $style->font_size);
        }
    }

    /**
     * Whether a frame is text other than white space, or a list marker.
     *
     * @param Frame $frame
     *
     * @return bool
     */
    protected function has_text(Frame $frame): bool
    {
        if ($frame->is_text_node()) {
            return trim($frame->get_text()) !== "";
        }

        return $frame->get_style()->display === "-dompdf-list-bullet";
    }

    /**
     * Whether a frame is an atomic inline box, like an inline block or an
     * image, rather than text, an inline box, a line break or a list marker.
     *
     * @param Frame $frame
     *
     * @return bool
     */
    protected function is_atomic(Frame $frame): bool
    {
        $display = $frame->get_style()->display;

        return $display !== "inline" && $display !== "-dompdf-br" && $display !== "-dompdf-list-bullet";
    }

    /**
     * Get the `outside` positioned list markers to be vertically aligned with
     * the line box.
     *
     * @return ListBullet[]
     */
    public function get_list_markers(): array
    {
        return $this->list_markers;
    }

    /**
     * Add a list marker to the line box.
     *
     * The list marker is only added for the purpose of vertical alignment, it
     * is not actually added to the list of frames of the line box.
     */
    public function add_list_marker(ListBullet $marker): void
    {
        $this->list_markers[] = $marker;
    }

    /**
     * An iterator of all list markers and inline positioned frames of the line
     * box.
     *
     * @return Iterator<AbstractFrameDecorator>
     */
    public function frames_to_align(): Iterator
    {
        yield from $this->list_markers;

        foreach ($this->_frames as $frame) {
            if ($frame->get_positioner() instanceof InlinePositioner) {
                yield $frame;
            }
        }
    }

    /**
     * Trim trailing whitespace from the line.
     */
    public function trim_trailing_ws(): void
    {
        $lastIndex = count($this->_frames) - 1;

        if ($lastIndex < 0) {
            return;
        }

        $lastFrame = $this->_frames[$lastIndex];
        $reflower = $lastFrame->get_reflower();

        if ($reflower instanceof TextFrameReflower && !$lastFrame->is_pre()) {
            $reflower->trim_trailing_ws();
            $this->recalculate_width();
        }
    }

    /**
     * Recalculate LineBox width based on the contained frames total width.
     *
     * @return float
     */
    public function recalculate_width(): float
    {
        $width = 0.0;

        foreach ($this->_frames as $frame) {
            $width += $frame->get_margin_width();
        }

        return $this->w = $width;
    }

    public function __toString(): string
    {
        $props = ["wc", "y", "w", "h", "left", "right", "br"];
        $s = "";
        foreach ($props as $prop) {
            $s .= "$prop: " . $this->$prop . "\n";
        }
        $s .= count($this->_frames) . " frames\n";

        return $s;
    }
}
