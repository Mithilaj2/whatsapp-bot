<?php

namespace App\Services\WhatsApp;

use RuntimeException;

/**
 * A status webhook for a wamid we have not stored yet, usually because it
 * arrived before the send job saved the id. The job retries later.
 */
class UnknownMessageStatus extends RuntimeException {}
