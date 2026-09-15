<?xml version="1.0" encoding="UTF-8"?>
<!--
    Extract plain text from an alto file (v4).

    @see http://www.loc.gov/standards/alto

    @copyright Daniel Berthereau, 2023
    @license CeCILL 2.1 https://cecill.info/licences/Licence_CeCILL_V2.1-fr.txt

    @version 0.1.0
-->

<xsl:stylesheet version="1.0"
    xmlns:xsl="http://www.w3.org/1999/XSL/Transform"

    xmlns:alto="http://www.loc.gov/standards/alto/ns-v4#"
    xmlns:xlink="http://www.w3.org/1999/xlink"
    xmlns:xsi="http://www.w3.org/2001/XMLSchema-instance"
    >

    <xsl:output method="text" encoding="UTF-8"/>

    <!-- Pages. -->
    <xsl:template match="/alto:alto/alto:Layout/alto:Page">
        <xsl:apply-templates select="descendant::alto:TextLine"/>
        <xsl:if test="position() != last()">
            <xsl:text>&#x0A;</xsl:text>
        </xsl:if>
    </xsl:template>

    <!-- Lignes. -->
    <xsl:template match="alto:TextLine">
        <xsl:apply-templates select="descendant::alto:String | descendant::alto:SP"/>
        <xsl:if test="position() != last()">
            <xsl:text>&#x0A;</xsl:text>
        </xsl:if>
    </xsl:template>

    <xsl:template match="alto:String">
        <xsl:value-of select="@CONTENT"/>
    </xsl:template>

    <xsl:template match="alto:SP">
        <xsl:text> </xsl:text>
    </xsl:template>

    <xsl:template match="text()">
    </xsl:template>

</xsl:stylesheet>
