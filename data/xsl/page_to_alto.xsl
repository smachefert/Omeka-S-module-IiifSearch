<?xml version="1.0" encoding="UTF-8"?>
<!--
    Convert a PAGE XML (PRImA PcGts, as produced by laypa, Loghi, Transkribus,
    eScriptorium, OCR-D, etc.) into a standard ALTO (v4) XML file.

    Supported source structure:
    - Any PAGE version (2009 to 2019): elements are matched by local name.
    - One <Page> per file, with @imageFilename, @imageWidth and @imageHeight.
      The image filename is kept as sourceImageInformation to pair the page
      with its image media.
    - TextRegion -> TextBlock, TextLine -> TextLine, Word -> String. The bbox
      of each element is computed from the polygon of its Coords/@points.
    - Text is the Unicode of the first TextEquiv (lowest @index).
    - When a line has no words (or words without coordinates), the text of the
      line is split on spaces and each word gets a proportional part of the
      line, like pdf2xml.
    - Regions are ordered by the ReadingOrder; regions that are not referenced
      in it are appended in document order. Nested groups are flattened.
    - Nested regions (text regions inside a table region or another region)
      are output as separate blocks.

    Not supported:
    - Baselines and polygons themselves: only the bbox is kept.
    - Glyphs, text styles and non-text regions (images, graphics, etc.).

    @copyright Bob Coret, 2026
    @license CeCILL 2.1 https://cecill.info/licences/Licence_CeCILL_V2.1-fr.txt

    @version 0.1.0
-->

<xsl:stylesheet version="1.0"
    xmlns:xsl="http://www.w3.org/1999/XSL/Transform"
    xmlns="http://www.loc.gov/standards/alto/ns-v4#">

    <xsl:output method="xml" indent="yes" encoding="UTF-8" omit-xml-declaration="no"/>
    <xsl:strip-space elements="*"/>

    <!-- Any region (TextRegion, TableRegion, etc.) by id. -->
    <xsl:key name="region" match="*[@id][substring(local-name(), string-length(local-name()) - 5) = 'Region']" use="@id"/>
    <!-- References of the reading order by referenced id. -->
    <xsl:key name="ref" match="*[local-name() = 'RegionRefIndexed' or local-name() = 'RegionRef']" use="@regionRef"/>

    <!-- ============================================================== -->
    <!-- Root -->
    <!-- ============================================================== -->

    <xsl:template match="/">
        <xsl:variable name="page" select="/*[local-name() = 'PcGts']/*[local-name() = 'Page'][1]"/>
        <alto xmlns="http://www.loc.gov/standards/alto/ns-v4#">
            <Description>
                <MeasurementUnit>pixel</MeasurementUnit>
                <xsl:if test="normalize-space($page/@imageFilename) != ''">
                    <sourceImageInformation>
                        <fileName><xsl:value-of select="normalize-space($page/@imageFilename)"/></fileName>
                    </sourceImageInformation>
                </xsl:if>
            </Description>
            <Layout>
                <xsl:apply-templates select="$page"/>
            </Layout>
        </alto>
    </xsl:template>

    <!-- ============================================================== -->
    <!-- Page -> Page -->
    <!-- ============================================================== -->

    <xsl:template match="*[local-name() = 'Page']">
        <Page ID="page_1" PHYSICAL_IMG_NR="1">
            <xsl:attribute name="WIDTH"><xsl:value-of select="number(@imageWidth)"/></xsl:attribute>
            <xsl:attribute name="HEIGHT"><xsl:value-of select="number(@imageHeight)"/></xsl:attribute>
            <PrintSpace HPOS="0" VPOS="0">
                <xsl:attribute name="WIDTH"><xsl:value-of select="number(@imageWidth)"/></xsl:attribute>
                <xsl:attribute name="HEIGHT"><xsl:value-of select="number(@imageHeight)"/></xsl:attribute>
                <!-- First pass: regions in the reading order, group by group. -->
                <xsl:for-each select=".//*[local-name() = 'ReadingOrder']//*[*[local-name() = 'RegionRefIndexed' or local-name() = 'RegionRef']]">
                    <xsl:for-each select="*[local-name() = 'RegionRefIndexed' or local-name() = 'RegionRef']">
                        <xsl:sort select="@index" data-type="number"/>
                        <xsl:apply-templates select="key('region', @regionRef)" mode="region"/>
                    </xsl:for-each>
                </xsl:for-each>
                <!-- Second pass: text regions not covered by the reading order. -->
                <xsl:apply-templates mode="region" select=".//*[local-name() = 'TextRegion']
                    [not(key('ref', @id))]
                    [not(ancestor::*[@id][key('ref', @id)])]
                    [not(ancestor::*[local-name() = 'TextRegion'])]"/>
            </PrintSpace>
        </Page>
    </xsl:template>

    <!-- ============================================================== -->
    <!-- Regions -->
    <!-- ============================================================== -->

    <!-- TextRegion -> TextBlock, then the nested regions as next blocks. -->
    <xsl:template match="*[local-name() = 'TextRegion']" mode="region">
        <xsl:variable name="lines" select="*[local-name() = 'TextLine']"/>
        <xsl:variable name="text">
            <xsl:call-template name="text-equiv"/>
        </xsl:variable>
        <xsl:if test="$lines or normalize-space($text) != ''">
            <TextBlock>
                <xsl:attribute name="ID"><xsl:value-of select="@id"/></xsl:attribute>
                <xsl:call-template name="emit-coords"/>
                <xsl:choose>
                    <xsl:when test="$lines">
                        <xsl:apply-templates select="$lines"/>
                    </xsl:when>
                    <!-- A region with a text but without lines is a single line. -->
                    <xsl:otherwise>
                        <TextLine>
                            <xsl:attribute name="ID"><xsl:value-of select="concat(@id, '_line')"/></xsl:attribute>
                            <xsl:call-template name="emit-coords"/>
                            <xsl:call-template name="split-line">
                                <xsl:with-param name="text" select="normalize-space($text)"/>
                            </xsl:call-template>
                        </TextLine>
                    </xsl:otherwise>
                </xsl:choose>
            </TextBlock>
        </xsl:if>
        <xsl:apply-templates mode="region" select="*[substring(local-name(), string-length(local-name()) - 5) = 'Region'][not(key('ref', @id))]"/>
    </xsl:template>

    <!-- Other regions (table, etc.): only the nested text regions are kept. -->
    <xsl:template match="*" mode="region">
        <xsl:apply-templates mode="region" select="*[substring(local-name(), string-length(local-name()) - 5) = 'Region'][not(key('ref', @id))]"/>
    </xsl:template>

    <!-- ============================================================== -->
    <!-- TextLine -> TextLine -->
    <!-- ============================================================== -->

    <xsl:template match="*[local-name() = 'TextLine']">
        <xsl:variable name="words" select="*[local-name() = 'Word']"/>
        <xsl:variable name="useWords" select="$words and not($words[not(*[local-name() = 'Coords']/@points)])"/>
        <xsl:variable name="text">
            <xsl:choose>
                <xsl:when test="$useWords">
                    <xsl:for-each select="$words">
                        <xsl:text> </xsl:text>
                        <xsl:call-template name="text-equiv"/>
                    </xsl:for-each>
                </xsl:when>
                <xsl:otherwise>
                    <xsl:call-template name="text-equiv"/>
                </xsl:otherwise>
            </xsl:choose>
        </xsl:variable>
        <!-- A line without text is skipped: alto requires a String in a line. -->
        <xsl:if test="normalize-space($text) != ''">
            <TextLine>
                <xsl:attribute name="ID"><xsl:value-of select="@id"/></xsl:attribute>
                <xsl:call-template name="emit-coords"/>
                <xsl:choose>
                    <xsl:when test="$useWords">
                        <xsl:apply-templates select="$words"/>
                    </xsl:when>
                    <xsl:otherwise>
                        <xsl:call-template name="split-line">
                            <xsl:with-param name="text" select="normalize-space($text)"/>
                        </xsl:call-template>
                    </xsl:otherwise>
                </xsl:choose>
            </TextLine>
        </xsl:if>
    </xsl:template>

    <!-- ============================================================== -->
    <!-- Word -> String -->
    <!-- ============================================================== -->

    <xsl:template match="*[local-name() = 'Word']">
        <xsl:variable name="text">
            <xsl:call-template name="text-equiv"/>
        </xsl:variable>
        <xsl:if test="normalize-space($text) != ''">
            <String>
                <xsl:attribute name="ID"><xsl:value-of select="@id"/></xsl:attribute>
                <xsl:call-template name="emit-coords"/>
                <xsl:attribute name="CONTENT"><xsl:value-of select="normalize-space($text)"/></xsl:attribute>
                <xsl:call-template name="emit-wc"/>
            </String>
        </xsl:if>
    </xsl:template>

    <!-- ============================================================== -->
    <!-- Helpers -->
    <!-- ============================================================== -->

    <!-- Unicode of the first TextEquiv of the current element (lowest @index). -->
    <xsl:template name="text-equiv">
        <xsl:for-each select="*[local-name() = 'TextEquiv'][*[local-name() = 'Unicode']]">
            <xsl:sort select="@index" data-type="number"/>
            <xsl:if test="position() = 1">
                <xsl:value-of select="*[local-name() = 'Unicode']"/>
            </xsl:if>
        </xsl:for-each>
    </xsl:template>

    <!-- Word confidence of the first TextEquiv, when set. -->
    <xsl:template name="emit-wc">
        <xsl:for-each select="*[local-name() = 'TextEquiv'][*[local-name() = 'Unicode']]">
            <xsl:sort select="@index" data-type="number"/>
            <xsl:if test="position() = 1 and string(number(@conf)) != 'NaN'">
                <xsl:attribute name="WC"><xsl:value-of select="@conf"/></xsl:attribute>
            </xsl:if>
        </xsl:for-each>
    </xsl:template>

    <!-- Get the bbox "minx,miny,maxx,maxy" of the Coords of the current element. -->
    <xsl:template name="coords-bbox">
        <xsl:variable name="points" select="normalize-space(*[local-name() = 'Coords']/@points)"/>
        <xsl:choose>
            <xsl:when test="$points != ''">
                <xsl:call-template name="points-bbox">
                    <xsl:with-param name="points" select="$points"/>
                </xsl:call-template>
            </xsl:when>
            <xsl:otherwise>
                <xsl:text>0,0,0,0</xsl:text>
            </xsl:otherwise>
        </xsl:choose>
    </xsl:template>

    <!-- Emit HPOS/VPOS/WIDTH/HEIGHT of the Coords of the current element. -->
    <xsl:template name="emit-coords">
        <xsl:variable name="bbox">
            <xsl:call-template name="coords-bbox"/>
        </xsl:variable>
        <xsl:call-template name="emit-bbox-attrs">
            <xsl:with-param name="bbox" select="$bbox"/>
        </xsl:call-template>
    </xsl:template>

    <!-- Emit HPOS/VPOS/WIDTH/HEIGHT from "minx,miny,maxx,maxy". Negative coordinates are set to 0. -->
    <xsl:template name="emit-bbox-attrs">
        <xsl:param name="bbox"/>
        <xsl:variable name="rest1" select="substring-after($bbox, ',')"/>
        <xsl:variable name="rest2" select="substring-after($rest1, ',')"/>
        <xsl:variable name="minx" select="number(substring-before($bbox, ','))"/>
        <xsl:variable name="miny" select="number(substring-before($rest1, ','))"/>
        <xsl:variable name="maxx" select="number(substring-before($rest2, ','))"/>
        <xsl:variable name="maxy" select="number(substring-after($rest2, ','))"/>
        <xsl:variable name="x" select="$minx * ($minx &gt; 0)"/>
        <xsl:variable name="y" select="$miny * ($miny &gt; 0)"/>
        <xsl:attribute name="HPOS"><xsl:value-of select="$x"/></xsl:attribute>
        <xsl:attribute name="VPOS"><xsl:value-of select="$y"/></xsl:attribute>
        <xsl:attribute name="WIDTH"><xsl:value-of select="($maxx - $x) * ($maxx &gt; $x)"/></xsl:attribute>
        <xsl:attribute name="HEIGHT"><xsl:value-of select="($maxy - $y) * ($maxy &gt; $y)"/></xsl:attribute>
    </xsl:template>

    <!-- Split the text of the current line into words with a proportional width. -->
    <xsl:template name="split-line">
        <xsl:param name="text"/>
        <xsl:if test="$text != ''">
            <xsl:variable name="bbox">
                <xsl:call-template name="coords-bbox"/>
            </xsl:variable>
            <xsl:variable name="rest1" select="substring-after($bbox, ',')"/>
            <xsl:variable name="rest2" select="substring-after($rest1, ',')"/>
            <xsl:variable name="minx" select="number(substring-before($bbox, ','))"/>
            <xsl:variable name="miny" select="number(substring-before($rest1, ','))"/>
            <xsl:variable name="maxx" select="number(substring-before($rest2, ','))"/>
            <xsl:variable name="maxy" select="number(substring-after($rest2, ','))"/>
            <xsl:variable name="x" select="$minx * ($minx &gt; 0)"/>
            <xsl:variable name="y" select="$miny * ($miny &gt; 0)"/>
            <xsl:call-template name="split">
                <xsl:with-param name="string" select="$text"/>
                <xsl:with-param name="left" select="$x"/>
                <xsl:with-param name="top" select="$y"/>
                <xsl:with-param name="height" select="($maxy - $y) * ($maxy &gt; $y)"/>
                <xsl:with-param name="size_character" select="(($maxx - $x) * ($maxx &gt; $x)) div string-length($text)"/>
            </xsl:call-template>
        </xsl:if>
    </xsl:template>

    <!-- Same as in pdf2xml_to_alto.xsl. The string is normalized. -->
    <xsl:template name="split">
        <xsl:param name="string"/>
        <xsl:param name="left" select="0"/>
        <xsl:param name="top" select="0"/>
        <xsl:param name="height" select="0"/>
        <xsl:param name="size_character" select="0"/>
        <xsl:if test="string-length($string) > 0">
            <xsl:variable name="has_space" select="contains($string, ' ')"/>
            <xsl:variable name="current_word" select="substring-before(concat($string, ' '), ' ')"/>
            <xsl:variable name="current_width" select="string-length($current_word) * $size_character"/>
            <String HPOS="{floor($left)}" VPOS="{floor($top)}" WIDTH="{round($current_width)}" HEIGHT="{ceiling($height)}" CONTENT="{$current_word}"/>
            <xsl:if test="$has_space">
                <SP HPOS="{floor($left + $current_width)}" VPOS="{floor($top)}" WIDTH="{round($size_character)}"/>
            </xsl:if>
            <xsl:call-template name="split">
                <xsl:with-param name="string" select="substring-after($string, ' ')"/>
                <xsl:with-param name="left" select="$left + (string-length($current_word) + $has_space) * $size_character"/>
                <xsl:with-param name="top" select="$top"/>
                <xsl:with-param name="height" select="$height"/>
                <xsl:with-param name="size_character" select="$size_character"/>
            </xsl:call-template>
        </xsl:if>
    </xsl:template>

    <!--
        Compute bbox of a polygon "x1,y1 x2,y2 …" by recursive folding.
        Tail-recursive on the remaining points string. Same as in
        tei_to_alto.xsl.
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

</xsl:stylesheet>
