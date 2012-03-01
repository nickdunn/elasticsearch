var Elasticsearch_Mappings = {

	sections: [],
	progress: 0,
	refresh_rate: 0,

	init: function() {

		var self = this;

		// cache handles of sections to re-index
		jQuery('tbody > tr').each(function() {
			var section = jQuery(this);
			if(section.data('reindex') == 'yes') {
				self.sections.push(section.data('handle'));
				section.find('span').text('Waiting...').addClass('inactive');
			}
		});

		this.refresh_rate = Symphony.Context.get('elasticsearch')['reindex-batch-delay'] * 1000;;
		this.indexNextSection();

	},

	indexNextSection: function() {
		if (this.sections.length == this.progress) return;
		this.indexSectionByPage(this.sections[this.progress], 1);
	},

	indexSectionByPage: function(handle, page, total_pages) {
		var self = this;
		
		var span = jQuery('#reindex-' + handle);
		
		span.text('Indexing page ' + page + (total_pages ? ' of ' + total_pages : '')).removeClass('inactive');
		span.parent().prev().addClass('spinner');

		jQuery.ajax({
			dataType: 'json',
			url: Symphony.Context.get('root') + '/symphony/extension/elasticsearch/reindex/' + handle + '/' + page + '/',
			success: function(response) {
				
				span.text('Indexing page ' + page + ' of ' + response.pagination['total-pages']);
				
				// there are more pages left
				if (response.pagination['remaining-pages'] > 0) {
					setTimeout(function() {
						self.indexSectionByPage(handle, response.pagination['next-page'], response.pagination['total-pages']);
					}, self.refresh_rate);
				}
				// proceed to next section
				else {
					
					span.text(response.pagination['total-entries'] + ' ' + (response.pagination['total-entries'] == 1 ? 'entry' : 'entries'));
					span.parent().prev().removeClass('spinner');
					
					setTimeout(function() {
						self.progress++;
						self.indexNextSection();
					}, self.refresh_rate);
				}
			}
		});		
	}
};

jQuery(document).ready(function() {
	Elasticsearch_Mappings.init();
});