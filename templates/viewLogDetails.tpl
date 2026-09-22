{**
 * plugins/generic/detailedLog/templates/viewLogDetails.tpl
 *
 * Copyright (c) 2026
 * Distributed under the GNU GPL v3.
 *
 * Detailed Activity Log Entry Modal displaying all metadata and event_log_settings.
 *}

<div class="pkp_detailed_log_modal">
	{* Header Banner *}
	<div class="detailed-log-header">
		<div class="header-left">
			<span class="badge badge-cat {$eventCategory.badgeClass}">{$eventCategory.icon} {$eventCategory.name|escape}</span>
			<h3 class="event-main-title">{$translatedMessage|escape}</h3>
			<div class="event-meta-line">
				<span class="meta-item"><strong>{translate key="plugins.generic.detailedLog.logId"}:</strong> #{$logId|escape}</span>
				<span class="meta-separator">&bull;</span>
				<span class="meta-item"><strong>{translate key="common.date"}:</strong> {$logEntry->getDateLogged()|escape}</span>
				<span class="meta-separator">&bull;</span>
				<span class="meta-item"><strong>{translate key="common.user"}:</strong>
					{if $logEntry->getUserFullName()}
						{$logEntry->getUserFullName()|escape}
						{if $userDetails.username}(@{$userDetails.username|escape}){/if}
					{else}
						<em>{translate key="plugins.generic.detailedLog.systemUser"}</em>
					{/if}
				</span>
				{if $realIp}
					<span class="meta-separator">&bull;</span>
					<span class="meta-item"><strong>{translate key="plugins.generic.detailedLog.setting.realIp"}:</strong> <span class="font-mono font-bold">{$realIp|escape}</span></span>
				{/if}
			</div>
		</div>
	</div>

	{* Key Highlights Cards *}
	<div class="detailed-log-highlights">
		{* 1. File Details Card *}
		{if $highlights.files && ($highlights.files.filename || $highlights.files.fileId)}
			<div class="highlight-card card-file">
				<div class="card-header">
					<span class="card-icon">📁</span>
					<h4>{translate key="plugins.generic.detailedLog.card.fileDetails"}</h4>
				</div>
				<div class="card-body">
					<div class="highlight-grid">
						{if $highlights.files.filename}
							<div class="highlight-item highlight-item-full">
								<span class="item-label">{translate key="plugins.generic.detailedLog.setting.filename"}:</span>
								<span class="item-value font-bold">{$highlights.files.filename|escape}</span>
							</div>
						{/if}
						{if $highlights.files.fileId}
							<div class="highlight-item">
								<span class="item-label">{translate key="plugins.generic.detailedLog.setting.fileId"}:</span>
								<span class="item-value font-mono">{$highlights.files.fileId|escape}</span>
							</div>
						{/if}
						{if $highlights.files.submissionFileId}
							<div class="highlight-item">
								<span class="item-label">{translate key="plugins.generic.detailedLog.setting.submissionFileId"}:</span>
								<span class="item-value font-mono">{$highlights.files.submissionFileId|escape}</span>
							</div>
						{/if}
						{if $highlights.files.fileStageLabel}
							<div class="highlight-item">
								<span class="item-label">{translate key="plugins.generic.detailedLog.setting.fileStage"}:</span>
								<span class="item-value"><span class="badge badge-filestage">{$highlights.files.fileStageLabel|escape}</span></span>
							</div>
						{/if}
						{if $highlights.files.sourceFileId}
							<div class="highlight-item">
								<span class="item-label">{translate key="plugins.generic.detailedLog.setting.sourceSubmissionFileId"}:</span>
								<span class="item-value font-mono">{$highlights.files.sourceFileId|escape}</span>
							</div>
						{/if}
					</div>
				</div>
			</div>
		{/if}

		{* 2. Editorial Decision Card *}
		{if $highlights.decision && $highlights.decision.decision}
			<div class="highlight-card card-decision">
				<div class="card-header">
					<span class="card-icon">⚖️</span>
					<h4>{translate key="plugins.generic.detailedLog.card.decisionDetails"}</h4>
				</div>
				<div class="card-body">
					<div class="highlight-grid">
						<div class="highlight-item highlight-item-full">
							<span class="item-label">{translate key="plugins.generic.detailedLog.setting.decision"}:</span>
							<span class="item-value font-bold highlight-decision-badge">{$highlights.decision.decision|escape}</span>
						</div>
						{if $highlights.decision.editorName}
							<div class="highlight-item">
								<span class="item-label">{translate key="plugins.generic.detailedLog.setting.editorName"}:</span>
								<span class="item-value">{$highlights.decision.editorName|escape}</span>
							</div>
						{/if}
						{if $highlights.decision.editorId}
							<div class="highlight-item">
								<span class="item-label">{translate key="plugins.generic.detailedLog.setting.editorId"}:</span>
								<span class="item-value font-mono">#{$highlights.decision.editorId|escape}</span>
							</div>
						{/if}
					</div>
				</div>
			</div>
		{/if}

		{* 3. Peer Review Card *}
		{if $highlights.review && ($highlights.review.reviewerName || $highlights.review.round)}
			<div class="highlight-card card-review">
				<div class="card-header">
					<span class="card-icon">🔍</span>
					<h4>{translate key="plugins.generic.detailedLog.card.reviewDetails"}</h4>
				</div>
				<div class="card-body">
					<div class="highlight-grid">
						{if $highlights.review.reviewerName}
							<div class="highlight-item">
								<span class="item-label">{translate key="plugins.generic.detailedLog.setting.reviewerName"}:</span>
								<span class="item-value font-bold">{$highlights.review.reviewerName|escape}</span>
							</div>
						{/if}
						{if $highlights.review.round}
							<div class="highlight-item">
								<span class="item-label">{translate key="plugins.generic.detailedLog.setting.round"}:</span>
								<span class="item-value"><span class="badge badge-round">{translate key="submission.round" round=$highlights.review.round}</span></span>
							</div>
						{/if}
						{if $highlights.review.reviewAssignmentId}
							<div class="highlight-item">
								<span class="item-label">{translate key="plugins.generic.detailedLog.setting.reviewAssignmentId"}:</span>
								<span class="item-value font-mono">#{$highlights.review.reviewAssignmentId|escape}</span>
							</div>
						{/if}
						{if $highlights.review.reviewDueDate}
							<div class="highlight-item">
								<span class="item-label">{translate key="plugins.generic.detailedLog.setting.reviewDueDate"}:</span>
								<span class="item-value">{$highlights.review.reviewDueDate|escape}</span>
							</div>
						{/if}
					</div>
				</div>
			</div>
		{/if}

		{* 4. Participant Card *}
		{if $highlights.participant && ($highlights.participant.fullName || $highlights.participant.userGroup)}
			<div class="highlight-card card-participant">
				<div class="card-header">
					<span class="card-icon">👥</span>
					<h4>{translate key="plugins.generic.detailedLog.card.participantDetails"}</h4>
				</div>
				<div class="card-body">
					<div class="highlight-grid">
						{if $highlights.participant.fullName}
							<div class="highlight-item">
								<span class="item-label">{translate key="plugins.generic.detailedLog.setting.userFullName"}:</span>
								<span class="item-value font-bold">{$highlights.participant.fullName|escape}</span>
							</div>
						{/if}
						{if $highlights.participant.username}
							<div class="highlight-item">
								<span class="item-label">{translate key="plugins.generic.detailedLog.setting.username"}:</span>
								<span class="item-value font-mono">@{$highlights.participant.username|escape}</span>
							</div>
						{/if}
						{if $highlights.participant.userGroup}
							<div class="highlight-item highlight-item-full">
								<span class="item-label">{translate key="plugins.generic.detailedLog.setting.userGroupName"}:</span>
								<span class="item-value"><span class="badge badge-role">{$highlights.participant.userGroup|escape}</span></span>
							</div>
						{/if}
					</div>
				</div>
			</div>
		{/if}

		{* 5. Communication Card *}
		{if $highlights.communication && $highlights.communication.subject}
			<div class="highlight-card card-comm">
				<div class="card-header">
					<span class="card-icon">✉️</span>
					<h4>{translate key="plugins.generic.detailedLog.card.communicationDetails"}</h4>
				</div>
				<div class="card-body">
					<div class="highlight-grid">
						<div class="highlight-item highlight-item-full">
							<span class="item-label">{translate key="plugins.generic.detailedLog.setting.subject"}:</span>
							<span class="item-value font-bold">{$highlights.communication.subject|escape}</span>
						</div>
						{if $highlights.communication.recipient}
							<div class="highlight-item">
								<span class="item-label">{translate key="plugins.generic.detailedLog.setting.recipientName"}:</span>
								<span class="item-value">{$highlights.communication.recipient|escape}</span>
							</div>
						{/if}
					</div>
				</div>
			</div>
		{/if}
		{* 6. Request Body Card *}
		{if $requestBody}
			<div class="highlight-card card-request-body" style="grid-column: 1 / -1;">
				<div class="card-header">
					<span class="card-icon">📥</span>
					<h4>{translate key="plugins.generic.detailedLog.card.requestBody"}</h4>
				</div>
				<div class="card-body">
					<pre style="max-height: 250px; overflow-y: auto; background: #f8fafc; padding: 12px; border-radius: 4px; font-size: 0.85em; font-family: monospace; white-space: pre-wrap; word-break: break-all; border: 1px solid #e2e8f0; margin: 0;"><code>{$requestBody|escape}</code></pre>
				</div>
			</div>
		{/if}
	</div>

	{* All Parameters from event_log_settings Table *}
	<div class="detailed-log-section">
		<div class="section-title-wrapper">
			<h4 class="section-title">
				<span>⚙️ {translate key="plugins.generic.detailedLog.table.allSettingsTitle"}</span>
				<span class="badge badge-count">{$rawSettings|@count} {translate key="plugins.generic.detailedLog.records"}</span>
			</h4>
			<p class="section-subtitle">{translate key="plugins.generic.detailedLog.table.allSettingsSubtitle"}</p>
		</div>

		{if $interpretedSettings|@count > 0}
			<div class="table-responsive">
				<table class="pkpTable detailed-log-settings-table">
					<thead>
						<tr>
							<th style="width: 28%;">{translate key="plugins.generic.detailedLog.table.settingName"}</th>
							<th style="width: 10%;">{translate key="plugins.generic.detailedLog.table.locale"}</th>
							<th style="width: 27%;">{translate key="plugins.generic.detailedLog.table.rawValue"}</th>
							<th style="width: 35%;">{translate key="plugins.generic.detailedLog.table.interpretedValue"}</th>
						</tr>
					</thead>
					<tbody>
						{foreach from=$interpretedSettings item=s}
							<tr class="setting-row setting-cat-{$s.category|escape}">
								<td class="setting-name-cell">
									<strong>{$s.label|escape}</strong>
									<br><small class="text-muted font-mono">{$s.name|escape}</small>
								</td>
								<td class="setting-locale-cell font-mono">
									{if $s.locale !== '-'}
										<span class="badge badge-locale">{$s.locale|escape}</span>
									{else}
										<span class="text-muted">&mdash;</span>
									{/if}
								</td>
								<td class="setting-raw-cell font-mono">
									{if $s.raw_value !== null && $s.raw_value !== ''}
										{$s.raw_value|escape}
									{else}
										<span class="text-muted"><em>NULL</em></span>
									{/if}
								</td>
								<td class="setting-interpreted-cell">
									{if $s.interpreted !== $s.raw_value}
										<span class="interpreted-highlight">{$s.interpreted|escape}</span>
									{else}
										{$s.raw_value|escape}
									{/if}
								</td>
							</tr>
						{/foreach}
					</tbody>
				</table>
			</div>
		{else}
			<div class="empty-settings-notice">
				<em>{translate key="plugins.generic.detailedLog.noSettingsRecorded"}</em>
			</div>
		{/if}
	</div>

	{* Raw JSON Payload for Audit Trail *}
	<div class="detailed-log-section raw-json-section">
		<details>
			<summary class="raw-json-summary">🔍 {translate key="plugins.generic.detailedLog.viewRawJson"}</summary>
			<pre class="raw-json-content"><code>{$allDataJson|escape}</code></pre>
		</details>
	</div>
</div>
