<?php
/**
 * @link      http://craftcms.com/
 * @copyright Copyright (c) Pixel & Tonic, Inc.
 * @license   http://craftcms.com/license
 */

namespace craft\app\web\twig\variables;

use craft\app\helpers\Io;
use craft\app\helpers\Url;

\Craft::$app->requireEdition(\Craft::Client);

/**
 * Class Logo variable.
 *
 * @author Pixel & Tonic, Inc. <support@pixelandtonic.com>
 * @since  3.0
 */
class Logo extends Image
{
    // Public Methods
    // =========================================================================

    /**
     * Return the URL to the logo.
     *
     * @return string|null
     */
    public function getUrl()
    {
        return Url::getResourceUrl('logo/'.Io::getFilename($this->path));
    }
}
