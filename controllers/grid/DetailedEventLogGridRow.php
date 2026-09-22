<?php

/**
 * @file plugins/generic/detailedLog/controllers/grid/DetailedEventLogGridRow.php
 *
 * Copyright (c) 2026
 * Distributed under the GNU GPL v3.
 *
 * @class DetailedEventLogGridRow
 * @ingroup plugins_generic_detailedLog
 *
 * @brief Detailed EventLog grid row definition adding the "View Details" modal action
 *        and file download actions.
 */

namespace APP\plugins\generic\detailedLog\controllers\grid;

use APP\core\Application;
use APP\facades\Repo;
use APP\plugins\generic\detailedLog\classes\DetailedLogHelper;
use APP\submission\Submission;
use PKP\controllers\api\file\linkAction\DownloadFileLinkAction;
use PKP\controllers\grid\eventLog\EventLogGridRow;
use PKP\controllers\grid\eventLog\linkAction\EmailLinkAction;
use PKP\linkAction\LinkAction;
use PKP\linkAction\request\AjaxModal;
use PKP\log\EmailLogEntry;
use PKP\log\event\EventLogEntry;
use PKP\log\event\SubmissionFileEventLogEntry;
use PKP\submission\reviewAssignment\ReviewAssignment;
use PKP\submissionFile\SubmissionFile;

class DetailedEventLogGridRow extends EventLogGridRow
{
    /**
     * Get the submission associated with this row
     */
    public function getSubmission(): ?Submission
    {
        return $this->_submission;
    }

    /**
     * @copydoc GridRow::initialize()
     */
    public function initialize($request, $template = null)
    {
        parent::initialize($request, $template);

        $logEntry = $this->getData();
        if (!$logEntry || !($logEntry instanceof EventLogEntry)) {
            return;
        }

        $router = $request->getRouter();
        $submission = $this->getSubmission();
        $submissionId = $submission ? $submission->getId() : (int) ($request->getUserVar('submissionId') ?: $logEntry->getData('submissionId'));
        if (!$submissionId && $logEntry->getAssocType() == Application::ASSOC_TYPE_SUBMISSION) {
            $submissionId = (int) $logEntry->getAssocId();
        }

        $actionArgs = [
            'submissionId' => $submissionId,
            'logId' => $logEntry->getId(),
        ];

        // Add the "View Full Details" modal link action
        $this->addAction(
            new LinkAction(
                'viewLogDetails',
                new AjaxModal(
                    $router->url($request, null, null, 'viewLogDetails', null, $actionArgs),
                    DetailedLogHelper::translate('plugins.generic.detailedLog.viewDetailsTitle', ['id' => $logEntry->getId()], "Activity Log Details - #{$logEntry->getId()}"),
                    'modal_information'
                ),
                DetailedLogHelper::translate('plugins.generic.detailedLog.viewDetails', [], 'View Details'),
                'information'
            )
        );
    }
}
