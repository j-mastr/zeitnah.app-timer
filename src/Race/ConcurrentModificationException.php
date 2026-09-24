<?php

namespace App\Race;

/** Another writer changed the race between our read and our write; the caller retries. */
final class ConcurrentModificationException extends \RuntimeException
{
}
