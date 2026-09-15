<?xml version="1.0" encoding="UTF-8"?>
<!--
    Convert an hOCR (HTML produced by tesseract, ocropus, kraken, etc.) into
    a standard ALTO (v4) XML.

    Only basic features used by IIIF search and highlighting are emitted:
    Page, PrintSpace, TextBlock, TextLine, String. Confidence and other
    hOCR hints are ignored.

    The input is expected to have been normalized via PHP DOMDocument::loadHTML
    so that elements are not namespaced.

    @copyright Daniel Berthereau, 2026
    @license CeCILL 2.1 https://cecill.info/licences/Licence_CeCILL_V2.1-fr.txt

    @version 0.1.0
-->

<xsl:stylesheet version="1.0"
    xmlns:xsl="http://www.w3.org/1999/XSL/Transform"
    xmlns="http://www.loc.gov/standards/alto/ns-v4#">

    <xsl:output method="xml" indent="yes" encoding="UTF-8" omit-xml-declaration="no"/>

    <xsl:strip-space elements="*"/>

    <!-- ============================================================== -->
    <!-- Root -->
    <!-- ============================================================== -->

    <xsl:template match="/">
        <alto xmlns="http://www.loc.gov/standards/alto/ns-v4#">
            <Description>
                <MeasurementUnit>pixel</MeasurementUnit>
            </Description>
            <Layout>
                <xsl:apply-templates select="//*[contains(concat(' ', @class, ' '), ' ocr_page ')]"/>
            </Layout>
        </alto>
    </xsl:template>

    <!-- ============================================================== -->
    <!-- ocr_page -> Page -->
    <!-- ============================================================== -->

    <xsl:template match="*[contains(concat(' ', @class, ' '), ' ocr_page ')]">
        <xsl:variable name="bbox" select="substring-before(concat(substring-after(@title, 'bbox '), ';'), ';')"/>
        <xsl:variable name="x1" select="substring-before($bbox, ' ')"/>
        <xsl:variable name="r1" select="substring-after($bbox, ' ')"/>
        <xsl:variable name="y1" select="substring-before($r1, ' ')"/>
        <xsl:variable name="r2" select="substring-after($r1, ' ')"/>
        <xsl:variable name="x2" select="substring-before($r2, ' ')"/>
        <xsl:variable name="y2" select="substring-after($r2, ' ')"/>
        <xsl:variable name="w" select="number($x2) - number($x1)"/>
        <xsl:variable name="h" select="number($y2) - number($y1)"/>
        <xsl:variable name="imgSeg" select="normalize-space(substring-before(concat(substring-after(@title, 'image '), ';'), ';'))"/>
        <Page>
            <xsl:attribute name="ID"><xsl:value-of select="@id"/></xsl:attribute>
            <xsl:attribute name="PHYSICAL_IMG_NR"><xsl:value-of select="position()"/></xsl:attribute>
            <xsl:attribute name="WIDTH"><xsl:value-of select="$w"/></xsl:attribute>
            <xsl:attribute name="HEIGHT"><xsl:value-of select="$h"/></xsl:attribute>
            <xsl:if test="$imgSeg != ''">
                <xsl:attribute name="PAGECLASS"><xsl:value-of select="translate($imgSeg, '&quot;', '')"/></xsl:attribute>
            </xsl:if>
            <PrintSpace>
                <xsl:attribute name="HPOS"><xsl:value-of select="$x1"/></xsl:attribute>
                <xsl:attribute name="VPOS"><xsl:value-of select="$y1"/></xsl:attribute>
                <xsl:attribute name="WIDTH"><xsl:value-of select="$w"/></xsl:attribute>
                <xsl:attribute name="HEIGHT"><xsl:value-of select="$h"/></xsl:attribute>
                <xsl:apply-templates select=".//*[contains(concat(' ', @class, ' '), ' ocr_carea ')]"/>
                <!-- Some hocr lack ocr_carea wrapping; pick lines directly when no carea was matched. -->
                <xsl:if test="not(.//*[contains(concat(' ', @class, ' '), ' ocr_carea ')])">
                    <TextBlock>
                        <xsl:attribute name="ID"><xsl:value-of select="concat(@id, '_block')"/></xsl:attribute>
                        <xsl:attribute name="HPOS"><xsl:value-of select="$x1"/></xsl:attribute>
                        <xsl:attribute name="VPOS"><xsl:value-of select="$y1"/></xsl:attribute>
                        <xsl:attribute name="WIDTH"><xsl:value-of select="$w"/></xsl:attribute>
                        <xsl:attribute name="HEIGHT"><xsl:value-of select="$h"/></xsl:attribute>
                        <xsl:apply-templates select=".//*[contains(concat(' ', @class, ' '), ' ocr_line ')]"/>
                    </TextBlock>
                </xsl:if>
            </PrintSpace>
        </Page>
    </xsl:template>

    <!-- ============================================================== -->
    <!-- ocr_carea -> TextBlock -->
    <!-- ============================================================== -->

    <xsl:template match="*[contains(concat(' ', @class, ' '), ' ocr_carea ')]">
        <TextBlock>
            <xsl:attribute name="ID"><xsl:value-of select="@id"/></xsl:attribute>
            <xsl:call-template name="emit-bbox">
                <xsl:with-param name="title" select="@title"/>
            </xsl:call-template>
            <xsl:apply-templates select=".//*[contains(concat(' ', @class, ' '), ' ocr_line ')]"/>
        </TextBlock>
    </xsl:template>

    <!-- ============================================================== -->
    <!-- ocr_line -> TextLine -->
    <!-- ============================================================== -->

    <xsl:template match="*[contains(concat(' ', @class, ' '), ' ocr_line ')]">
        <TextLine>
            <xsl:attribute name="ID"><xsl:value-of select="@id"/></xsl:attribute>
            <xsl:call-template name="emit-bbox">
                <xsl:with-param name="title" select="@title"/>
            </xsl:call-template>
            <xsl:apply-templates select=".//*[contains(concat(' ', @class, ' '), ' ocrx_word ')]"/>
        </TextLine>
    </xsl:template>

    <!-- ============================================================== -->
    <!-- ocrx_word -> String -->
    <!-- ============================================================== -->

    <xsl:template match="*[contains(concat(' ', @class, ' '), ' ocrx_word ')]">
        <String>
            <xsl:attribute name="ID"><xsl:value-of select="@id"/></xsl:attribute>
            <xsl:call-template name="emit-bbox">
                <xsl:with-param name="title" select="@title"/>
            </xsl:call-template>
            <xsl:attribute name="CONTENT"><xsl:value-of select="normalize-space(.)"/></xsl:attribute>
        </String>
    </xsl:template>

    <!-- ============================================================== -->
    <!-- Helper: parse bbox from title and emit HPOS/VPOS/WIDTH/HEIGHT. -->
    <!-- ============================================================== -->

    <xsl:template name="emit-bbox">
        <xsl:param name="title"/>
        <xsl:variable name="seg" select="substring-before(concat(substring-after($title, 'bbox '), ';'), ';')"/>
        <xsl:variable name="x1" select="substring-before($seg, ' ')"/>
        <xsl:variable name="r1" select="substring-after($seg, ' ')"/>
        <xsl:variable name="y1" select="substring-before($r1, ' ')"/>
        <xsl:variable name="r2" select="substring-after($r1, ' ')"/>
        <xsl:variable name="x2" select="substring-before($r2, ' ')"/>
        <xsl:variable name="y2" select="substring-after($r2, ' ')"/>
        <xsl:attribute name="HPOS"><xsl:value-of select="$x1"/></xsl:attribute>
        <xsl:attribute name="VPOS"><xsl:value-of select="$y1"/></xsl:attribute>
        <xsl:attribute name="WIDTH"><xsl:value-of select="number($x2) - number($x1)"/></xsl:attribute>
        <xsl:attribute name="HEIGHT"><xsl:value-of select="number($y2) - number($y1)"/></xsl:attribute>
    </xsl:template>

</xsl:stylesheet>
