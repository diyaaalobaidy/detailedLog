<?php

/**
 * @file plugins/generic/detailedLog/DetailedLogPlugin.php
 *
 * Copyright (c) 2026
 * Distributed under the GNU GPL v3.
 *
 * @class DetailedLogPlugin
 * @ingroup plugins_generic_detailedLog
 *
 * @brief Main plugin class for the Detailed Activity Log plugin for OJS 3.5.
 *        Shows the complete detailed activity log for articles with all parameters
 *        from event_log_settings.
 */

namespace APP\plugins\generic\detailedLog;

use APP\core\Application;
use APP\plugins\generic\detailedLog\classes\DetailedLogHelper;
use APP\plugins\generic\detailedLog\controllers\grid\DetailedSubmissionEventLogGridHandler;
use APP\plugins\generic\detailedLog\controllers\grid\DetailedSubmissionFileEventLogGridHandler;
use APP\template\TemplateManager;
use Illuminate\Support\Facades\DB;
use PKP\core\JSONMessage;
use PKP\linkAction\LinkAction;
use PKP\linkAction\request\AjaxModal;
use PKP\plugins\GenericPlugin;
use PKP\plugins\Hook;

class DetailedLogPlugin extends GenericPlugin
{
    /**
     * @copydoc Plugin::getDisplayName()
     */
    public function getDisplayName()
    {
        return DetailedLogHelper::translate('plugins.generic.detailedLog.displayName', [], 'Detailed Activity Log Plugin');
    }

    /**
     * @copydoc Plugin::getDescription()
     */
    public function getDescription()
    {
        return DetailedLogHelper::translate('plugins.generic.detailedLog.description', [], 'Displays full detailed audit logs for submissions including all parameters, files, decisions, review rounds, and stages from the event_log_settings table.');
    }

    /**
     * Site-wide plugin mandatory on all journals
     *
     * @copydoc Plugin::isSitePlugin()
     */
    public function isSitePlugin()
    {
        return true;
    }

    /**
     * Mandatory plugin: Always enabled across all journals
     *
     * @copydoc LazyLoadPlugin::getEnabled()
     */
    public function getEnabled($contextId = null)
    {
        return true;
    }

    /**
     * Mandatory plugin: Cannot be disabled
     *
     * @copydoc LazyLoadPlugin::setEnabled()
     */
    public function setEnabled($enabled)
    {
        // Mandatory plugin: Cannot be disabled
    }

    /**
     * @copydoc Plugin::getCanEnable()
     */
    public function getCanEnable()
    {
        return false;
    }

    /**
     * @copydoc Plugin::getCanDisable()
     */
    public function getCanDisable()
    {
        return false;
    }

    /**
     * @copydoc Plugin::register()
     */
    public function register($category, $path, $mainContextId = null)
    {
        if (parent::register($category, $path, $mainContextId)) {
            $localePath = __DIR__ . '/locale';
            if (is_dir($localePath)) {
                \PKP\facades\Locale::registerPath($localePath);
            }

            // Mandatory audit plugin: Always active across all contexts/journals
            classes\DetailedActivityRecorder::init();

            // Intercept component routing for Submission Event Log grids
            Hook::add('LoadComponentHandler', $this->handleComponentRouting(...));

            // Add stylesheet to backend templates
            Hook::add('TemplateManager::display', $this->setupStylesheet(...));

            // Fallback hook if grid is initialized directly
            Hook::add('submissioneventloggridhandler::initfeatures', $this->handleGridFeatures(...));

            return true;
        }
        return false;
    }

    /**
     * Intercept component requests to replace standard event log grid with our detailed handler
     */
    public function handleComponentRouting(string $hookName, array $params): bool
    {
        $component = &$params[0];
        $op = &$params[1];
        $componentInstance = &$params[2];

        if ($component === 'grid.eventLog.SubmissionEventLogGridHandler') {
            $componentInstance = new DetailedSubmissionEventLogGridHandler($this);
            return Hook::ABORT;
        }

        if ($component === 'grid.eventLog.SubmissionFileEventLogGridHandler') {
            $componentInstance = new DetailedSubmissionFileEventLogGridHandler($this);
            return Hook::ABORT;
        }

        if ($component === 'plugins.generic.detailedLog.controllers.grid.DetailedSubmissionEventLogGridHandler') {
            $componentInstance = new DetailedSubmissionEventLogGridHandler($this);
            return Hook::ABORT;
        }

        if ($component === 'plugins.generic.detailedLog.controllers.grid.DetailedSubmissionFileEventLogGridHandler') {
            $componentInstance = new DetailedSubmissionFileEventLogGridHandler($this);
            return Hook::ABORT;
        }

        return Hook::CONTINUE;
    }

    /**
     * Inject custom CSS stylesheet into the template manager
     */
    public function setupStylesheet(string $hookName, array $args): bool
    {
        $templateMgr = $args[0]; /** @var TemplateManager $templateMgr */
        $request = Application::get()->getRequest();

        $templateMgr->addStyleSheet(
            'detailedLogPluginCss',
            $request->getBaseUrl() . '/' . $this->getPluginPath() . '/css/detailedLog.css',
            ['contexts' => ['backend']]
        );

        return Hook::CONTINUE;
    }

    /**
     * Grid features callback fallback
     */
    public function handleGridFeatures(string $hookName, array $args): bool
    {
        $grid = $args[0];
        $request = $args[1];

        // Ensure stylesheet is added when grid is rendered
        $templateMgr = TemplateManager::getManager($request);
        $templateMgr->addStyleSheet(
            'detailedLogPluginCss',
            $request->getBaseUrl() . '/' . $this->getPluginPath() . '/css/detailedLog.css',
            ['contexts' => ['backend']]
        );

        return Hook::CONTINUE;
    }

    /**
     * @copydoc Plugin::getActions()
     */
    public function getActions($request, $actionArgs)
    {
        $actions = parent::getActions($request, $actionArgs);
        if (!$this->getEnabled()) {
            return $actions;
        }

        $router = $request->getRouter();
        $actions[] = new LinkAction(
            'detailedLogStats',
            new AjaxModal(
                $router->url($request, null, null, 'manage', null, ['verb' => 'stats', 'plugin' => $this->getName(), 'category' => 'generic']),
                DetailedLogHelper::translate('plugins.generic.detailedLog.displayName', [], 'Detailed Activity Log Plugin'),
                'modal_information'
            ),
            DetailedLogHelper::translate('common.information', [], 'Information'),
            'information'
        );

        return $actions;
    }

    /**
     * @copydoc Plugin::manage()
     */
    public function manage($args, $request)
    {
        $verb = $args['verb'] ?? null;
        if ($verb === 'disable') {
            return new JSONMessage(false, DetailedLogHelper::translate('plugins.generic.detailedLog.cannotBeDisabled', [], 'This plugin is mandatory across all journals and cannot be disabled.'));
        }

        if ($verb === 'stats') {
            $totalEvents = DB::table('event_log')->count();
            $totalSettings = DB::table('event_log_settings')->count();
            $distinctSettingNames = DB::table('event_log_settings')->distinct('setting_name')->count('setting_name');

            $html = '<div style="padding: 15px; font-size: 0.95em; line-height: 1.6;">';
            $html .= '<h3 style="margin-top: 0;">🔍 ' . htmlspecialchars($this->getDisplayName()) . '</h3>';
            $html .= '<p>' . htmlspecialchars($this->getDescription()) . '</p>';
            $html .= '<div style="margin: 10px 0; padding: 8px 12px; background: #e0f2fe; border: 1px solid #bae6fd; border-radius: 6px; color: #0369a1; font-weight: 600; font-size: 0.9em;">🔒 Mandatory Site-Wide Audit Plugin &mdash; Permanently active on all journals</div>';
            $html .= '<hr style="border: none; border-top: 1px solid #e2e8f0; margin: 15px 0;">';
            $html .= '<div style="display: grid; grid-template-columns: repeat(3, 1fr); gap: 12px; margin-bottom: 15px;">';
            $html .= '<div style="background: #f8fafc; border: 1px solid #e2e8f0; border-radius: 6px; padding: 12px; text-align: center;">';
            $html .= '<div style="font-size: 1.6em; font-weight: 700; color: #1e40af;">' . number_format($totalEvents) . '</div>';
            $html .= '<small style="color: #64748b;">Total Event Log Entries</small>';
            $html .= '</div>';
            $html .= '<div style="background: #f8fafc; border: 1px solid #e2e8f0; border-radius: 6px; padding: 12px; text-align: center;">';
            $html .= '<div style="font-size: 1.6em; font-weight: 700; color: #166534;">' . number_format($totalSettings) . '</div>';
            $html .= '<small style="color: #64748b;">Total Settings in event_log_settings</small>';
            $html .= '</div>';
            $html .= '<div style="background: #f8fafc; border: 1px solid #e2e8f0; border-radius: 6px; padding: 12px; text-align: center;">';
            $html .= '<div style="font-size: 1.6em; font-weight: 700; color: #92400e;">' . number_format($distinctSettingNames) . '</div>';
            $html .= '<small style="color: #64748b;">Distinct Setting Types</small>';
            $html .= '</div>';
            $html .= '</div>';
            $html .= '<p style="color: #15803d; font-weight: 600;">✓ Universal Audit Recorder is active. Intercepts all database queries, domain lifecycle events, real client IPs, and HTTP request payloads.</p>';
            $html .= '</div>';

            return new JSONMessage(true, $html);
        }

        return parent::manage($args, $request);
    }
}
