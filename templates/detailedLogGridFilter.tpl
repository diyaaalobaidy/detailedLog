{**
 * plugins/generic/detailedLog/templates/detailedLogGridFilter.tpl
 *
 * Copyright (c) 2026
 * Distributed under the GNU GPL v3.
 *
 * Filter template for the detailed event log grid.
 *}
<script type="text/javascript">
	$(function() {ldelim}
		$('#detailedLogFilterForm').pkpHandler('$.pkp.controllers.form.ClientFormHandler');
	{rdelim});
</script>
<form class="pkp_form detailed-log-filter-form" id="detailedLogFilterForm" action="{url router=PKP\core\PKPApplication::ROUTE_COMPONENT op="fetchGrid"}" method="post">
	{csrf}
	<div class="detailed-log-filter-row">
		<div class="filter-field filter-search">
			<input type="text" name="searchQuery" id="detailedLogSearch" class="pkp_form_input" placeholder="{translate key="plugins.generic.detailedLog.filter.searchPlaceholder"}" value="{$filterSelectionData.searchQuery|escape}">
		</div>
		<div class="filter-field filter-category">
			<select name="category" id="detailedLogCategory" class="pkp_form_input">
				<option value="all"{if !$filterSelectionData.category || $filterSelectionData.category == 'all'} selected{/if}>{translate key="plugins.generic.detailedLog.category.all"}</option>
				<option value="decision"{if $filterSelectionData.category == 'decision'} selected{/if}>⚖️ {translate key="plugins.generic.detailedLog.category.decision"}</option>
				<option value="review"{if $filterSelectionData.category == 'review'} selected{/if}>🔍 {translate key="plugins.generic.detailedLog.category.review"}</option>
				<option value="file"{if $filterSelectionData.category == 'file'} selected{/if}>📁 {translate key="plugins.generic.detailedLog.category.file"}</option>
				<option value="participant"{if $filterSelectionData.category == 'participant'} selected{/if}>👥 {translate key="plugins.generic.detailedLog.category.participant"}</option>
				<option value="publication"{if $filterSelectionData.category == 'publication'} selected{/if}>📢 {translate key="plugins.generic.detailedLog.category.publication"}</option>
				<option value="email"{if $filterSelectionData.category == 'email'} selected{/if}>✉️ {translate key="plugins.generic.detailedLog.category.email"}</option>
				<option value="metadata"{if $filterSelectionData.category == 'metadata'} selected{/if}>📝 {translate key="plugins.generic.detailedLog.category.metadata"}</option>
			</select>
		</div>
		<div class="filter-field filter-actions">
			<button type="submit" class="pkp_button pkp_button_primary">{translate key="common.search"}</button>
		</div>
	</div>
</form>
