jQuery(document).ready(function() {
	
	$ = jQuery;
	
	$('.drawer').each(function() {
		
		var drawer = $(this);
		var id = drawer.attr('id');
		var button = $('a[href="#'+id+'"]');
		var expanded = false;
		
		// should be expanded (initial state set from PHP)
		if(drawer.hasClass('expanded')) expanded = true;
		
		// remember state between use
		if (Symphony.Support.localStorage && localStorage[id]) {
			expanded = (localStorage[id] == 'true') ? true : false;
			if(expanded === true) drawer.addClass('expanded');
		}
		
		if(expanded == true) button.addClass('selected');
		
		button.live('click', function(e) {
			
			e.preventDefault();
			if(expanded == true) {
				$(this).removeClass('selected');
				drawer.slideUp('fast', function() {
					$(this).removeClass('expanded');
				});
			} else {
				$(this).addClass('selected');
				drawer.slideDown('fast', function() {
					$(this).addClass('expanded');
				});
			}
			
			expanded = !expanded;
			localStorage[id] = expanded;
			
		});
		
	});
	
	buildDrawerFilters();
		
});

function buildDrawerFilters() {
	var drawer = jQuery('#drawer-filters');
	if(!drawer.length) return;
	
	var date_min = '';
	var date_max = '';

	jQuery('.date-range input:first').each(function() {
		// if the drawer is collapsed (display: none) we need to
		// temporarily show it to allow element heights to be calculated
		var drawer = jQuery(this).parents('.drawer:not(.expanded)');
		if(drawer.length) drawer.show();
		var height = jQuery(this).height();		
		jQuery(this).parent().find('span.conjunctive').height(height);
		if(drawer.length) drawer.hide();

		date_min = jQuery(this).parent().data('dateMin');
		date_max = jQuery(this).parent().data('dateMax');
	});

	jQuery('.date-range input').daterangepicker({
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

	jQuery('.drawer.filters form').bind('submit', function(e) {
		e.preventDefault();
		var get = '';
		jQuery(this).find('input, textarea, select').each(function() {
			var type = jQuery(this).attr('type');
			// no need to send buttons
			if(type == 'button' || type == 'submit') return;
			get += jQuery(this).attr('name') + '=' + encodeURI(jQuery(this).val()) + '&';
		});
		// remove trailing ampersand
		get = get.replace(/&$/,'');
		window.location.href = '?' + get;
	});

	jQuery('.drawer.filters input.secondary').bind('click', function(e) {
		e.preventDefault();
		window.location.href = '?';
	});
	
}