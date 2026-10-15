<?php

return [
    // invalid credentials share one authenticated-owner window across scan and manual entry
    'max_invalid_attempts' => (int) env('PASS_LOOKUP_MAX_INVALID_ATTEMPTS', 10),
    'decay_seconds' => (int) env('PASS_LOOKUP_DECAY_SECONDS', 60),
];
