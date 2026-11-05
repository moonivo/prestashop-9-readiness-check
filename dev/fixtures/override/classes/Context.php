<?php
/**
 * Test fixture: an override that redefines a core method without the return type
 * PrestaShop 9 added (Context::isMobile(): bool). Not shipped.
 */
class Context extends ContextCore
{
    public function isMobile()
    {
        return parent::isMobile();
    }
}
