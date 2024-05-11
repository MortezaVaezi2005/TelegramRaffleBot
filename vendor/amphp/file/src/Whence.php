<?php declare(strict_types=1);

namespace Amp\File;

enum Whence
{
    /**
     * Set position equal to offset bytes.
     */
    case Start;

    /**
     * Set position to current location plus offset.
     */
    case Current;

    /**
     * Set position to end-of-file plus offset.
     */
    case End;
}
