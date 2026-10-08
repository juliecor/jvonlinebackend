<?php

namespace App\Support\Assistant;

use RuntimeException;

/** The assistant can't answer (no API key, OpenAI down or out of credit); the message is safe to show. */
class AssistantUnavailable extends RuntimeException {}
