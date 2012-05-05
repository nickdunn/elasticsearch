<?php

	require_once(TOOLKIT . '/class.datasource.php');
	require_once(TOOLKIT . '/class.entrymanager.php');
	
	require_once(EXTENSIONS . '/elasticsearch/lib/class.elasticsearch.php');

	Class datasourceelasticsearch extends Datasource{

		public $dsParamROOTELEMENT = 'elasticsearch';

		public function __construct(&$parent, $env=NULL, $process_params=true){
			parent::__construct($parent, $env, $process_params);
		}
		
		public function about(){
			return array(
				'name' => 'ElasticSearch: Search'
			);
		}

		public function grab(&$param_pool=NULL){
			
			$config = (object)Symphony::Configuration()->get('elasticsearch');
			
			// build an object of runtime parameters
			$params = (object)array(
				'keywords' => isset($_GET['keywords']) ? $_GET['keywords'] : '',
				'current-page' => (isset($_GET['page']) && is_numeric($_GET['page'])) ? (int)$_GET['page'] : 1,
				'per-page' => (isset($_GET['per-page']) && is_numeric($_GET['per-page'])) ? (int)$_GET['per-page'] : $config->{'per-page'},
				'sort' =>  isset($_GET['sort']) ? $_GET['sort'] : $config->sort,
				'direction' =>  (isset($_GET['direction']) && in_array($_GET['direction'], array('asc', 'desc'))) ? $_GET['direction'] : $config->direction,
				'sections' =>  (isset($_GET['sections']) && !empty($_GET['sections'])) ? array_map('trim', explode(',', $_GET['sections'])) : NULL,
				'default-sections' => !empty($config->{'default-sections'}) ? explode(',', $config->{'default-sections'}) : NULL,
				'language' =>  (isset($_GET['language']) && !empty($_GET['language'])) ? array_map('trim', explode(',', $_GET['language'])) : NULL,
				'default-language' => !empty($config->{'default-language'}) ? explode(',', $config->{'default-language'}) : NULL
			);
			
			$params->{'keywords-raw'} = $params->keywords;
			$params->keywords = ElasticSearch::filterKeywords($params->keywords);
			
			// don't run search if not searching for anything
			if(empty($params->keywords)) return;
			
			// check valid page number
			if($params->{'current-page'} < 1) $params->{'current-page'} = 1;
			
			// if no language passed but there are defaults, use the defaults
			if($params->{'language'} === NULL && count($params->{'default-language'})) {
				$params->{'language'} = $params->{'default-language'};
			}
			
			// include this extension's own library
			ElasticSearch::init();
			
			// a query_string search type in ES accepts common (Lucene) search syntax such as
			// prefixing terms with +/- and surrounding exact phrases with quotes
			$query_querystring = new Elastica_Query_QueryString();
			// all terms are required
			$query_querystring->setDefaultOperator('AND');
			// pass in keywords
			$query_querystring->setQueryString($params->keywords);
			// only apply the search to fields mapped as multi-type with a sub-type named "symphony_fulltext"
			// this allows us to exclude fields from this generic full-site search but search them elsewhere
			if($params->{'language'}) {
				$fields = array();
				foreach($params->{'language'} as $language) {
					$fields[] = '*_' . $language . '.symphony_fulltext';
				}
				$query_querystring->setFields($fields);
			} else {
				$query_querystring->setFields(array('*.symphony_fulltext'));
			}
			
			// create the parent query object (a factory) into which the query_string is passed
			$query = new Elastica_Query($query_querystring);
			$query->setLimit($params->{'per-page'});
			// TODO: check this. should it be + 1?
			$query->setFrom($params->{'per-page'} * ($params->{'current-page'} - 1));
			$query->setSort(array($params->{'sort'} => $params->{'direction'}));
			
			// build a search object, this wraps an Elastica_Client and handles requests to and from the ElasticSearch server
			$search = new Elastica_Search(ElasticSearch::getClient());
			// search on our site index only (in case the server is running multiple indexes)
			$search->addIndex(ElasticSearch::getIndex());
			
			// create a new facet on the entry _type (section handle). this will return a list
			// of sections in which the matching entries reside, and a count of matches in each
			$facet = new Elastica_Facet_Terms('filtered-sections');
			$facet->setField('_type');
			$query->addFacet($facet);
			
			// we also want a list of _all_ sections and their total entry counts. facets run within the context
			// of the query they are attached to, so we want a new query that searches within the specified sections
			// but doesn't search on the keywords (so it finds everything). ES supports this with a match_all query
			// which Elastica creates by default when you create a plain query object
			$query_all = new Elastica_Query();
			$facet = new Elastica_Facet_Terms('all-sections');
			$facet->setField('_type');
			$query_all->addFacet($facet);
			
			// build an array of all valid section handles that have mappings
			$all_mapped_sections = array();
			$section_full_names = array();
			
			foreach(ElasticSearch::getAllTypes() as $type) {
				// if using default config sections, check that the type exists in the default
				if(count($params->{'default-sections'}) > 0 && !in_array($type->section->get('handle'), $params->{'default-sections'})) continue;
				$all_mapped_sections[] = $type->section->get('handle');
				// cache an array of section names indexed by their handles, quick lookup later
				$section_full_names[$type->section->get('handle')] = $type->section->get('name');
			}
			
			$sections = array();
			// no specified sections were sent in the params, so default to all available sections
			if($params->sections === NULL) {
				$sections = $all_mapped_sections;
			}
			// otherwise filter out any specified sections that we don't have mappings for, in case
			// a user has made a typo or someone is tampering with the URL params
			else {
				foreach($params->sections as $handle) {
					if(!in_array($handle, $all_mapped_sections)) continue;
					$sections[] = $handle;
				}
			}
			
			// a filter is an additional set of filtering that can be added to a query. filters are run
			// after the query has executed, so run over the resultset and remove documents that don't
			// match the criteria. they are fast and are cached by ES. we want to restrict the search
			// results to within the specified sections only, so we add a filter on the _type (section handle)
			// field. the filter is of type "terms" (an array of exact-match strings)
			$filter = new Elastica_Filter_Terms('_type');
			
			// build an array of field handles which should be highlighted in search results, used for building
			// the excerpt on results pages. a field is marked as highlightable by giving it a "symphony_fulltext"
			// field in the section mappings
			$highlights = array();
			
			// iterate over each valid section, adding it as a filter and finding any highlighted fields within
			foreach($sections as $section) {				
				// add these sections to the entry search
				$filter->addTerm($section);
				// read the section's mapping JSON from disk
				$mapping = json_decode(ElasticSearch::getTypeByHandle($section)->mapping_json, FALSE);
				// find fields that have symphony_highlight
				foreach($mapping->{$section}->properties as $field => $properties) {
					if(!$properties->fields->symphony_fulltext) continue;
					$highlights[] = array($field => (object)array());
				}
			}
			
			// add the section filter to both queries (keyword search and the all entries facet search)
			$query->setFilter($filter);
			$query_all->setFilter($filter);
			
			// configure highlighting for the keyword search
			$query->setHighlight(array(
				'fields' => $highlights,
				// encode any HTML attributes or entities, ensures valid XML
				'encoder' => 'html',
				// number of characters of each fragment returned
				'fragment_size' => $config->{'highlight-fragment-size'},
				// how many fragments allowed per field
				'number_of_fragments' => $config->{'highlight-per-field'},
				// custom highlighting tags
				'pre_tags' => array('<strong class="highlight">'),
				'post_tags' => array('</strong>')
			));
			
			// run both queries!
			$query_result = $search->search($query);
			$query_all_result = $search->search($query_all);
			
			// build root XMK element
			$xml = new XMLElement($this->dsParamROOTELEMENT, NULL, array(
				'took' => $query_result->getResponse()->getEngineTime() . 'ms',
				'max-score' => round($query_result->getMaxScore(), 4)
			));
			
			// append keywords to the XML
			$xml_keywords = new XMLElement('keywords');
			$xml_keywords->appendChild(new XMLElement('raw', General::sanitize($params->{'keywords-raw'})));
			$xml_keywords->appendChild(new XMLElement('filtered', General::sanitize($params->{'keywords'})));
			$xml->appendChild($xml_keywords);
			
			// build pagination
			$xml->appendChild(General::buildPaginationElement(
				$query_result->getTotalHits(),
				ceil($query_result->getTotalHits() * (1 / $params->{'per-page'})),
				$params->{'per-page'},
				$params->{'current-page'}
			));
			
			// build facets
			$xml_facets = new XMLElement('facets');
			// merge the facets from both queries so they appear as one
			$facets = array_merge($query_result->getFacets(), $query_all_result->getFacets());
			foreach($facets as $handle => $facet) {
				$xml_facet = new XMLElement('facet', NULL, array('handle' => $handle));
				foreach($facet['terms'] as $term) {
					// only show sections that are in default config, if it is being used
					if(!in_array($term['term'], $all_mapped_sections)) continue;
					$xml_facet_term = new XMLElement('term', $section_full_names[$term['term']], array(
						'handle' => $term['term'],
						'entries' => $term['count'],
						// mark whether this section was searched within
						'active' => in_array($term['term'], $sections) ? 'yes' : 'no',
					));
					$xml_facet->appendChild($xml_facet_term);
				}
				$xml_facets->appendChild($xml_facet);
			}
			$xml->appendChild($xml_facets);
			
			// if each entry is to have its full XML built and appended to the result,
			// create a new EntryManager for using later on
			if($config->{'build-entry-xml'} === 'yes') {
				$em = new EntryManager(Frontend::instance());
				$field_pool = array();
			}
			
			// append entries
			$xml_entries = new XMLElement('entries');
			foreach($query_result->getResults() as $data) {
				
				$entry = new XMLElement('entry', NULL, array(
					'id' => $data->getId(),
					'section' => $data->getType(),
					'score' => is_array($data->getScore()) ? reset($data->getScore()) : round($data->getScore(), 4)
				));
				
				// append field highlights
				foreach($data->getHighlights() as $field => $highlight) {
					foreach($highlight as $html) {
						$entry->appendChild(new XMLElement('highlight', $html, array('field' => $field)));
					}
				}
				
				// build and append entry data
				// this was pinched from Symphony's datasource class
				if($config->{'build-entry-xml'} === 'yes') {
					$e = reset($em->fetch($data->getId()));
					$field_data = $e->getData();
					foreach($field_data as $field_id => $values) {
						if(!isset($field_pool[$field_id]) || !is_object($field_pool[$field_id])) {
							$field_pool[$field_id] = FieldManager::fetch($field_id);
						}
						$field_pool[$field_id]->appendFormattedElement($entry, $values, FALSE, NULL, $e->get('id'));
					}
				}
				
				$xml_entries->appendChild($entry);
				
				// put each entry ID into the param pool for chaining
				$param_pool['ds-elasticsearch'][] = $data->getId();
			}
			$xml->appendChild($xml_entries);
			
			// log query if logging is enabled
			if ($config->{'log-searches'} === 'yes') {
				ElasticSearchLogs::save($params->keywords, $params->{'keywords-raw'}, $sections, $params->{'current-page'}, $query_result->getTotalHits());
			}
			
			return $xml;
		}

	}
