<?php
/**
 * @package dompdf
 * @link    https://github.com/dompdf/dompdf
 * @license http://www.gnu.org/copyleft/lesser.html GNU Lesser General Public License
 */
namespace Dompdf\FrameReflower;

use Dompdf\Exception;
use Dompdf\FrameDecorator\Block as BlockFrameDecorator;
use Dompdf\FrameDecorator\Table as TableFrameDecorator;
use Dompdf\FrameDecorator\TableCell as TableCellFrameDecorator;
use Dompdf\Helpers;

/**
 * Reflows table cells
 *
 * @package dompdf
 */
class TableCell extends Block
{
    /**
     * TableCell constructor.
     * @param BlockFrameDecorator $frame
     */
    function __construct(BlockFrameDecorator $frame)
    {
        parent::__construct($frame);
    }

    /**
     * @param BlockFrameDecorator|null $block
     */
    function reflow(?BlockFrameDecorator $block = null)
    {
        if ($this->is_orthogonal()) {
            $this->reflow_orthogonal($block);
            return;
        }

        /** @var TableCellFrameDecorator */
        $frame = $this->_frame;
        $table = TableFrameDecorator::find_parent_table($frame);
        if ($table === null) {
            throw new Exception("Parent table not found for table cell");
        }

        // Counters and generated content
        $this->_set_content();

        $style = $frame->get_style();
        $cellmap = $table->get_cellmap();

        [$x, $y] = $cellmap->get_frame_position($frame);
        $frame->set_position($x, $y);

        $cells = $cellmap->get_spanned_cells($frame);

        $w = 0;
        foreach ($cells["columns"] as $i) {
            $col = $cellmap->get_column($i);
            $w += $col["used-width"];
        }

        $h = $frame->get_containing_block("h");

        $left_space = (float)$style->length_in_pt([$style->margin_left,
                $style->padding_left,
                $style->border_left_width],
            $w);

        $right_space = (float)$style->length_in_pt([$style->padding_right,
                $style->margin_right,
                $style->border_right_width],
            $w);

        $top_space = (float)$style->length_in_pt([$style->margin_top,
                $style->padding_top,
                $style->border_top_width],
            $w);
        $bottom_space = (float)$style->length_in_pt([$style->margin_bottom,
                $style->padding_bottom,
                $style->border_bottom_width],
            $w);

        $cb_w = $w - $left_space - $right_space;
        $style->set_used("width", $cb_w);

        $content_x = $x + $left_space;
        $content_y = $line_y = $y + $top_space;

        // Adjust the first line based on the text-indent property
        $indent = (float)$style->length_in_pt($style->text_indent, $w);
        $frame->increase_line_width($indent);

        $page = $frame->get_root();

        // Set the y position of the first line in the cell
        $line_box = $frame->get_current_line_box();
        $line_box->y = $line_y;

        // Set the containing blocks and reflow each child
        foreach ($frame->get_children() as $child) {
            $child->set_containing_block($content_x, $content_y, $cb_w, $h);
            $this->process_clear($child);
            $child->reflow($frame);
            $this->process_float($child, $content_x, $cb_w);

            if ($page->is_full()) {
                break;
            }
        }

        // Determine our height. The percentage heights of the cells of a
        // table with a definite height are resolved by distributing the
        // height of the table over its rows
        if (Helpers::is_percent($style->height) && $table->get_style()->height !== "auto") {
            $style_height = 0.0;
        } else {
            $style_height = $this->resolve_height($h);
        }
        $content_height = $this->_calculate_content_height();

        if ($style_height === "auto") {
            $style_height = 0.0;
        }

        $height = max($style_height, $content_height);

        $frame->set_content_height($content_height);

        // Let the cellmap know our height
        $cellmap->set_frame_height($frame, $height + $top_space + $bottom_space);

        $style->set_used("height", $height);

        $this->_text_align();
        $this->vertical_align();

        // Handle relative positioning
        foreach ($frame->get_children() as $child) {
            $this->position_relative($child);
        }
    }

    /**
     * Lay out a cell whose writing mode is orthogonal to the one of the table.
     *
     * The content is laid out horizontally, with the inline size of the cell,
     * its height, as width, and rotated into place by the renderer. The width
     * of the cell, given by its columns, is the block size of the content.
     *
     * https://www.w3.org/TR/css-writing-modes-4/#orthogonal-flows
     *
     * @param BlockFrameDecorator|null $block
     */
    protected function reflow_orthogonal(?BlockFrameDecorator $block): void
    {
        /** @var TableCellFrameDecorator */
        $frame = $this->_frame;
        $table = TableFrameDecorator::find_parent_table($frame);
        if ($table === null) {
            throw new Exception("Parent table not found for table cell");
        }

        // Counters and generated content
        $this->_set_content();

        $style = $frame->get_style();
        $cellmap = $table->get_cellmap();

        [$x, $y] = $cellmap->get_frame_position($frame);
        $frame->set_position($x, $y);

        $cells = $cellmap->get_spanned_cells($frame);

        $w = 0;
        foreach ($cells["columns"] as $i) {
            $col = $cellmap->get_column($i);
            $w += $col["used-width"];
        }

        $h = $frame->get_containing_block("h");

        $left_space = (float)$style->length_in_pt([$style->margin_left,
                $style->padding_left,
                $style->border_left_width],
            $w);

        $right_space = (float)$style->length_in_pt([$style->padding_right,
                $style->margin_right,
                $style->border_right_width],
            $w);

        $top_space = (float)$style->length_in_pt([$style->margin_top,
                $style->padding_top,
                $style->border_top_width],
            $w);
        $bottom_space = (float)$style->length_in_pt([$style->margin_bottom,
                $style->padding_bottom,
                $style->border_bottom_width],
            $w);

        // The block size is the width of the cell. The inline size is its
        // height: the specified one, or the fit-content size within the height
        // of the containing block, or of the page if it is undefined
        $block_size = $w - $left_space - $right_space;
        $inline_size = $style->length_in_pt($style->height, $h);

        if ($inline_size === "auto") {
            [$min, $max] = $this->get_min_max_child_width();
            $inline_size = max($min, min($max, (float) $h));
        }

        $inline_size = (float) $inline_size;

        // Lay the content out horizontally, with the inline size as width
        $style->set_used("width", $inline_size);

        $content_x = $x + $left_space;
        $content_y = $line_y = $y + $top_space;

        $page = $frame->get_root();

        $line_box = $frame->get_current_line_box();
        $line_box->y = $line_y;

        foreach ($frame->get_children() as $child) {
            $child->set_containing_block($content_x, $content_y, $inline_size, $block_size);
            $this->process_clear($child);
            $child->reflow($frame);
            $this->process_float($child, $content_x, $inline_size);

            if ($page->is_full()) {
                break;
            }
        }

        $this->_text_align();
        $this->vertical_align();

        // Handle relative positioning
        foreach ($frame->get_children() as $child) {
            $this->position_relative($child);
        }

        if ($style->writing_mode === "vertical-lr") {
            $this->_mirror_lines($frame, $content_y, $block_size);
        }

        // The physical box: the width of the cell, and the inline size as
        // height. Its content is aligned along the inline axis by text-align,
        // so the vertical alignment of the cell does not apply
        $style->set_used("width", $block_size);
        $style->set_used("height", $inline_size);
        $style->set_used("vertical_align", "top");
        $frame->set_content_height($inline_size);

        // Let the cellmap know our height
        $cell_height = ($inline_size + $top_space + $bottom_space) / count($cells["rows"]);

        foreach ($cells["rows"] as $i) {
            $cellmap->set_row_height($i, $cell_height);
        }
    }

    public function get_min_max_content_width(): array
    {
        if ($this->is_orthogonal()) {
            return $this->get_orthogonal_min_max_content_width();
        }

        // Ignore percentage values for a specified width here, as they are
        // relative to the table width, which is not determined yet
        $style = $this->_frame->get_style();
        $width = $style->width;
        $fixed_width = $width !== "auto" && !Helpers::is_percent($width);

        [$min, $max] = $this->get_min_max_child_width();

        // For table cells: Use specified width if it is greater than the
        // minimum defined by the content
        if ($fixed_width) {
            $width = (float) $style->length_in_pt($width, 0);
            $min = max($width, $min);
            $max = $min;
        }

        // Handle min/max width style properties
        $min_width = $this->resolve_min_width(null);
        $max_width = $this->resolve_max_width(null);
        $min = Helpers::clamp($min, $min_width, $max_width);
        $max = Helpers::clamp($max, $min_width, $max_width);

        return [$min, $max];
    }
}
