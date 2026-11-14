<?xml version="1.0" encoding="UTF-8"?>
<!--
    Convert a TEI facsimile (Transkribus / eScriptorium / e-editiones flavour)
    into a standard ALTO (v4) XML file.

    Supported source structure:
    - One <facsimile> with N <surface> elements (multipage).
    - Each <surface> declares bbox via @ulx @uly @lrx @lry or via
      @points="x1,y1 x2,y2 …" and optionally @source pointing at the image
      filename.
    - Nested <zone> elements inside <surface> carry @ulx @uly @lrx @lry or
      @points and optional inner text (<line>, <seg>, raw text).
    - When zones are missing, the surface itself becomes a single TextBlock
      and any descendant text is emitted as a String.
    - <text><body> elements referencing zones via @facs="#zone-id" have
      their text exported into the matching zone (concatenated normalized
      text content). Inline text colocated with the zone takes precedence.

    Not supported:
    - Surfaces/zones with neither bbox attributes nor @points.

    @copyright Daniel Berthereau, 2026
    @license CeCILL 2.1 https://cecill.info/licences/Licence_CeCILL_V2.1-fr.txt

    @version 0.2.0
-->

<xsl:stylesheet version="1.0"
    xmlns:xsl="http://www.w3.org/1999/XSL/Transform"
    xmlns="http://www.loc.gov/standards/alto/ns-v4#"
    xmlns:tei="http://www.tei-c.org/ns/1.0"
    exclude-result-prefixes="tei">

    <xsl:output method="xml" indent="yes" encoding="UTF-8" omit-xml-declaration="no"/>
    <xsl:strip-space elements="*"/>

    <xsl:key name="facs-target" match="*[@facs]" use="substring-after(@facs, '#')"/>

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
        <xsl:variable name="bbox">
            <xsl:call-template name="resolve-bbox"/>
        </xsl:variable>
        <xsl:variable name="ulx" select="substring-before($bbox, ',')"/>
        <xsl:variable name="rest1" select="substring-after($bbox, ',')"/>
        <xsl:variable name="uly" select="substring-before($rest1, ',')"/>
        <xsl:variable name="rest2" select="substring-after($rest1, ',')"/>
        <xsl:variable name="lrx" select="substring-before($rest2, ',')"/>
        <xsl:variable name="lry" select="substring-after($rest2, ',')"/>
        <xsl:variable name="w" select="number($lrx) - number($ulx)"/>
        <xsl:variable name="h" select="number($lry) - number($uly)"/>
        <Page>
            <xsl:attribute name="ID"><xsl:value-of select="@xml:id | @id"/></xsl:attribute>
            <xsl:attribute name="PHYSICAL_IMG_NR"><xsl:value-of select="position()"/></xsl:attribute>
            <xsl:attribute name="WIDTH"><xsl:value-of select="$lrx"/></xsl:attribute>
            <xsl:attribute name="HEIGHT"><xsl:value-of select="$lry"/></xsl:attribute>
            <xsl:if test="@source">
                <xsl:attribute name="PAGECLASS"><xsl:value-of select="@source"/></xsl:attribute>
            </xsl:if>
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
    <!-- Outer zone -> TextBlock -->
    <!-- ============================================================== -->

    <xsl:template match="tei:zone | *[local-name()='zone']" mode="block">
        <xsl:variable name="bbox">
            <xsl:call-template name="resolve-bbox"/>
        </xsl:variable>
        <xsl:variable name="ulx" select="substring-before($bbox, ',')"/>
        <xsl:variable name="rest1" select="substring-after($bbox, ',')"/>
        <xsl:variable name="uly" select="substring-before($rest1, ',')"/>
        <xsl:variable name="rest2" select="substring-after($rest1, ',')"/>
        <xsl:variable name="lrx" select="substring-before($rest2, ',')"/>
        <xsl:variable name="lry" select="substring-after($rest2, ',')"/>
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
                            <xsl:attribute name="CONTENT">
                                <xsl:call-template name="resolve-text"/>
                            </xsl:attribute>
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
        <xsl:variable name="bbox">
            <xsl:call-template name="resolve-bbox"/>
        </xsl:variable>
        <xsl:variable name="ulx" select="substring-before($bbox, ',')"/>
        <xsl:variable name="rest1" select="substring-after($bbox, ',')"/>
        <xsl:variable name="uly" select="substring-before($rest1, ',')"/>
        <xsl:variable name="rest2" select="substring-after($rest1, ',')"/>
        <xsl:variable name="lrx" select="substring-before($rest2, ',')"/>
        <xsl:variable name="lry" select="substring-after($rest2, ',')"/>
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
                <xsl:attribute name="CONTENT">
                    <xsl:call-template name="resolve-text"/>
                </xsl:attribute>
            </String>
        </TextLine>
    </xsl:template>

    <!-- ============================================================== -->
    <!-- Helpers -->
    <!-- ============================================================== -->

    <xsl:template name="emit-bbox-attrs">
        <xsl:param name="ulx"/>
        <xsl:param name="uly"/>
        <xsl:param name="lrx"/>
        <xsl:param name="lry"/>
        <xsl:attribute name="HPOS"><xsl:value-of select="$ulx"/></xsl:attribute>
        <xsl:attribute name="VPOS"><xsl:value-of select="$uly"/></xsl:attribute>
        <xsl:attribute name="WIDTH"><xsl:value-of select="number($lrx) - number($ulx)"/></xsl:attribute>
        <xsl:attribute name="HEIGHT"><xsl:value-of select="number($lry) - number($uly)"/></xsl:attribute>
    </xsl:template>

    <!--
        Resolve a bbox as "ulx,uly,lrx,lry" from either @ulx/@uly/@lrx/@lry
        or @points. Returns a comma-separated string.
    -->
    <xsl:template name="resolve-bbox">
        <xsl:choose>
            <xsl:when test="@ulx and @uly and @lrx and @lry">
                <xsl:value-of select="concat(@ulx, ',', @uly, ',', @lrx, ',', @lry)"/>
            </xsl:when>
            <xsl:when test="@points">
                <xsl:call-template name="points-bbox">
                    <xsl:with-param name="points" select="normalize-space(@points)"/>
                </xsl:call-template>
            </xsl:when>
            <xsl:otherwise>
                <xsl:text>0,0,0,0</xsl:text>
            </xsl:otherwise>
        </xsl:choose>
    </xsl:template>

    <!--
        Compute bbox of a polygon "x1,y1 x2,y2 …" by recursive folding.
        Tail-recursive on the remaining points string.
    -->
    <xsl:template name="points-bbox">
        <xsl:param name="points"/>
        <xsl:param name="minx" select="''"/>
        <xsl:param name="miny" select="''"/>
        <xsl:param name="maxx" select="''"/>
        <xsl:param name="maxy" select="''"/>
        <xsl:variable name="pair">
            <xsl:choose>
                <xsl:when test="contains($points, ' ')">
                    <xsl:value-of select="substring-before($points, ' ')"/>
                </xsl:when>
                <xsl:otherwise>
                    <xsl:value-of select="$points"/>
                </xsl:otherwise>
            </xsl:choose>
        </xsl:variable>
        <xsl:variable name="rest" select="normalize-space(substring-after($points, ' '))"/>
        <xsl:variable name="x" select="number(substring-before($pair, ','))"/>
        <xsl:variable name="y" select="number(substring-after($pair, ','))"/>
        <xsl:variable name="newMinx">
            <xsl:choose>
                <xsl:when test="$minx = '' or $x &lt; number($minx)"><xsl:value-of select="$x"/></xsl:when>
                <xsl:otherwise><xsl:value-of select="$minx"/></xsl:otherwise>
            </xsl:choose>
        </xsl:variable>
        <xsl:variable name="newMiny">
            <xsl:choose>
                <xsl:when test="$miny = '' or $y &lt; number($miny)"><xsl:value-of select="$y"/></xsl:when>
                <xsl:otherwise><xsl:value-of select="$miny"/></xsl:otherwise>
            </xsl:choose>
        </xsl:variable>
        <xsl:variable name="newMaxx">
            <xsl:choose>
                <xsl:when test="$maxx = '' or $x &gt; number($maxx)"><xsl:value-of select="$x"/></xsl:when>
                <xsl:otherwise><xsl:value-of select="$maxx"/></xsl:otherwise>
            </xsl:choose>
        </xsl:variable>
        <xsl:variable name="newMaxy">
            <xsl:choose>
                <xsl:when test="$maxy = '' or $y &gt; number($maxy)"><xsl:value-of select="$y"/></xsl:when>
                <xsl:otherwise><xsl:value-of select="$maxy"/></xsl:otherwise>
            </xsl:choose>
        </xsl:variable>
        <xsl:choose>
            <xsl:when test="$rest = ''">
                <xsl:value-of select="concat($newMinx, ',', $newMiny, ',', $newMaxx, ',', $newMaxy)"/>
            </xsl:when>
            <xsl:otherwise>
                <xsl:call-template name="points-bbox">
                    <xsl:with-param name="points" select="$rest"/>
                    <xsl:with-param name="minx" select="$newMinx"/>
                    <xsl:with-param name="miny" select="$newMiny"/>
                    <xsl:with-param name="maxx" select="$newMaxx"/>
                    <xsl:with-param name="maxy" select="$newMaxy"/>
                </xsl:call-template>
            </xsl:otherwise>
        </xsl:choose>
    </xsl:template>

    <!--
        Resolve text content for the current zone: inline text first; if
        empty, follow @facs references back from <text><body> descendants.
    -->
    <xsl:template name="resolve-text">
        <xsl:variable name="inline" select="normalize-space(.)"/>
        <xsl:choose>
            <xsl:when test="$inline != ''">
                <xsl:value-of select="$inline"/>
            </xsl:when>
            <xsl:when test="@xml:id">
                <xsl:for-each select="key('facs-target', string(@xml:id))">
                    <xsl:if test="position() &gt; 1"><xsl:text> </xsl:text></xsl:if>
                    <xsl:value-of select="normalize-space(.)"/>
                </xsl:for-each>
            </xsl:when>
        </xsl:choose>
    </xsl:template>

</xsl:stylesheet>
