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
     * @copydoc GridRow::initialize()
     */
    public function initialize($request, $template = null)
    {
        parent::initialize($request, $template);

        $logEntry = $this->getData();
        assert($logEntry != null && ($logEntry instanceof EventLogEntry || $logEntry instanceof EmailLogEntry));

        $router = $request->getRouter();
        $submission = $this->getSubmission();

        if ($logEntry instanceof EventLogEntry) {
            $actionArgs = [
                'submissionId' => $submission->getId(),
                'logId' => $logEntry->getId(),
            ];

            // 1. Add the "View Full Details" modal link action
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

            // 2. Download action for file uploads / revisions
            switch ($logEntry->getEventType()) {
                case SubmissionFileEventLogEntry::SUBMISSION_LOG_FILE_REVISION_UPLOAD:
                case SubmissionFileEventLogEntry::SUBMISSION_LOG_FILE_UPLOAD:
                    $submissionFileId = $logEntry->getData('submissionFileId');
                    $fileId = $logEntry->getData('fileId');
                    $submissionFile = $submissionFileId ? Repo::submissionFile()->get($submissionFileId) : null;
                    if (!$submissionFile) {
                        break;
                    }
                    $filename = $logEntry->getLocalizedData('filename') ?? $submissionFile->getLocalizedData('name');
                    if ($submissionFile) {
                        $anonymousAuthor = false;
                        $maybeAnonymousAuthor = $this->_isCurrentUserAssignedAuthor && $submissionFile->getData('fileStage') === SubmissionFile::SUBMISSION_FILE_REVIEW_ATTACHMENT;
                        if ($maybeAnonymousAuthor && $submissionFile->getData('assocType') === Application::ASSOC_TYPE_REVIEW_ASSIGNMENT) {
                            $reviewAssignment = Repo::reviewAssignment()->get($submissionFile->getData('assocId'));
                            if ($reviewAssignment && in_array($reviewAssignment->getReviewMethod(), [ReviewAssignment::SUBMISSION_REVIEW_METHOD_ANONYMOUS, ReviewAssignment::SUBMISSION_REVIEW_METHOD_DOUBLEANONYMOUS])) {
                                $anonymousAuthor = true;
                            }
                        }
                        if (!$anonymousAuthor) {
                            $workflowStageId = Repo::submissionFile()->getWorkflowStageId($submissionFile);
                            if ($workflowStageId || $submissionFile->getData('fileStage') != SubmissionFile::SUBMISSION_FILE_QUERY) {
                                $this->addAction(new DownloadFileLinkAction($request, $submissionFile, $workflowStageId, __('common.download'), $fileId, $filename));
                            }
                        }
                    }
                    break;
            }
        } elseif ($logEntry instanceof EmailLogEntry) {
            $this->addAction(
                new EmailLinkAction(
                    $request,
                    __('submission.event.viewEmail'),
                    [
                        'submissionId' => $logEntry->assocId,
                        'emailLogEntryId' => $logEntry->id,
                    ]
                )
            );
        }
    }
}
