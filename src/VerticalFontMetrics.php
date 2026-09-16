<?php
/**
 * @package dompdf
 * @link    https://github.com/dompdf/dompdf
 * @license http://www.gnu.org/copyleft/lesser.html GNU Lesser General Public License
 */
namespace Dompdf;

/**
 * Font metrics of text set in a vertical writing mode with upright glyphs.
 *
 * Upright glyphs advance along the line by the height of the font, their
 * vertical advance, instead of by their width.
 *
 * https://www.w3.org/TR/css-writing-modes-4/#text-orientation
 *
 * @package dompdf
 */
class VerticalFontMetrics extends FontMetrics
{
    /**
     * Ranges of code points typeset upright in `text-orientation: mixed`: the
     * characters with a Vertical_Orientation of U or Tu in Unicode Standard
     * Annex #50, as first-last pairs, generated from VerticalOrientation.txt of
     * Unicode 17.0.0.
     *
     * https://www.unicode.org/reports/tr50/
     *
     * @var int[][]
     */
    protected const UPRIGHT_RANGES = [
        [0x00A7, 0x00A7], [0x00A9, 0x00A9], [0x00AE, 0x00AE], [0x00B1, 0x00B1],
        [0x00BC, 0x00BE], [0x00D7, 0x00D7], [0x00F7, 0x00F7], [0x02EA, 0x02EB],
        [0x1100, 0x11FF], [0x1401, 0x167F], [0x18B0, 0x18FF], [0x2016, 0x2016],
        [0x2020, 0x2021], [0x2030, 0x2031], [0x203B, 0x203C], [0x2042, 0x2042],
        [0x2047, 0x2049], [0x2051, 0x2051], [0x2065, 0x2065], [0x20DD, 0x20E0],
        [0x20E2, 0x20E4], [0x2100, 0x2101], [0x2103, 0x2109], [0x210F, 0x210F],
        [0x2113, 0x2114], [0x2116, 0x2117], [0x211E, 0x2123], [0x2125, 0x2125],
        [0x2127, 0x2127], [0x2129, 0x2129], [0x212E, 0x212E], [0x2135, 0x213F],
        [0x2145, 0x214A], [0x214C, 0x214D], [0x214F, 0x2189], [0x218C, 0x218F],
        [0x221E, 0x221E], [0x2234, 0x2235], [0x2300, 0x2307], [0x230C, 0x231F],
        [0x2324, 0x2328], [0x232B, 0x232B], [0x237D, 0x239A], [0x23BE, 0x23CD],
        [0x23CF, 0x23CF], [0x23D1, 0x23DB], [0x23E2, 0x2422], [0x2424, 0x24FF],
        [0x25A0, 0x2619], [0x2620, 0x2767], [0x2776, 0x2793], [0x2B12, 0x2B2F],
        [0x2B50, 0x2B59], [0x2B97, 0x2B97], [0x2BB8, 0x2BD1], [0x2BD3, 0x2BEB],
        [0x2BF0, 0x2BFF], [0x2E50, 0x2E51], [0x2E80, 0x3007], [0x3012, 0x3013],
        [0x3020, 0x302F], [0x3031, 0x309F], [0x30A1, 0x30FB], [0x30FD, 0xA4CF],
        [0xA960, 0xA97F], [0xAC00, 0xD7FF], [0xE000, 0xFAFF], [0xFE10, 0xFE1F],
        [0xFE30, 0xFE48], [0xFE50, 0xFE57], [0xFE5F, 0xFE62], [0xFE67, 0xFE6F],
        [0xFF01, 0xFF07], [0xFF0A, 0xFF0C], [0xFF0E, 0xFF19], [0xFF1F, 0xFF3A],
        [0xFF3C, 0xFF3C], [0xFF3E, 0xFF3E], [0xFF40, 0xFF5A], [0xFFE0, 0xFFE2],
        [0xFFE4, 0xFFE7], [0xFFF0, 0xFFF8], [0xFFFC, 0xFFFD], [0x10980, 0x1099F],
        [0x11580, 0x115FF], [0x11A00, 0x11ABF], [0x13000, 0x1467F], [0x16FE0, 0x18DFF],
        [0x1AFF0, 0x1B2FF], [0x1CEC0, 0x1CFCF], [0x1D000, 0x1D1FF], [0x1D2E0, 0x1D37F],
        [0x1D800, 0x1DAAF], [0x1F000, 0x1F7FF], [0x1F900, 0x1FAFF], [0x20000, 0x2FFFD],
        [0x30000, 0x3FFFD], [0xF0000, 0xFFFFD], [0x100000, 0x10FFFD],
    ];

    /**
     * @var FontMetrics
     */
    protected $fontMetrics;

    /**
     * @var string
     */
    protected $orientation;

    /**
     * @param FontMetrics $fontMetrics The metrics of the horizontal text
     * @param string      $orientation `upright` or `mixed`
     */
    public function __construct(FontMetrics $fontMetrics, string $orientation)
    {
        parent::__construct($fontMetrics->getCanvas(), $fontMetrics->getOptions());

        $this->fontMetrics = $fontMetrics;
        $this->orientation = $orientation;
    }

    /**
     * Whether a character is typeset upright in the orientation of these
     * metrics.
     *
     * @param string $char
     * @return bool
     */
    public function isUpright(string $char): bool
    {
        if ($this->orientation === "upright") {
            return true;
        }

        $codePoint = mb_ord($char, "UTF-8");

        foreach (self::UPRIGHT_RANGES as [$first, $last]) {
            if ($codePoint < $first) {
                return false;
            }

            if ($codePoint <= $last) {
                return true;
            }
        }

        return false;
    }

    /**
     * Split a text into runs of characters sharing the same orientation.
     *
     * @param string $text
     * @return array<array{0: string, 1: bool}> The runs, as text and whether it is upright
     */
    public function getRuns(string $text): array
    {
        $runs = [];

        foreach (mb_str_split($text, 1, "UTF-8") as $char) {
            $upright = $this->isUpright($char);

            if ($runs !== [] && $runs[count($runs) - 1][1] === $upright) {
                $runs[count($runs) - 1][0] .= $char;
            } else {
                $runs[] = [$char, $upright];
            }
        }

        return $runs;
    }

    /**
     * The advance of an upright glyph along the line: the height of the font,
     * plus the letter spacing.
     *
     * @param string $font
     * @param float  $size
     * @param float  $charSpacing
     * @return float
     */
    public function getUprightAdvance($font, float $size, float $charSpacing = 0.0): float
    {
        return $this->getFontHeight($font, $size) + $charSpacing;
    }

    public function getTextWidth(string $text, $font, float $size, float $wordSpacing = 0.0, float $charSpacing = 0.0): float
    {
        $width = 0.0;

        foreach ($this->getRuns($text) as [$run, $upright]) {
            $width += $upright
                ? mb_strlen($run, "UTF-8") * $this->getUprightAdvance($font, $size, $charSpacing)
                : $this->fontMetrics->getTextWidth($run, $font, $size, $wordSpacing, $charSpacing);
        }

        return $width;
    }
}
