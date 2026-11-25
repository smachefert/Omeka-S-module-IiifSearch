<?xml version="1.0" encoding="UTF-8"?>
<!--
    Convert an ALTO (v3 or v4) document into a minimal hOCR HTML document.

    Maps ALTO/Layout/Page → div.ocr_page,
         TextBlock         → div.ocr_carea,
         TextLine          → span.ocr_line,
         String            → span.ocrx_word.

    Coordinates are kept in pixels; the hOCR bbox uses absolute corners
    (x1 y1 x2 y2). Confidence, font metadata and other ALTO hints are not
    forwarded — only what IIIF viewers consume for highlighting.

    @copyright Daniel Berthereau, 2026
    @license CeCILL 2.1 https://cecill.info/licences/Licence_CeCILL_V2.1-fr.txt

    @version 0.1.0
-->

<xsl:stylesheet version="1.0"
    xmlns:xsl="http://www.w3.org/1999/XSL/Transform"
    xmlns:a3="http://www.loc.gov/standards/alto/ns-v3#"
    xmlns:a4="http://www.loc.gov/standards/alto/ns-v4#"
    exclude-result-prefixes="a3 a4">

    <xsl:output method="html" indent="yes" encoding="UTF-8"
        doctype-system="about:legacy-compat"/>

    <xsl:strip-space elements="*"/>

    <!-- ============================================================== -->
    <!-- Root -->
    <!-- ============================================================== -->

    <xsl:template match="/">
        <html lang="en">
            <head>
                <title>OCR Output</title>
                <meta charset="UTF-8"/>
                <meta name="ocr-system" content="IiifSearch alto_to_hocr"/>
                <meta name="ocr-capabilities"
                    content="ocr_page ocr_carea ocr_line ocrx_word"/>
            </head>
            <body>
                <xsl:apply-templates select="//*[local-name() = 'Page']"/>
            </body>
        </html>
    </xsl:template>

    <!-- ============================================================== -->
    <!-- Page -->
    <!-- ============================================================== -->

    <xsl:template match="*[local-name() = 'Page']">
        <xsl:variable name="pnum">
            <xsl:choose>
                <xsl:when test="@PHYSICAL_IMG_NR">
                    <xsl:value-of select="@PHYSICAL_IMG_NR"/>
                </xsl:when>
                <xsl:otherwise>
                    <xsl:value-of select="position()"/>
                </xsl:otherwise>
            </xsl:choose>
        </xsl:variable>
        <div class="ocr_page" id="page_{$pnum}">
            <xsl:attribute name="title">
                <xsl:text>bbox 0 0 </xsl:text>
                <xsl:value-of select="@WIDTH"/>
                <xsl:text> </xsl:text>
                <xsl:value-of select="@HEIGHT"/>
            </xsl:attribute>
            <xsl:apply-templates select=".//*[local-name() = 'TextBlock']">
                <xsl:with-param name="pnum" select="$pnum"/>
            </xsl:apply-templates>
        </div>
    </xsl:template>

    <!-- ============================================================== -->
    <!-- TextBlock -->
    <!-- ============================================================== -->

    <xsl:template match="*[local-name() = 'TextBlock']">
        <xsl:param name="pnum"/>
        <xsl:variable name="x1" select="@HPOS"/>
        <xsl:variable name="y1" select="@VPOS"/>
        <xsl:variable name="x2" select="@HPOS + @WIDTH"/>
        <xsl:variable name="y2" select="@VPOS + @HEIGHT"/>
        <div class="ocr_carea" id="block_{$pnum}_{position()}">
            <xsl:attribute name="title">
                <xsl:text>bbox </xsl:text>
                <xsl:value-of select="$x1"/>
                <xsl:text> </xsl:text>
                <xsl:value-of select="$y1"/>
                <xsl:text> </xsl:text>
                <xsl:value-of select="$x2"/>
                <xsl:text> </xsl:text>
                <xsl:value-of select="$y2"/>
            </xsl:attribute>
            <xsl:apply-templates select=".//*[local-name() = 'TextLine']">
                <xsl:with-param name="pnum" select="$pnum"/>
                <xsl:with-param name="bnum" select="position()"/>
            </xsl:apply-templates>
        </div>
    </xsl:template>

    <!-- ============================================================== -->
    <!-- TextLine -->
    <!-- ============================================================== -->

    <xsl:template match="*[local-name() = 'TextLine']">
        <xsl:param name="pnum"/>
        <xsl:param name="bnum"/>
        <xsl:variable name="x1" select="@HPOS"/>
        <xsl:variable name="y1" select="@VPOS"/>
        <xsl:variable name="x2" select="@HPOS + @WIDTH"/>
        <xsl:variable name="y2" select="@VPOS + @HEIGHT"/>
        <span class="ocr_line" id="line_{$pnum}_{$bnum}_{position()}">
            <xsl:attribute name="title">
                <xsl:text>bbox </xsl:text>
                <xsl:value-of select="$x1"/>
                <xsl:text> </xsl:text>
                <xsl:value-of select="$y1"/>
                <xsl:text> </xsl:text>
                <xsl:value-of select="$x2"/>
                <xsl:text> </xsl:text>
                <xsl:value-of select="$y2"/>
            </xsl:attribute>
            <xsl:apply-templates select=".//*[local-name() = 'String']">
                <xsl:with-param name="pnum" select="$pnum"/>
                <xsl:with-param name="bnum" select="$bnum"/>
                <xsl:with-param name="lnum" select="position()"/>
            </xsl:apply-templates>
        </span>
    </xsl:template>

    <!-- ============================================================== -->
    <!-- String -->
    <!-- ============================================================== -->

    <xsl:template match="*[local-name() = 'String']">
        <xsl:param name="pnum"/>
        <xsl:param name="bnum"/>
        <xsl:param name="lnum"/>
        <xsl:variable name="x1" select="@HPOS"/>
        <xsl:variable name="y1" select="@VPOS"/>
        <xsl:variable name="x2" select="@HPOS + @WIDTH"/>
        <xsl:variable name="y2" select="@VPOS + @HEIGHT"/>
        <span class="ocrx_word" id="word_{$pnum}_{$bnum}_{$lnum}_{position()}">
            <xsl:attribute name="title">
                <xsl:text>bbox </xsl:text>
                <xsl:value-of select="$x1"/>
                <xsl:text> </xsl:text>
                <xsl:value-of select="$y1"/>
                <xsl:text> </xsl:text>
                <xsl:value-of select="$x2"/>
                <xsl:text> </xsl:text>
                <xsl:value-of select="$y2"/>
            </xsl:attribute>
            <xsl:value-of select="@CONTENT"/>
        </span>
        <xsl:if test="position() != last()">
            <xsl:text> </xsl:text>
        </xsl:if>
    </xsl:template>

</xsl:stylesheet>
