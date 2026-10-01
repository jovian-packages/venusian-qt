<?php

namespace Jovian\Toolkits\Qt\Bridge;

use Surface\Bridge\BridgedToolkitSession;
use Surface\Bridge\ToolkitBridgeDriver;
use Jovian\Toolkits\Qt\Contracts\Bridge\QtBridgeDriver as BridgeContract;

class QtBridgeDriver extends ToolkitBridgeDriver implements BridgeContract
{

    public function connect(): BridgedToolkitSession
    {
        // TODO: Implement connect() method.
    }
}