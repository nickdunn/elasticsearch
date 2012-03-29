jQuery(document).ready(function() {
	
	var $ = jQuery;
	
	var drawer = jQuery('#drawer-elasticsearch');
	if(!drawer.length) return;
	
	// is drawer open by default? if not, open it, calculate, then close again
	var is_drawer_open = drawer.is(':visible');
	if(!is_drawer_open) drawer.show();
	
	var date_range = drawer.find('.date-range');
	var date_min = date_range.data('datamin');
	var date_max = date_range.data('datamax');

	date_range.find('input').daterangepicker({
		arrows: false,
		presetRanges: [
			{text: 'Last 7 days', dateStart: 'today-7days', dateEnd: 'today' },
			{text: 'Last 30 days', dateStart: 'today-30days', dateEnd: 'today' },
			{text: 'Last 12 months', dateStart: 'today-1year', dateEnd: 'today' }
		],
		presets: {
			dateRange: 'Date range...'
		},
		nextLinkText: '&#8594;',
		prevLinkText: '&#8592;',
		closeOnSelect: true,
		datepickerOptions: {
			nextText: '&#8594;',
			prevText: '&#8592;',
			minDate: Date.parse(date_min),
			maxDate: Date.parse(date_max),
			showOtherMonths: true
		},
		earliestDate: date_min,
		latestDate: date_max
	});

	drawer.find('form').bind('submit', function(e) {
		e.preventDefault();
		var url = '';
		jQuery(this).find('input, textarea, select').each(function() {
			// no need to send buttons
			var type = jQuery(this).attr('type');
			if(type == 'button' || type == 'submit') return;
			
			url += jQuery(this).attr('name') + '=' + encodeURI(jQuery(this).val()) + '&';
		});
		// remove trailing ampersand
		url = url.replace(/&$/,'');
		window.location.href = '?' + url;
	});

	drawer.find('input.clear').bind('click', function(e) {
		e.preventDefault();
		window.location.href = '?';
	});
	
	if(!is_drawer_open) drawer.hide();
		
});