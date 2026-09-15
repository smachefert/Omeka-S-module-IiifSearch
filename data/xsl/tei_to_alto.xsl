<?xml version="1.0" encoding="UTF-8"?>
<!--
    Convert a TEI facsimile (Transkribus / eScriptorium / e-editiones flavour)
    into a standard ALTO (v4) XML file.

    Supported source structure:
    - One <facsimile> with N <surface> elements (multipage).
    - Each <surface> declares bbox via @ulx @uly @lrx @lry and optionally
      @source pointing at the image filename.
    - Nested <zone> elements inside <surface> carry @ulx @uly @lrx @lry
      and optional inner text (<line>, <seg>, raw text).
    - When zones are missing, the surface itself becomes a single TextBlock
      and any descendant text is emitted as a String.

    Not supported (yet):
    - @points polygons (only bbox attributes are read).
    - References from <text><body> to facsimile zones via @facs (text in
      <text> is ignored; only text colocated with zones is exported).

    @copyright Daniel Berthereau, 2026
    @license CeCILL 2.1 https://cecill.info/licences/Licence_CeCILL_V2.1-fr.txt

    @version 0.1.0
-->

<xsl:stylesheet version="1.0"
    xmlns:xsl="http://www.w3.org/1999/XSL/Transform"
    xmlns="http://www.loc.gov/standards/alto/ns-v4#"
    xmlns:tei="http://www.tei-c.org/ns/1.0"
    exclude-result-prefixes="tei">

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
                <xsl:apply-templates select="//tei:facsimile/tei:surface | //*[local-name()='facsimile']/*[local-name()='surface']"/>
            </Layout>
        </alto>
    </xsl:template>

    <!-- ============================================================== -->
    <!-- surface -> Page -->
    <!-- ============================================================== -->

    <xsl:template match="tei:surface | *[local-name()='surface']">
        <xsl:variable name="ulx" select="number(@ulx)"/>
        <xsl:variable name="uly" select="number(@uly)"/>
        <xsl:variable name="lrx" select="number(@lrx)"/>
        <xsl:variable name="lry" select="number(@lry)"/>
        <xsl:variable name="w" select="$lrx - $ulx"/>
        <xsl:variable name="h" select="$lry - $uly"/>
        <Page>
            <xsl:attribute name="ID"><xsl:value-of select="@xml:id | @id"/></xsl:attribute>
            <xsl:attribute name="PHYSICAL_IMG_NR"><xsl:value-of select="position()"/></xsl:attribute>
            <xsl:attribute name="WIDTH"><xsl:value-of select="$lrx"/></xsl:attribute>
            <xsl:attribute name="HEIGHT"><xsl:value-of select="$lry"/></xsl:attribute>
            <PrintSpace>
                <xsl:attribute name="HPOS"><xsl:value-of select="$ulx"/></xsl:attribute>
                <xsl:attribute name="VPOS"><xsl:value-of select="$uly"/></xsl:attribute>
                <xsl:attribute name="WIDTH"><xsl:value-of select="$w"/></xsl:attribute>
                <xsl:attribute name="HEIGHT"><xsl:value-of select="$h"/></xsl:attribute>
                <xsl:choose>
                    <xsl:when test="tei:zone | *[local-name()='zone']">
                        <xsl:apply-templates select="tei:zone | *[local-name()='zone']" mode="block"/>
                    </xsl:when>
                    <xsl:otherwise>
                        <TextBlock>
                            <xsl:attribute name="ID"><xsl:value-of select="concat(@xml:id, '_block')"/></xsl:attribute>
                            <xsl:attribute name="HPOS"><xsl:value-of select="$ulx"/></xsl:attribute>
                            <xsl:attribute name="VPOS"><xsl:value-of select="$uly"/></xsl:attribute>
                            <xsl:attribute name="WIDTH"><xsl:value-of select="$w"/></xsl:attribute>
                            <xsl:attribute name="HEIGHT"><xsl:value-of select="$h"/></xsl:attribute>
                        </TextBlock>
                    </xsl:otherwise>
                </xsl:choose>
            </PrintSpace>
        </Page>
    </xsl:template>

    <!-- ============================================================== -->
    <!-- Outer zone -> TextBlock (TextRegion-style) -->
    <!-- ============================================================== -->

    <xsl:template match="tei:zone | *[local-name()='zone']" mode="block">
        <xsl:variable name="ulx" select="number(@ulx)"/>
        <xsl:variable name="uly" select="number(@uly)"/>
        <xsl:variable name="lrx" select="number(@lrx)"/>
        <xsl:variable name="lry" select="number(@lry)"/>
        <TextBlock>
            <xsl:attribute name="ID"><xsl:value-of select="@xml:id | @id"/></xsl:attribute>
            <xsl:call-template name="emit-bbox-attrs">
                <xsl:with-param name="ulx" select="$ulx"/>
                <xsl:with-param name="uly" select="$uly"/>
                <xsl:with-param name="lrx" select="$lrx"/>
                <xsl:with-param name="lry" select="$lry"/>
            </xsl:call-template>
            <xsl:choose>
                <xsl:when test="tei:zone | *[local-name()='zone']">
                    <xsl:apply-templates select="tei:zone | *[local-name()='zone']" mode="line"/>
                </xsl:when>
                <xsl:otherwise>
                    <!-- Bare zone with direct text content. -->
                    <TextLine>
                        <xsl:attribute name="ID"><xsl:value-of select="concat(@xml:id, '_line')"/></xsl:attribute>
                        <xsl:call-template name="emit-bbox-attrs">
                            <xsl:with-param name="ulx" select="$ulx"/>
                            <xsl:with-param name="uly" select="$uly"/>
                            <xsl:with-param name="lrx" select="$lrx"/>
                            <xsl:with-param name="lry" select="$lry"/>
                        </xsl:call-template>
                        <String>
                            <xsl:attribute name="ID"><xsl:value-of select="concat(@xml:id, '_w')"/></xsl:attribute>
                            <xsl:call-template name="emit-bbox-attrs">
                                <xsl:with-param name="ulx" select="$ulx"/>
                                <xsl:with-param name="uly" select="$uly"/>
                                <xsl:with-param name="lrx" select="$lrx"/>
                                <xsl:with-param name="lry" select="$lry"/>
                            </xsl:call-template>
                            <xsl:attribute name="CONTENT"><xsl:value-of select="normalize-space(.)"/></xsl:attribute>
                        </String>
                    </TextLine>
                </xsl:otherwise>
            </xsl:choose>
        </TextBlock>
    </xsl:template>

    <!-- ============================================================== -->
    <!-- Inner zone -> TextLine -->
    <!-- ============================================================== -->

    <xsl:template match="tei:zone | *[local-name()='zone']" mode="line">
        <xsl:variable name="ulx" select="number(@ulx)"/>
        <xsl:variable name="uly" select="number(@uly)"/>
        <xsl:variable name="lrx" select="number(@lrx)"/>
        <xsl:variable name="lry" select="number(@lry)"/>
        <TextLine>
            <xsl:attribute name="ID"><xsl:value-of select="@xml:id | @id"/></xsl:attribute>
            <xsl:call-template name="emit-bbox-attrs">
                <xsl:with-param name="ulx" select="$ulx"/>
                <xsl:with-param name="uly" select="$uly"/>
                <xsl:with-param name="lrx" select="$lrx"/>
                <xsl:with-param name="lry" select="$lry"/>
            </xsl:call-template>
            <String>
                <xsl:attribute name="ID"><xsl:value-of select="concat(@xml:id, '_w')"/></xsl:attribute>
                <xsl:call-template name="emit-bbox-attrs">
                    <xsl:with-param name="ulx" select="$ulx"/>
                    <xsl:with-param name="uly" select="$uly"/>
                    <xsl:with-param name="lrx" select="$lrx"/>
                    <xsl:with-param name="lry" select="$lry"/>
                </xsl:call-template>
                <xsl:attribute name="CONTENT"><xsl:value-of select="normalize-space(.)"/></xsl:attribute>
            </String>
        </TextLine>
    </xsl:template>

    <!-- ============================================================== -->
    <!-- Helper: bbox attributes from ulx/uly/lrx/lry -->
    <!-- ============================================================== -->

    <xsl:template name="emit-bbox-attrs">
        <xsl:param name="ulx"/>
        <xsl:param name="uly"/>
        <xsl:param name="lrx"/>
        <xsl:param name="lry"/>
        <xsl:attribute name="HPOS"><xsl:value-of select="$ulx"/></xsl:attribute>
        <xsl:attribute name="VPOS"><xsl:value-of select="$uly"/></xsl:attribute>
        <xsl:attribute name="WIDTH"><xsl:value-of select="$lrx - $ulx"/></xsl:attribute>
        <xsl:attribute name="HEIGHT"><xsl:value-of select="$lry - $uly"/></xsl:attribute>
    </xsl:template>

</xsl:stylesheet>
