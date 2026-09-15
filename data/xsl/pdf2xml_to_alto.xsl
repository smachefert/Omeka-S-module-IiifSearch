<?xml version="1.0" encoding="UTF-8"?>
<!--
    Convert a xml file "pdf2xml", created via poppler phptohtml, into a standard xml alto (v4).

    Because the source is a basic format (pdf2xml) and because the purpose of conversion is
    to standardize text search and highlighting for IIIF, only basic features of alto are used.
    Anyway, to use a xslt to rebuild a complex layout from a pdf2xml is not an appropriate process.

    @todo Manage pdf2xml bold/italic/link.
    @todo Recreate block/paragraphs and text stream.
    @todo Insert illustrations (useless for word search and highlighting in iiif).

    @see https://gitlab.freedesktop.org/poppler/poppler/-/raw/master/utils/pdf2xml.dtd

    @copyright Daniel Berthereau, 2023
    @license CeCILL 2.1 https://cecill.info/licences/Licence_CeCILL_V2.1-fr.txt

    @version 0.1.1
-->

<xsl:stylesheet version="1.0"
    xmlns:xsl="http://www.w3.org/1999/XSL/Transform"

    xmlns:alto="http://www.loc.gov/standards/alto/ns-v4#"
    xmlns:xlink="http://www.w3.org/1999/xlink"
    xmlns:xsi="http://www.w3.org/2001/XMLSchema-instance"

    exclude-result-prefixes="
        xsl alto
        "
    >

    <xsl:output method="xml" encoding="UTF-8" indent="yes"/>

    <xsl:strip-space elements="*"/>

    <!-- Constants. -->

    <xsl:variable name="software_creator">Daniel Berthereau</xsl:variable>
    <xsl:variable name="software_name">pdf2xml_to_alto.xsl</xsl:variable>
    <xsl:variable name="software_version">0.1.1</xsl:variable>

    <!-- Parameters. -->

    <!-- Date can only be a parameter in xslt v1 without exlt. -->
    <xsl:param name="datetime"></xsl:param>

    <!-- Source file. -->
    <xsl:param name="source_pdf_file_url"></xsl:param>
    <xsl:param name="source_pdf_file_name"></xsl:param>
    <xsl:param name="source_pdf_file_identifier"></xsl:param>
    <xsl:param name="source_pdf_document_url"></xsl:param>
    <xsl:param name="source_pdf_document_identifier"></xsl:param>

    <!-- Identity template. -->
    <xsl:template match="@*|node()">
        <xsl:copy>
            <xsl:apply-templates select="@*|node()"/>
        </xsl:copy>
    </xsl:template>

    <!-- Root template. -->
    <xsl:template match="/pdf2xml">
        <alto
            xmlns="http://www.loc.gov/standards/alto/ns-v4#"
            xmlns:xlink="http://www.w3.org/1999/xlink"
            xmlns:xsi="http://www.w3.org/2001/XMLSchema-instance"
            xsi:schemaLocation="http://www.loc.gov/standards/alto/ns-v4# https://www.loc.gov/standards/alto/v4/alto.xsd"
            >
            <Description>
                <MeasurementUnit>pixel</MeasurementUnit>
                <xsl:if test="
                    $source_pdf_file_url != ''
                    or $source_pdf_file_name != ''
                    or $source_pdf_file_identifier != ''
                    or $source_pdf_document_url != ''
                    or $source_pdf_document_identifier != ''
                    ">
                    <sourceImageInformation>
                        <xsl:if test="$source_pdf_file_name != ''">
                            <fileName><xsl:value-of select="$source_pdf_file_name"/></fileName>
                        </xsl:if>
                        <xsl:if test="$source_pdf_file_url != '' or $source_pdf_file_identifier != ''">
                            <fileIdentifier fileIdentifierLocation="{$source_pdf_file_url}">
                                <xsl:choose>
                                    <xsl:when test="$source_pdf_file_identifier != ''"><xsl:value-of select="$source_pdf_file_identifier"/></xsl:when>
                                    <xsl:otherwise><xsl:value-of select="$source_pdf_file_url"/></xsl:otherwise>
                                </xsl:choose>
                            </fileIdentifier>
                        </xsl:if>
                        <xsl:if test="$source_pdf_file_url != '' or $source_pdf_file_identifier != ''">
                            <documentIdentifier documentIdentifierLocation="{$source_pdf_document_url}">
                                <xsl:choose>
                                    <xsl:when test="$source_pdf_document_identifier != ''"><xsl:value-of select="$source_pdf_document_identifier"/></xsl:when>
                                    <xsl:otherwise><xsl:value-of select="$source_pdf_document_url"/></xsl:otherwise>
                                </xsl:choose>
                            </documentIdentifier>
                        </xsl:if>
                    </sourceImageInformation>
                </xsl:if>
                <Processing ID="PR_1">
                    <processingStepDescription>Extraction of the text layer from pdf to xml</processingStepDescription>
                    <processingSoftware>
                        <!--
                        <softwareCreator></softwareCreator>
                        -->
                        <softwareName><xsl:value-of select="@producer"/></softwareName>
                        <softwareVersion><xsl:value-of select="@version"/></softwareVersion>
                    </processingSoftware>
                </Processing>
                <Processing ID="PR_2">
                    <xsl:if test="$datetime != ''">
                        <processingDateTime>
                            <xsl:value-of select="$datetime"/>
                        </processingDateTime>
                    </xsl:if>
                    <processingStepDescription>Conversion from pdf2xml to alto</processingStepDescription>
                    <processingStepSettings>
                        <xsl:text>&#x0A;</xsl:text>
                        <xsl:text>include_text = true</xsl:text>
                        <xsl:text>&#x0A;</xsl:text>
                        <xsl:text>include_fonts = true</xsl:text>
                        <xsl:text>&#x0A;</xsl:text>
                        <xsl:text>include_images = false</xsl:text>
                        <xsl:text>&#x0A;</xsl:text>
                        <xsl:text>include_outline = false</xsl:text>
                        <xsl:text>&#x0A;</xsl:text>
                        <xsl:text>include_bil = false</xsl:text>
                        <xsl:text>&#x0A;</xsl:text>
                    </processingStepSettings>
                    <processingSoftware>
                        <softwareCreator><xsl:value-of select="$software_creator"/></softwareCreator>
                        <softwareName><xsl:value-of select="$software_name"/></softwareName>
                        <softwareVersion><xsl:value-of select="$software_version"/></softwareVersion>
                    </processingSoftware>
                </Processing>
            </Description>
            <xsl:if test="page/fontspec">
                <Styles>
                    <xsl:apply-templates select="page/fontspec"/>
                </Styles>
            </xsl:if>
            <Layout>
                <xsl:apply-templates select="page"/>
            </Layout>
        </alto>
    </xsl:template>

    <xsl:template match="fontspec">
        <!-- Old versions of pdftohtml return bad id and bad size (example: pdftohtml 0.71 on debian 10). -->

        <!-- Convert pdf2xml 0-based id into 1-based alto id. -->
        <!-- Anyway, the id may be badly output, so don't use @id but position(). -->
        <xsl:variable name="textstyle_id" select="concat('TS_', position())"/>

        <xsl:variable name="textstyle_family" select="@family"/>

        <xsl:variable name="textstyle_size">
            <xsl:choose>
                <xsl:when test="normalize-space(@size) = '' or @size + 0 != @size">
                    <xsl:text>10</xsl:text>
                </xsl:when>
                <xsl:otherwise>
                    <xsl:value-of select="@size"/>
                </xsl:otherwise>
            </xsl:choose>
        </xsl:variable>

        <xsl:variable name="textstyle_color">
            <xsl:choose>
                <xsl:when test="normalize-space(@color) = ''">
                    <xsl:text>000000</xsl:text>
                </xsl:when>
                <xsl:when test="substring(@color, 1, 1) = '#'">
                    <xsl:value-of select="substring(@color, 2)"/>
                </xsl:when>
                <xsl:otherwise>
                    <xsl:value-of select="@color"/>
                </xsl:otherwise>
            </xsl:choose>
        </xsl:variable>

        <TextStyle xmlns="http://www.loc.gov/standards/alto/ns-v4#" ID="{$textstyle_id}" FONTFAMILY="{$textstyle_family}" FONTSIZE="{$textstyle_size}" FONTCOLOR="{$textstyle_color}"/>
    </xsl:template>

    <xsl:template match="page">
        <!-- TODO Pdf2xml page position is always absolute? -->
        <xsl:variable name="hpos"><xsl:call-template name="min_left"/></xsl:variable>
        <xsl:variable name="vpos"><xsl:call-template name="min_top"/></xsl:variable>
        <xsl:variable name="width"><xsl:call-template name="max_width"/></xsl:variable>
        <xsl:variable name="height"><xsl:call-template name="max_height"/></xsl:variable>
        <!-- TODO Differences between print space and composed block are margins. -->
        <Page xmlns="http://www.loc.gov/standards/alto/ns-v4#" PHYSICAL_IMG_NR="{position()}" WIDTH="{@width}" HEIGHT="{@height}" ID="{concat('PG_', position())}">
            <PrintSpace HPOS="{@left}" VPOS="{@top}" WIDTH="{@width}" HEIGHT="{@height}">
                <ComposedBlock HPOS="{$hpos}" VPOS="{$vpos}" WIDTH="{$width}" HEIGHT="{$height}" ID="{concat('CB_', position())}">
                    <TextBlock HPOS="{$hpos}" VPOS="{$vpos}" WIDTH="{$width}" HEIGHT="{$height}" ID="{concat('TB_', position())}">
                        <xsl:apply-templates select="text"/>
                    </TextBlock>
                </ComposedBlock>
            </PrintSpace>
        </Page>
    </xsl:template>

    <xsl:template match="text">
        <xsl:variable name="stylerefs">
            <xsl:if test="@font and @font != ''">
                <xsl:value-of select="concat('TS_', @font + 1)"/>
            </xsl:if>
        </xsl:variable>
        <TextLine xmlns="http://www.loc.gov/standards/alto/ns-v4#" HPOS="{@left}" VPOS="{@top}" WIDTH="{@width}" HEIGHT="{@height}" STYLEREFS="{$stylerefs}" >
            <xsl:choose>
                <xsl:when test="contains(., ' ')">
                    <!-- The size of a character or a space is the same for all characters. -->
                    <!-- TODO Adapt character and space size to the font family or at least for common size. -->
                    <xsl:apply-templates select="." mode="split">
                        <xsl:with-param name="string" select="."/>
                        <xsl:with-param name="left" select="@left"/>
                        <xsl:with-param name="top" select="@top"/>
                        <xsl:with-param name="width" select="@width"/>
                        <xsl:with-param name="height" select="@height"/>
                        <xsl:with-param name="size_character" select="@width div string-length(.)"/>
                    </xsl:apply-templates>
                </xsl:when>
                <xsl:otherwise>
                    <String HPOS="{@left}" VPOS="{@top}" WIDTH="{@width}" HEIGHT="{@height}" CONTENT="{.}"/>
                </xsl:otherwise>
            </xsl:choose>
        </TextLine>
    </xsl:template>

    <xsl:template match="text" name="split" mode="split">
        <xsl:param name="string" select="."/>
        <xsl:param name="left" select="0"/>
        <xsl:param name="top" select="0"/>
        <xsl:param name="width" select="0"/>
        <xsl:param name="height" select="0"/>
        <xsl:param name="size_character" select="0"/>
        <!-- `round(0.5)` returns the next integer (1), and the character size is at least 1 pixel, so no need to check for 0. -->
        <xsl:if test="string-length($string) > 0">
            <xsl:variable name="has_space" select="contains($string, ' ')"/>
            <xsl:variable name="current_word" select="substring-before(concat($string, ' '), ' ')"/>
            <xsl:variable name="current_width" select="string-length($current_word) * $size_character"/>
            <!-- The first or last character may be a space, for example after a text with an link. -->
            <xsl:choose>
                <xsl:when test="$current_word = ''">
                    <SP xmlns="http://www.loc.gov/standards/alto/ns-v4#" HPOS="{floor($left)}" VPOS="{floor($top)}" WIDTH="{round($size_character)}"/>
                </xsl:when>
                <xsl:otherwise>
                    <String xmlns="http://www.loc.gov/standards/alto/ns-v4#" HPOS="{floor($left)}" VPOS="{floor($top)}" WIDTH="{round($current_width)}" HEIGHT="{ceiling($height)}" CONTENT="{$current_word}"/>
                    <xsl:if test="$has_space">
                        <SP xmlns="http://www.loc.gov/standards/alto/ns-v4#" HPOS="{floor($left + $current_width)}" VPOS="{floor($top)}" WIDTH="{round($size_character)}"/>
                    </xsl:if>
                </xsl:otherwise>
            </xsl:choose>
            <xsl:call-template name="split">
                <xsl:with-param name="string" select="substring-after($string, ' ')"/>
                <xsl:with-param name="left" select="$left + (string-length($current_word) + $has_space) * $size_character"/>
                <xsl:with-param name="top" select="$top"/>
                <xsl:with-param name="width" select="$width"/>
                <xsl:with-param name="height" select="$height"/>
                <xsl:with-param name="size_character" select="$size_character"/>
            </xsl:call-template>
        </xsl:if>
    </xsl:template>

    <xsl:template name="min_left">
        <xsl:for-each select="@left">
            <xsl:sort select="." data-type="number" order="ascending"/>
            <xsl:if test="position() =1">
                <xsl:value-of select="."/>
            </xsl:if>
        </xsl:for-each>
    </xsl:template>

    <xsl:template name="min_top">
        <xsl:for-each select="@top">
            <xsl:sort select="." data-type="number" order="ascending"/>
            <xsl:if test="position() = 1">
                <xsl:value-of select="."/>
            </xsl:if>
        </xsl:for-each>
    </xsl:template>

    <xsl:template name="max_width">
        <xsl:for-each select="@width">
            <xsl:sort select="." data-type="number" order="descending"/>
            <xsl:if test="position() = 1">
                <xsl:value-of select="."/>
            </xsl:if>
        </xsl:for-each>
    </xsl:template>

    <xsl:template name="max_height">
        <xsl:for-each select="@height">
            <xsl:sort select="." data-type="number" order="descending"/>
            <xsl:if test="position() = 1">
                <xsl:value-of select="."/>
            </xsl:if>
        </xsl:for-each>
    </xsl:template>

</xsl:stylesheet>
