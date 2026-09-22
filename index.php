<?php

/**
 * @file plugins/generic/detailedLog/index.php
 *
 * Copyright (c) 2026
 * Distributed under the GNU GPL v3.
 *
 * @brief Wrapper for Detailed Activity Log plugin for OJS 3.5.
 */

require_once __DIR__ . '/DetailedLogPlugin.php';

return new \APP\plugins\generic\detailedLog\DetailedLogPlugin();
