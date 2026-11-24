<?php

namespace App\Support;

use RuntimeException;

/** A photo that passed the type and size checks but could not be decoded or re-encoded. */
final class PhotoNotProcessable extends RuntimeException {}
