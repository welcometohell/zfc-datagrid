<?php
/**
 * Output as a PDF file.
 */
namespace ZfcDatagrid\Renderer\TCPDF;

use TCPDF;
use Zend\Http\Headers;
use Zend\Http\Response\Stream as ResponseStream;
use ZfcDatagrid\Column\Style;
use ZfcDatagrid\Column\Type;
use ZfcDatagrid\Library\ImageResize;
use ZfcDatagrid\Renderer\AbstractExport;

class Renderer extends AbstractExport
{
    protected $allowedColumnTypes = [
        Type\DateTime::class,
        Type\Image::class,
        Type\Number::class,
        Type\PhpArray::class,
        Type\PhpString::class,
    ];

    /**
     * @var TCPDF
     */
    protected $pdf;

    /**
     * @var string
     */
    protected $alignment = 'L';

    private $columnsPositionX = [];

    public function getName()
    {
        return 'TCPDF';
    }

    public function isExport()
    {
        return true;
    }

    public function isHtml()
    {
        return false;
    }

    public function execute()
    {
        $pdf = $this->getPdf();
        $pdf->AddPage();

        $cols = $this->getColumnsToExport();
        $this->calculateColumnWidth($cols);

        /*
         * Display used filters etc...
         */
        // @todo

        $this->printGrid();

        return $this->saveAndSend();
    }

    protected function printGrid()
    {
        $pdf = $this->getPdf();

        /*
         * Print the header
         */
        $this->printTableHeader();

        /*
         * Write data
         */
        $pageHeight = $pdf->getPageHeight();
        $pageHeight -= 10;

        foreach ($this->getData() as $row) {
            $rowHeight = $this->getRowHeight($row);
            $y = $pdf->GetY();

            $usedHeight = $y + $rowHeight;

            if ($usedHeight > $pageHeight) {
                // Height is more than the pageHeight -> create a new page
                if ($rowHeight < $pageHeight) {
                    // If the row height > page height, than we would have a problem, if we add a new page
                    // because it will overflow anyway...
                    $pdf->AddPage();

                    $this->printTableHeader();
                }
            }

            $this->printTableRow($row, $rowHeight);
        }
    }

    protected function saveAndSend()
    {
        $pdf = $this->getPdf();

        $options = $this->getOptions();
        $optionsExport = $options['settings']['export'];

        $path = $optionsExport['path'];
        $saveFilename = date('Y-m-d_H-i-s').$this->getCacheId().'.pdf';
        $pdf->Output($path.'/'.$saveFilename, 'F');

        $response = new ResponseStream();
        $response->setStream(fopen($path.'/'.$saveFilename, 'r'));

        $headers = new Headers();
        $headers->addHeaders([
            'Content-Type' => [
                'application/force-download',
                'application/octet-stream',
                'application/download',
            ],
            'Content-Length' => filesize($path.'/'.$saveFilename),
            'Content-Disposition' => 'attachment;filename='.$this->getFilename().'.pdf',
            'Cache-Control' => 'must-revalidate',
            'Pragma' => 'no-cache',
            'Expires' => 'Thu, 1 Jan 1970 00:00:00 GMT',
        ]);

        $response->setHeaders($headers);

        return $response;
    }

    protected function initPdf()
    {
        $optionsRenderer = $this->getOptionsRenderer();

        $papersize = $optionsRenderer['papersize'];
        $orientation = $optionsRenderer['orientation'];
        if ('landscape' == $orientation) {
            $orientation = 'L';
        } else {
            $orientation = 'P';
        }

        $pdf = new TCPDF($orientation, 'mm', $papersize);

        $margins = $optionsRenderer['margins'];
        $pdf->SetMargins($margins['left'], $margins['top'], $margins['right']);
        $pdf->SetAutoPageBreak(true, $margins['bottom']);
        $pdf->setHeaderMargin($margins['header']);
        $pdf->setFooterMargin($margins['footer']);

        $header = $optionsRenderer['header'];
        $pdf->setHeaderFont([
            'Helvetica',
            '',
            13,
        ]);

        $pdf->setHeaderData($header['logo'], $header['logoWidth'], $this->getTitle());

        $this->pdf = $pdf;
    }

    /**
     * @return TCPDF
     */
    public function getPdf()
    {
        if (null === $this->pdf) {
            $this->initPdf();
        }

        return $this->pdf;
    }

    /**
     * Calculates the column width, based on the papersize and orientation.
     *
     * @param array $cols
     */
    protected function calculateColumnWidth(array $cols)
    {
        // First make sure the columns width is 100 "percent"
        $this->calculateColumnWidthPercent($cols);

        $pdf = $this->getPdf();
        $margins = $pdf->getMargins();

        $paperWidth = $this->getPaperWidth();
        $paperWidth -= ($margins['left'] + $margins['right']);

        $factor = $paperWidth / 100;
        foreach ($cols as $col) {
            /* @var $col \ZfcDatagrid\Column\AbstractColumn */
            $col->setWidth($col->getWidth() * $factor);
        }
    }

    /**
     * @param array $row
     *
     * @return number
     */
    protected function getRowHeight(array $row)
    {
        $optionsRenderer = $this->getOptionsRenderer();
        $sizePoint       = $optionsRenderer['style']['data']['size'];
        $padding   = $optionsRenderer['style']['data']['padding'];
        $contentPadding = $optionsRenderer['style']['data']['contentPadding'];

        // Points to MM
        $size = $sizePoint / 2.83464566929134;

        $pdf = $this->getPdf();

        $rowHeight = $size + $padding;
        foreach ($this->getColumnsToExport() as $col) {
            /* @var $col \ZfcDatagrid\Column\AbstractColumn */

            switch (get_class($col->getType())) {
                case Type\Image::class:
                    // "min" height for such a column
                    $height = $col->getType()->getResizeHeight() + $contentPadding;
                    break;

                default:
                    $value = $row[$col->getUniqueId()];
                    if (is_array($value)) {
                        $value = implode(PHP_EOL, $value);
                    }

                    foreach ($col->getStyles() as $style) {
                        if ($style instanceof Style\Html) {
                            $value = str_replace(['<br>', '<br />', '<br/>'], [PHP_EOL, PHP_EOL, PHP_EOL], $value);
                            $value = strip_tags($value);
                        }
                    }

                    $height = $pdf->getStringHeight($col->getWidth(), $value);

                    // include borders top/bottom
                    $height += $contentPadding;
                    break;
            }

            if ($height > $rowHeight) {
                $rowHeight = $height;
            }
        }

        return $rowHeight;
    }

    protected function printTableHeader()
    {
        $optionsRenderer = $this->getOptionsRenderer();
        $height = $optionsRenderer['style']['header']['height'];
        $this->setFontHeader();

        $pdf = $this->getPdf();
        $currentPage = $pdf->getPage();
        $y = $pdf->GetY();
        foreach ($this->getColumnsToExport() as $col) {
            /* @var $col \ZfcDatagrid\Column\AbstractColumn */
            $x = $pdf->GetX();
            $pdf->setPage($currentPage);

            $this->columnsPositionX[$col->getUniqueId()] = $x;

            $label = $this->translate($col->getLabel());

            // Do not wrap header labels, it will look very ugly, that's why max height is set to 7!
            // @codingStandardsIgnoreStart
            $pdf->MultiCell($col->getWidth(), $height, $label, 1, $this->getTextAlignment(), true, 2, $x, $y, true, 0, false, true, 7);
            // @codingStandardsIgnoreEnd
        }
    }

    protected function printTableRow(array $row, $rowHeight)
    {
        $pdf = $this->getPdf();

        $currentPage = $pdf->getPage();
        $y = $pdf->GetY();
        foreach ($this->getColumnsToExport() as $col) {
            /* @var $col \ZfcDatagrid\Column\AbstractColumn */

            $pdf->setPage($currentPage);
            $x = $this->columnsPositionX[$col->getUniqueId()];

            switch (get_class($col->getType())) {
                case 'ZfcDatagrid\Column\Type\Image':
                    $text = '';

                    $link = K_BLANK_IMAGE;
                    if ($row[$col->getUniqueId()] != '') {
                        $link = $row[$col->getUniqueId()];
                        if (is_array($link)) {
                            $link = array_shift($link);
                        }
                    }

                    try {
                        $resizeType = $col->getType()->getResizeType();
                        $resizeHeight = $col->getType()->getResizeHeight();
                        if ('dynamic' === $resizeType) {
                            // resizing properly to width + height (and keeping the ratio)
                            $file = file_get_contents($link);
                            if ($file !== false) {
                                list($width, $height) = $this->calcImageSize(
                                    $file,
                                    $col->getWidth() - 2,
                                    $rowHeight - 2
                                );

                                // @codingStandardsIgnoreStart
                                $pdf->Image('@' . $file, $x + 1, $y + 1, $width, $height, '', '', 'L', true);
                                // @codingStandardsIgnoreEnd
                            }
                        } else {
                            // @codingStandardsIgnoreStart
                            $pdf->Image($link, $x + 1, $y + 1, 0, $resizeHeight, '', '', 'L', true);
                            // @codingStandardsIgnoreEnd
                        }
                    } catch (\Exception $e) {
                        // if tcpdf couldnt find a image, continue and log it
                        trigger_error($e->getMessage());
                    }
                    break;

                default:
                    $text = $row[$col->getUniqueId()];
                    break;
            }

            if (is_array($text)) {
                $text = implode(PHP_EOL, $text);
            }

            /*
             * Styles
             */
            $this->setFontData();

            $isHtml = false;
            $backgroundColor = false;

            $styles = array_merge($this->getRowStyles(), $col->getStyles());
            foreach ($styles as $style) {
                /* @var $style Style\AbstractStyle */
                if ($style->isApply($row) === true) {
                    switch (get_class($style)) {
                        case Style\Bold::class:
                            $this->setBold();
                            break;

                        case Style\Italic::class:
                            $this->setItalic();
                            break;

                        case Style\Color::class:
                            $this->setColor($style->getRgbArray());
                            break;

                        case Style\BackgroundColor::class:
                            $this->setBackgroundColor($style->getRgbArray());
                            $backgroundColor = true;
                            break;

                        case Style\Strikethrough::class:
                            $text = '<del>'.$text.'</del>';
                            $isHtml = true;
                            break;

                        case Style\Html::class:
                            $isHtml = true;
                            break;

                        case Style\Align::class:
                            switch ($style->getAlignment()) {
                                case Style\Align::$RIGHT:
                                    $this->setTextAlignment('R');
                                    break;
                                case Style\Align::$LEFT:
                                    $this->setTextAlignment('L');
                                    break;
                                case Style\Align::$CENTER:
                                    $this->setTextAlignment('C');
                                    break;
                                case Style\Align::$JUSTIFY:
                                    $this->setTextAlignment('J');
                                    break;
                                default:
                                    //throw new \Exception('Not defined yet: "'.get_class($style->getAlignment()).'"');
                                    break;
                            }
                            break;

                        default:
                            throw new \Exception('Not defined yet: "'.get_class($style).'"');
                    }
                }
            }

            // @codingStandardsIgnoreStart
            // MultiCell($w, $h, $txt, $border=0, $align='J', $fill=false, $ln=1, $x='', $y='', $reseth=true, $stretch=0, $ishtml=false, $autopadding=true, $maxh=0, $valign='T', $fitcell=false)
            $pdf->MultiCell($col->getWidth(), $rowHeight, $text, 1, $this->getTextAlignment(), $backgroundColor, 1, $x, $y, true, 0, $isHtml);
            // @codingStandardsIgnoreEnd
        }
    }

    /**
     * @param string $imageData
     * @param number $maxWidth
     * @param number $maxHeight
     *
     * @return array
     */
    protected function calcImageSize($imageData, $maxWidth, $maxHeight)
    {
        $pdf = $this->getPdf();

        list($width, $height) = getimagesizefromstring($imageData);
        $width = $pdf->pixelsToUnits($width);
        $height = $pdf->pixelsToUnits($height);

        list($newWidth, $newHeight) = ImageResize::getCalculatedSize($width, $height, $maxWidth, $maxHeight);

        return [
            $newWidth,
            $newHeight,
        ];
    }

    protected function setFontHeader()
    {
        $optionsRenderer = $this->getOptionsRenderer();
        $style = $optionsRenderer['style']['header'];

        $font = $style['font'];
        $size = $style['size'];
        $color = $style['color'];
        $background = $style['background-color'];

        $pdf = $this->getPdf();
        $pdf->SetFont($font, '', $size);
        $pdf->SetTextColor($color[0], $color[1], $color[2]);
        $pdf->SetFillColor($background[0], $background[1], $background[2]);
        // "BOLD" fake
        $pdf->setTextRenderingMode(0.15, true, false);
    }

    protected function setFontData()
    {
        $optionsRenderer = $this->getOptionsRenderer();
        $style = $optionsRenderer['style']['data'];

        $font = $style['font'];
        $size = $style['size'];
        $color = $style['color'];
        $background = $style['background-color'];

        $pdf = $this->getPdf();
        $pdf->SetFont($font, '', $size);
        $pdf->SetTextColor($color[0], $color[1], $color[2]);
        $pdf->SetFillColor($background[0], $background[1], $background[2]);
        $pdf->setTextRenderingMode();
    }

    protected function setBold()
    {
        $pdf = $this->getPdf();
        $pdf->setTextRenderingMode(0.15, true, false);
    }

    protected function setItalic()
    {
        $optionsRenderer = $this->getOptionsRenderer();
        $style = $optionsRenderer['style']['data'];
        $font = $style['font'];
        $size = $style['size'];

        $pdf = $this->getPdf();
        $pdf->SetFont($font.'I', '', $size);
    }

    /**
     * @param array $rgb
     */
    protected function setColor(array $rgb)
    {
        $pdf = $this->getPdf();
        $pdf->SetTextColor($rgb['red'], $rgb['green'], $rgb['blue']);
    }

    /**
     * @param array $rgb
     */
    protected function setBackgroundColor(array $rgb)
    {
        $pdf = $this->getPdf();
        $pdf->SetFillColor($rgb['red'], $rgb['green'], $rgb['blue']);
    }

    /**
     * @param string $alignment
     */
    public function setTextAlignment($alignment)
    {
        $this->alignment = $alignment;
    }

    /**
     * @return string
     */
    public function getTextAlignment()
    {
        return $this->alignment;
    }
}
