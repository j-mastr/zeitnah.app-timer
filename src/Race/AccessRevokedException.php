<?php

namespace App\Race;

/** The access code was revoked, or none of the worksets it was restricted to exists any more. */
final class AccessRevokedException extends \RuntimeException
{
}
