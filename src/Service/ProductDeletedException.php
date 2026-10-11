<?php

namespace ProductImport\Service;

/** The product behind a link no longer exists; the link has become a tombstone. */
class ProductDeletedException extends \RuntimeException
{
}
