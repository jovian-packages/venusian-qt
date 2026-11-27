<?php

namespace Jovian\Toolkits\Qt\UI;

/**
 * A kind whose margin is its own style sheet box (QtSheetMargin): its parent's layout keeps
 * no margin round it. Every other kind's margin is its parent's to keep (QtHeldMargin).
 */
interface QtKeepsOwnMargin {}
