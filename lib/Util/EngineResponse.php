<?php

namespace OCA\X2Mail\Util;

class EngineResponse extends \OCP\AppFramework\Http\Response
{
    public function render(): string
    {
        $data = '';
        $i = \ob_get_level();
        while ($i--) {
            $data .= \ob_get_clean();
        }
        return $data;
    }
}
