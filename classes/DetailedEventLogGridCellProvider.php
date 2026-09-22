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
    public bool $_isCurrentUserAssignedAuthor;

    public function __construct(bool $isCurrentUserAssignedAuthor)
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
     * Render the Date & Time cell
     */
    protected function renderDateCell(EventLogEntry|EmailLogEntry $element): string
    {
        $dateStr = $element instanceof EventLogEntry ? $element->getDateLogged() : $element->dateSent;
        $timestamp = strtotime($dateStr);
        $formattedDate = date('Y-m-d H:i:s', $timestamp);
        $shortDate = date('M j, Y', $timestamp);
        $time = date('H:i', $timestamp);

        $html = '<div class="detailed-log-date-cell">';
        $html .= '<span class="date-full" title="' . htmlspecialchars($formattedDate) . '">' . htmlspecialchars($shortDate) . ' <small class="text-muted">' . htmlspecialchars($time) . '</small></span>';
        $html .= '</div>';

        return $html;
    }

    /**
     * Render the User cell with username and role badges
     */
    protected function renderUserCell(EventLogEntry|EmailLogEntry $element): string
    {
        if ($element instanceof EmailLogEntry) {
            $name = htmlspecialchars($element->senderFullName ?: DetailedLogHelper::translate('plugins.generic.detailedLog.systemUser', [], 'System / Automated'));
            $email = htmlspecialchars($element->senderEmail ?: '');
            return '<div class="detailed-log-user-cell"><strong class="user-fullname">' . $name . '</strong>' .
                ($email ? '<br><small class="text-muted">' . $email . '</small>' : '') . '</div>';
        }

        $userName = $element->getUserFullName();
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
                        $userName = $element->getUserFullName();
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

        $html = '<div class="detailed-log-user-cell">';
        $html .= '<strong class="user-fullname">' . htmlspecialchars($userName) . '</strong>';
        if (!empty($username) && $username !== $userName) {
            $html .= ' <span class="user-username text-muted">(@' . htmlspecialchars($username) . ')</span>';
        }
        if (!empty($userGroup)) {
            $html .= '<div class="user-role-badge"><span class="badge badge-role">' . htmlspecialchars($userGroup) . '</span></div>';
        }
        $html .= '</div>';

        return $html;
    }

    /**
     * Render the Event / Action cell with category icon and badges
     */
    protected function renderEventCell(EventLogEntry|EmailLogEntry $element): string
    {
        $cat = DetailedLogHelper::getEventCategory($element);

        if ($element instanceof EmailLogEntry) {
            $subject = htmlspecialchars($element->prefixedSubject ?: $element->subject);
            return '<div class="detailed-log-event-cell">' .
                '<span class="badge badge-cat ' . $cat['badgeClass'] . '">' . $cat['icon'] . ' ' . htmlspecialchars($cat['name']) . '</span> ' .
                '<span class="event-title">' . $subject . '</span></div>';
        }

        $translated = $element->getTranslatedMessage(null, $this->_isCurrentUserAssignedAuthor);
        $logId = $element->getId();

        $html = '<div class="detailed-log-event-cell">';
        $html .= '<span class="badge badge-cat ' . $cat['badgeClass'] . '">' . $cat['icon'] . ' ' . htmlspecialchars($cat['name']) . '</span> ';
        $html .= '<strong class="event-title">' . htmlspecialchars($translated) . '</strong>';
        $html .= ' <small class="text-muted event-id">#' . (int)$logId . '</small>';
        $html .= '</div>';

        return $html;
    }

    /**
     * Render the Workflow Stage cell
     */
    protected function renderStageCell(EventLogEntry|EmailLogEntry $element): string
    {
        if ($element instanceof EmailLogEntry) {
            return '<span class="badge badge-stage stage-email">' . DetailedLogHelper::translate('plugins.generic.detailedLog.emailNotice', [], 'Notification') . '</span>';
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

        return '<span class="badge badge-stage stage-pill">' . htmlspecialchars($label) . '</span>';
    }

    /**
     * Render the Details cell showing all key parameters from event_log_settings
     */
    protected function renderDetailsCell(EventLogEntry|EmailLogEntry $element): string
    {
        if ($element instanceof EmailLogEntry) {
            $recipients = is_array($element->recipients) ? implode(', ', $element->recipients) : (string)$element->recipients;
            return '<div class="detailed-log-params-cell">' .
                '<span class="param-tag param-email-recipients"><strong>' . DetailedLogHelper::translate('email.to', [], 'To:') . '</strong> ' . htmlspecialchars($recipients) . '</span>' .
                '</div>';
        }

        $highlights = DetailedLogHelper::getStructuredHighlights($element, $this->_isCurrentUserAssignedAuthor);
        $rawSettings = DetailedLogHelper::getRawSettings($element->getId());
        $settingCount = count($rawSettings);

        $items = [];

        // 1. File highlights
        if (!empty($highlights['files'])) {
            $f = $highlights['files'];
            $fileLabel = '';
            if (!empty($f['filename'])) {
                $fileLabel .= '📄 <span class="param-filename" title="' . htmlspecialchars($f['filename']) . '">' . htmlspecialchars($f['filename']) . '</span>';
            }
            if (!empty($f['fileId'])) {
                $fileLabel .= ' <small class="text-muted">(ID: ' . (int)$f['fileId'] . ')</small>';
            }
            if (!empty($f['fileStageLabel'])) {
                $fileLabel .= ' <span class="badge badge-filestage">' . htmlspecialchars($f['fileStageLabel']) . '</span>';
            }
            if ($fileLabel) {
                $items[] = '<div class="param-row param-file">' . $fileLabel . '</div>';
            }
        }

        // 2. Decision highlights
        if (!empty($highlights['decision'])) {
            $d = $highlights['decision'];
            $decFormatted = DetailedLogHelper::formatDecision($d['decision']);
            $decHtml = '<span class="badge ' . $decFormatted['badge'] . '">⚖️ ' . htmlspecialchars($decFormatted['text']) . '</span>';
            if (!empty($d['editorName'])) {
                $decHtml .= ' <small class="text-muted">' . DetailedLogHelper::translate('plugins.generic.detailedLog.byEditor', ['editor' => $d['editorName']], 'by ' . $d['editorName']) . '</small>';
            }
            $items[] = '<div class="param-row param-decision">' . $decHtml . '</div>';
        }

        // 3. Review highlights
        if (!empty($highlights['review'])) {
            $r = $highlights['review'];
            $revHtml = '🔍 <strong>' . htmlspecialchars($r['reviewerName']) . '</strong>';
            if (!empty($r['round'])) {
                $revHtml .= ' <span class="badge badge-round">' . DetailedLogHelper::translate('submission.round', ['round' => $r['round']], "Round {$r['round']}") . '</span>';
            }
            if (!empty($r['reviewAssignmentId'])) {
                $revHtml .= ' <small class="text-muted">(#' . (int)$r['reviewAssignmentId'] . ')</small>';
            }
            $items[] = '<div class="param-row param-review">' . $revHtml . '</div>';
        }

        // 4. Participant highlights
        if (!empty($highlights['participant'])) {
            $p = $highlights['participant'];
            $partHtml = '👤 <strong>' . htmlspecialchars($p['fullName'] ?: $p['username']) . '</strong>';
            if (!empty($p['userGroup'])) {
                $partHtml .= ' &mdash; <span class="badge badge-role">' . htmlspecialchars($p['userGroup']) . '</span>';
            }
            $items[] = '<div class="param-row param-participant">' . $partHtml . '</div>';
        }

        // 5. Email highlights
        if (!empty($highlights['communication'])) {
            $c = $highlights['communication'];
            $commHtml = '✉️ <em>' . htmlspecialchars($c['subject']) . '</em>';
            if (!empty($c['recipient'])) {
                $commHtml .= ' <small class="text-muted">(&rarr; ' . htmlspecialchars($c['recipient']) . ')</small>';
            }
            $items[] = '<div class="param-row param-comm">' . $commHtml . '</div>';
        }

        // Badge indicating total parameters in event_log_settings
        $countBadge = '<span class="badge badge-settings-count" title="' . DetailedLogHelper::translate('plugins.generic.detailedLog.settingsCountTooltip', ['count' => $settingCount], "{$settingCount} settings stored in event_log_settings") . '">' .
            $settingCount . ' ' . DetailedLogHelper::translate('plugins.generic.detailedLog.params', [], 'params') . '</span>';

        if (empty($items)) {
            return '<div class="detailed-log-params-cell">' . $countBadge . '</div>';
        }

        return '<div class="detailed-log-params-cell">' . implode('', $items) . ' <div class="param-meta">' . $countBadge . '</div></div>';
    }
}
