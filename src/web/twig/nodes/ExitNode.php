<?php
/**
 * @link      http://craftcms.com/
 * @copyright Copyright (c) Pixel & Tonic, Inc.
 * @license   http://craftcms.com/license
 */

namespace craft\app\web\twig\nodes;

/**
 * Class ExitNode
 *
 * @author Pixel & Tonic, Inc. <support@pixelandtonic.com>
 * @since  3.0
 */
class ExitNode extends \Twig_Node
{
    // Public Methods
    // =========================================================================

    /**
     * @inheritdoc
     */
    public function compile(\Twig_Compiler $compiler)
    {
        $compiler->addDebugInfo($this);

        if ($status = $this->getNode('status')) {
            $compiler
                ->write('throw new \craft\app\errors\HttpException(')
                ->subcompile($status)
                ->raw(");\n");
        } else {
            $compiler->write("\\Craft::\$app->end();\n");
        }
    }
}
