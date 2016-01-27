<?php
/**
 * @link      http://craftcms.com/
 * @copyright Copyright (c) Pixel & Tonic, Inc.
 * @license   http://craftcms.com/license
 */

namespace craft\app\fields;

use craft\app\base\Field;
use craft\app\base\InvalidComponentInterface;
use craft\app\base\InvalidComponentTrait;

/**
 * InvalidField represents a field with an invalid class.
 *
 * @author Pixel & Tonic, Inc. <support@pixelandtonic.com>
 * @since  3.0
 */
class InvalidField extends Field implements InvalidComponentInterface
{
    // Traits
    // =========================================================================

    use InvalidComponentTrait;

    // Public Methods
    // =========================================================================

    /**
     * @inheritdoc
     */
    public static function hasContentColumn()
    {
        return false;
    }

    // Public Methods
    // =========================================================================

    /**
     * @inheritdoc
     */
    public function getInputHtml($value, $element)
    {
        return '<p class="error">'.$this->errorMessage.'</p>';
    }
}
