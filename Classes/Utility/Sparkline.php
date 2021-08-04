<?php

namespace Flowpack\DecoupledContentStore\Utility;
// taken and adapted from https://raw.githubusercontent.com/jxxe/sparkline/master/sparkline.php
class Sparkline
{
    /**
     * Generate SVG sparklines with PHP
     * @author Jerome Paulos
     * @version 1.0.0
     * @license MIT
     * @link https://github.com/jxxe/sparkline/
     */
    private static function getY($max, $height, $diff, $value)
    {
        return round(floatval(($height - ($value * $height / $max) + $diff)), 2);
    }

    private static function buildElement($tag, $attrs)
    {
        $element = '<' . $tag . ' ';
        foreach ($attrs as $attr => $value) {
            $element .= $attr . '="' . $value . '"';
        }
        $element .= '></' . $tag . '>';
        return $element;
    }

    public static function sparkline($svgClass, $values, $lineColor = 'red', $fillColor = 'none', $options = null): string
    {
        if (count($values) <= 1) {
            return '';
        }
        $options = $options ?? ['strokeWidth' => 3, 'width' => 100, 'height' => 30,];
        $svg = '<svg style="width:' . $options['width'] . 'px;height:' . $options['height'] . 'px" class="' . $svgClass . '">';
        $strokeWidth = $options['strokeWidth'];
        $width = $options['width'];
        $fullHeight = $options['height'];
        $height = $fullHeight - ($strokeWidth * 2);
        $max = max($values);
        $lastItemIndex = count($values) - 1;
        $offset = $width / $lastItemIndex;
        $datapoints = [];
        $pathY = self::getY($max, $height, $strokeWidth, $values[0]);
        $pathCoords = "M 0 {$pathY}";
        foreach ($values as $index => $value) {
            $x = $index * $offset;
            $y = self::getY($max, $height, $strokeWidth, $value);
            $datapoints[$index] = ['index' => $index, 'x' => $x, 'y' => $y];
            $pathCoords .= " L {$x} {$y}";
        }
        $path = self::buildElement('path', ['class' => 'sparkline--line', 'd' => $pathCoords, 'fill' => 'none', 'stroke-width' => $strokeWidth, 'stroke' => $lineColor]);
        $fillCoords = "{$pathCoords} V {$fullHeight} L 0 {$fullHeight} Z";
        $fill = self::buildElement('path', ['class' => 'sparkline--fill', 'd' => $fillCoords, 'stroke' => 'none', 'fill' => $fillColor]);
        $svg .= $fill;
        $svg .= $path;
        $svg .= '</svg>';
        return '<!-- Generated with https://github.com/jxxe/sparkline/ -->' . $svg;
    }

}