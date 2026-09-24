<?php

namespace App\Regatta;

/** Another writer changed the regatta between our read and our write; the caller retries. */
final class ConcurrentModificationException extends \RuntimeException
{
}
