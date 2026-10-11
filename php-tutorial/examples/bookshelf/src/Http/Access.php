<?php

declare(strict_types=1);

namespace Bookshelf\Http;

/** Who may use a route. */
enum Access
{
    case Public;
    case Member;
    case Staff;
}
