<?php
/**
 * @link      http://craftcms.com/
 * @copyright Copyright (c) Pixel & Tonic, Inc.
 * @license   http://craftcms.com/license
 */

namespace craft\app\helpers;

/**
 * HtmlPurifier provides an ability to clean up HTML from any harmful code.
 *
 * @author Pixel & Tonic, Inc. <support@pixelandtonic.com>
 * @since  3.0
 */
class HtmlPurifier extends \yii\helpers\HtmlPurifier
{
    public static function cleanUtf8($string)
    {
        return \HTMLPurifier_Encoder::cleanUTF8($string);
    }

    public static function convertToUtf8($string, $config)
    {
        return \HTMLPurifier_Encoder::convertToUTF8($string, $config, null);
    }
}
