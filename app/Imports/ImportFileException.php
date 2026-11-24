<?php

namespace App\Imports;

use RuntimeException;

/** A problem with the uploaded file as a whole; the message is shown to the person who uploaded it. */
final class ImportFileException extends RuntimeException {}
