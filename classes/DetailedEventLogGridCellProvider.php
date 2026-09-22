<?php

/**
 * @file plugins/generic/detailedLog/classes/DetailedEventLogGridCellProvider.php
 *
 * Copyright (c) 2026
 * Distributed under the GNU GPL v3.
 *
 * @class DetailedEventLogGridCellProvider
 * @ingroup plugins_generic_detailedLog
 *
 * @brief Rich Cell provider for detailed submission event log entries displaying
 *        user details, event categories, stages, and full parameters from event_log_settings.
 */

namespace APP\plugins\generic\detailedLog\classes;

use APP\core\Application;
use APP\facades\Repo;
use PKP\controllers\grid\DataObjectGridCellProvider;
use PKP\controllers\grid\GridColumn;
use PKP\log\EmailLogEntry;
use PKP\log\event\EventLogEntry;
use PKP\log\event\PKPSubmissionEventLogEntry;
use PKP\submission\reviewAssignment\ReviewAssignment;
use PKP\submissionFile\SubmissionFile;

class DetailedEventLogGridCellProvider extends DataObjectGridCellProvider
{
    /** @var bool Is the current user assigned as an author to this submission */
    public bool $_isCurrentUserAssignedAuthor = false;

    public function __construct(bool $isCurrentUserAssignedAuthor = false)
    {
        parent::__construct();
        $this->_isCurrentUserAssignedAuthor = $isCurrentUserAssignedAuthor;
    }

    /**
     * Extracts variables for a given column from a data element
     *
     * @param \PKP\controllers\grid\GridRow $row
     * @param GridColumn $column
     *
     * @return array
     */
    public function getTemplateVarsFromRowColumn($row, $column)
    {
        $element = $row->getData();
        $columnId = $column->getId();

        assert(($element instanceof \PKP\core\DataObject || $element instanceof EmailLogEntry) && !empty($columnId));

        return match ($columnId) {
            'date' => ['label' => $this->renderDateCell($element)],
            'user' => ['label' => $this->renderUserCell($element)],
            'event' => ['label' => $this->renderEventCell($element)],
            'stage' => ['label' => $this->renderStageCell($element)],
            'details' => ['label' => $this->renderDetailsCell($element)],
            default => ['label' => ''],
        };
    }

    /**
     * Render the Date & Time cell as plain text
     */
    protected function renderDateCell(EventLogEntry|EmailLogEntry $element): string
    {
        $dateStr = $element instanceof EventLogEntry ? $element->getDateLogged() : $element->dateSent;
        if (!$dateStr) {
            return '';
        }
        $timestamp = strtotime($dateStr);
        return date('Y-m-d H:i:s', $timestamp);
    }

    /**
     * Render the User cell as plain text with username and role
     */
    protected function renderUserCell(EventLogEntry|EmailLogEntry $element): string
    {
        if ($element instanceof EmailLogEntry) {
            $senderInfo = DetailedLogHelper::getEmailSenderInfo($element);
            $userStr = $senderInfo['name'];
            if (!empty($senderInfo['email']) && $senderInfo['email'] !== $senderInfo['name']) {
                $userStr .= ' (' . $senderInfo['email'] . ')';
            }
            return $userStr;
        }

        $userName = null;
        try {
            $userName = $element->getUserFullName();
        } catch (\Throwable $e) {
        }
        $username = $element->getData('username');
        $userGroup = $element->getData('userGroupName');
        if (is_array($userGroup)) {
            $userGroup = current($userGroup);
        }

        // Anonymize reviewer details where necessary
        if ($this->_isCurrentUserAssignedAuthor) {
            $reviewerLogTypes = [
                PKPSubmissionEventLogEntry::SUBMISSION_LOG_REVIEW_ACCEPT,
                PKPSubmissionEventLogEntry::SUBMISSION_LOG_REVIEW_DECLINE,
                PKPSubmissionEventLogEntry::SUBMISSION_LOG_REVIEW_UNCONSIDERED,
                PKPSubmissionEventLogEntry::SUBMISSION_LOG_REVIEW_ASSIGN,
            ];
            if (in_array($element->getEventType(), $reviewerLogTypes)) {
                $userName = DetailedLogHelper::translate('editor.review.anonymousReviewer', [], 'Anonymous Reviewer');
                $username = null;
                $userGroup = DetailedLogHelper::translate('user.role.reviewer', [], 'Reviewer');
                if ($reviewAssignmentId = $element->getData('reviewAssignmentId')) {
                    $reviewAssignment = Repo::reviewAssignment()->get($reviewAssignmentId);
                    if ($reviewAssignment && $reviewAssignment->getReviewMethod() === ReviewAssignment::SUBMISSION_REVIEW_METHOD_OPEN) {
                        try {
                            $userName = $element->getUserFullName();
                        } catch (\Throwable $e) {
                        }
                        $username = $element->getData('username');
                    }
                }
            }

            // Anonymize reviewer files
            $fileStage = $element->getData('fileStage');
            if ($fileStage && $fileStage === SubmissionFile::SUBMISSION_FILE_REVIEW_ATTACHMENT) {
                $submissionFileId = $element->getData('submissionFileId');
                if ($submissionFileId) {
                    $submissionFile = Repo::submissionFile()->get($submissionFileId);
                    if ($submissionFile && $submissionFile->getData('assocType') === Application::ASSOC_TYPE_REVIEW_ASSIGNMENT) {
                        $reviewAssignment = Repo::reviewAssignment()->get($submissionFile->getData('assocId'));
                        if (!$reviewAssignment || in_array($reviewAssignment->getReviewMethod(), [ReviewAssignment::SUBMISSION_REVIEW_METHOD_ANONYMOUS, ReviewAssignment::SUBMISSION_REVIEW_METHOD_DOUBLEANONYMOUS])) {
                            $userName = DetailedLogHelper::translate('editor.review.anonymousReviewer', [], 'Anonymous Reviewer');
                            $username = null;
                            $userGroup = DetailedLogHelper::translate('user.role.reviewer', [], 'Reviewer');
                        }
                    }
                }
            }
        }

        if (empty($userName) && $element->getUserId()) {
            $userDetails = DetailedLogHelper::getUserDetails($element->getUserId());
            if ($userDetails) {
                $userName = $userDetails['fullName'];
                if (empty($username)) {
                    $username = $userDetails['username'];
                }
            }
        }

        if (empty($userName)) {
            $userName = DetailedLogHelper::translate('plugins.generic.detailedLog.systemUser', [], 'System / Automated');
        }

        $userStr = $userName;
        if (!empty($username) && $username !== $userName) {
            $userStr .= ' (@' . $username . ')';
        }
        if (!empty($userGroup)) {
            $userStr .= ' [' . $userGroup . ']';
        }

        return $userStr;
    }

    /**
     * Render the Event / Action cell as plain text
     */
    protected function renderEventCell(EventLogEntry|EmailLogEntry $element): string
    {
        $cat = DetailedLogHelper::getEventCategory($element);

        if ($element instanceof EmailLogEntry) {
            return '[' . $cat['name'] . '] ' . ($element->subject ?: 'Notification');
        }

        $translated = '';
        try {
            $translated = $element->getTranslatedMessage(null, $this->_isCurrentUserAssignedAuthor);
        } catch (\Throwable $e) {
            $translated = (string) $element->getMessage();
        }
        $logId = $element->getId();

        return '[' . $cat['name'] . '] ' . $translated . ' (#' . $logId . ')';
    }

    /**
     * Render the Workflow Stage cell as plain text
     */
    protected function renderStageCell(EventLogEntry|EmailLogEntry $element): string
    {
        if ($element instanceof EmailLogEntry) {
            return DetailedLogHelper::translate('plugins.generic.detailedLog.emailNotice', [], 'Notification');
        }

        $stageId = $element->getData('stageId');
        $round = $element->getData('round');
        $fileStage = $element->getData('fileStage');

        $label = '';
        if ($stageId) {
            $label = DetailedLogHelper::formatStageId((int)$stageId);
            if ($round && $stageId == 3) {
                $label .= ' (' . DetailedLogHelper::translate('submission.round', ['round' => $round], "Round {$round}") . ')';
            }
        } elseif ($fileStage) {
            $label = DetailedLogHelper::formatFileStage((int)$fileStage);
        }

        if (!$label) {
            $label = DetailedLogHelper::translate('plugins.generic.detailedLog.generalWorkflow', [], 'General');
        }

        return $label;
    }

    /**
     * Render the Details cell showing all key parameters from event_log_settings as plain text
     */
    protected function renderDetailsCell(EventLogEntry|EmailLogEntry $element): string
    {
        if ($element instanceof EmailLogEntry) {
            $recipients = is_array($element->recipients) ? implode(', ', $element->recipients) : (string)$element->recipients;
            return DetailedLogHelper::translate('email.to', [], 'To:') . ' ' . $recipients;
        }

        $highlights = DetailedLogHelper::getStructuredHighlights($element, $this->_isCurrentUserAssignedAuthor);
        $rawSettings = DetailedLogHelper::getRawSettings($element->getId());
        $settingCount = count($rawSettings);

        $parts = [];

        // 1. File highlights
        if (!empty($highlights['files'])) {
            $f = $highlights['files'];
            $fileParts = [];
            if (!empty($f['filename'])) {
                $fileParts[] = $f['filename'];
            }
            if (!empty($f['fileId'])) {
                $fileParts[] = 'ID: ' . $f['fileId'];
            }
            if (!empty($f['fileStageLabel'])) {
                $fileParts[] = $f['fileStageLabel'];
            }
            if (!empty($fileParts)) {
                $parts[] = 'File: ' . implode(', ', $fileParts);
            }
        }

        // 2. Decision highlights
        if (!empty($highlights['decision'])) {
            $d = $highlights['decision'];
            $decFormatted = DetailedLogHelper::formatDecision($d['decision']);
            $decText = 'Decision: ' . $decFormatted['text'];
            if (!empty($d['editorName'])) {
                $decText .= ' ' . DetailedLogHelper::translate('plugins.generic.detailedLog.byEditor', ['editor' => $d['editorName']], 'by ' . $d['editorName']);
            }
            $parts[] = $decText;
        }

        // 3. Review highlights
        if (!empty($highlights['review'])) {
            $r = $highlights['review'];
            $revText = 'Reviewer: ' . $r['reviewerName'];
            if (!empty($r['round'])) {
                $revText .= ' (' . DetailedLogHelper::translate('submission.round', ['round' => $r['round']], "Round {$r['round']}") . ')';
            }
            if (!empty($r['reviewAssignmentId'])) {
                $revText .= ' [#' . $r['reviewAssignmentId'] . ']';
            }
            $parts[] = $revText;
        }

        // 4. Participant highlights
        if (!empty($highlights['participant'])) {
            $p = $highlights['participant'];
            $partText = 'User: ' . ($p['fullName'] ?: $p['username']);
            if (!empty($p['userGroup'])) {
                $partText .= ' (' . $p['userGroup'] . ')';
            }
            $parts[] = $partText;
        }

        // 5. Email communication highlights
        if (!empty($highlights['communication'])) {
            $c = $highlights['communication'];
            $commText = 'Subject: ' . $c['subject'];
            if (!empty($c['recipient'])) {
                $commText .= ' -> ' . $c['recipient'];
            }
            $parts[] = $commText;
        }

        // 6. Contributor highlights
        if (!empty($highlights['contributor'])) {
            $co = $highlights['contributor'];
            $coText = 'Contributor: ' . $co['authorName'];
            if (!empty($co['email'])) {
                $coText .= ' <' . $co['email'] . '>';
            }
            $parts[] = $coText;
        }

        // 7. Discussion / Note highlights
        if (!empty($highlights['discussion'])) {
            $disc = $highlights['discussion'];
            $discText = 'Note by ' . ($disc['author'] ?: 'User');
            if (!empty($disc['title'])) {
                $discText .= ': ' . $disc['title'];
            } elseif (!empty($disc['excerpt'])) {
                $discText .= ': ' . $disc['excerpt'];
            }
            $parts[] = $discText;
        }

        // 8. Database activity highlights
        if (!empty($highlights['database'])) {
            $db = $highlights['database'];
            $dbText = $db['tableName'] . ' [' . $db['operation'] . ']';
            if (!empty($db['fields'])) {
                $fieldSummary = [];
                foreach ($db['fields'] as $fn => $fv) {
                    $fvStr = (string)$fv;
                    if (mb_strlen($fvStr) > 35) {
                        $fvStr = mb_substr($fvStr, 0, 32) . '...';
                    }
                    $fieldSummary[] = "{$fn}={$fvStr}";
                }
                $dbText .= ' (' . implode(', ', array_slice($fieldSummary, 0, 3)) . ')';
            }
            $parts[] = $dbText;
        }

        // If no structured highlights were extracted, show key raw settings
        if (empty($parts) && !empty($rawSettings)) {
            $settingPairs = [];
            foreach ($rawSettings as $s) {
                if ($s['value'] !== null && $s['value'] !== '') {
                    $settingPairs[] = $s['name'] . ': ' . $s['value'];
                }
            }
            if (!empty($settingPairs)) {
                $parts[] = implode(', ', array_slice($settingPairs, 0, 3));
            }
        }

        if ($settingCount > 0) {
            $parts[] = '[' . $settingCount . ' ' . DetailedLogHelper::translate('plugins.generic.detailedLog.params', [], 'params') . ']';
        }

        return implode(' | ', $parts);
    }
}
