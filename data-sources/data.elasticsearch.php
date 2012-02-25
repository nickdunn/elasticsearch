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
				'name' => 'ElasticSearch'
			);
		}

		public function grab(&$param_pool=NULL){
			
			$config = (object)Symphony::Configuration()->get('elasticsearch');
			
			// build an object of runtime parameters
			$params = (object)array(
				'keywords' => isset($_GET['keywords']) ? $_GET['keywords'] : '',
				'current-page' => isset($_GET['page']) ? $_GET['page'] : 1,
				'per-page' => isset($_GET['per-page']) ? $_GET['per-page'] : $config->{'per-page'},
				'sort' =>  isset($_GET['sort']) ? $_GET['sort'] : $config->sort,
				'direction' =>  isset($_GET['direction']) ? $_GET['direction'] : $config->direction,
				'sections' =>  isset($_GET['sections']) ? array_map('trim', explode(',', $_GET['sections'])) : NULL,
			);
			
			if(empty($params->keywords)) return;
			
			// check valid page number
			if($params->{'current-page'} < 1) $params->{'current-page'} = 1;
			
			// include this extension's own library
			ElasticSearch::init();
			
			$entries_querystring = new Elastica_Query_QueryString();
			$entries_querystring->setDefaultOperator('AND');
			
			// TODO: investigate impact of these, maybe they are useful
			//$entries_querystring->setFuzzyPrefixLength(1);
			//$entries_querystring->setPhraseSlop(1);
			
			// to analyse a search by snowball, it must be indexed this way too
			// so set this in type mapping also, otherwise this will not work
			// it will convert "library" to "librari" but if you didn't _index_ with
			// snowball too, then "librari" won't be in your content, so no matches
			//$entries_querystring->setAnalyzer('snowball');
			
			$entries_querystring->setQueryString($params->keywords);
			$entries_querystring->setAnalyzer('custom_analyzer');
			
			$entries_query = new Elastica_Query($entries_querystring);
			$entries_query->setLimit($params->{'per-page'});
			$entries_query->setFrom($params->{'per-page'} * ($params->{'current-page'} - 1));
			$entries_query->setSort(array(
				$params->{'sort'} => $params->{'direction'}
			));
			
			// copy this query to use for building facets matching the same keywords
			$facet_query = $entries_query;
			
			// create a new facet using the document type (section)
			$facet = new Elastica_Facet_Terms('filtered-entries-by-section');
			$facet->setField('_type');
			$facet_query->addFacet($facet);
			
			// build a search object, this wraps an Elastica_Client and handles
			// requests to and from the ElasticSearch server
			$search = new Elastica_Search(ElasticSearch::getClient());
			$search->addIndex(ElasticSearch::getIndex());
			
			// the facet search should not be be filtered by selected sections, so
			// run this query first before adding the type (section) filters
			$facet_result = $search->search($facet_query);
			
			$entries_matchall_query = new Elastica_Query();
			$facet = new Elastica_Facet_Terms('all-entries-by-section');
			$facet->setField('_type');
			$entries_matchall_query->addFacet($facet);
			$facet_result_all = $search->search($entries_matchall_query);
			
			$sections = $tmp_sections = array();
			$section_full_names = array();
			// build an array of all valid section handles that have mappings
			foreach(ElasticSearch::getAllTypes() as $type) {
				$tmp_sections[] = $type->section->get('handle');
				$section_full_names[$type->section->get('handle')] = $type->section->get('name');
			}
			
			// no params were sent, so default to all available sections
			if($params->sections === NULL) {
				$sections = $tmp_sections;
			}
			// otherwise strip out sent sections that we don't have mappings for
			else {
				foreach($params->sections as $handle) {
					if(!in_array($handle, $tmp_sections)) continue;
					$sections[] = $handle;
				}
			}
			
			$highlights = array();
			foreach($sections as $section) {
				
				// add these sections to the entry search
				$search->addType($section);
				
				// highlight desired fields
				$mapping = json_decode(ElasticSearch::getTypeByHandle($section)->mapping_json, FALSE);
				foreach($mapping->{$section}->properties as $field => $properties) {
					if(!$properties->symphony_highlight) continue;
					$highlights[] = array($field => (object)array());
				}
			}
			
			$entries_query->setHighlight(array(
				'encoder' => 'html',
				'fragment_size' => $config->{'highlight-fragment-size'},
				'number_of_fragments' => $config->{'highlight-per-field'},
				'pre_tags' => array('<strong class="highlight">'),
				'post_tags' => array('</strong>'),
				'fields' => $highlights
			));
			
			// run the entry search
			$entries_result = $search->search($entries_query);
			
			$xml = new XMLElement($this->dsParamROOTELEMENT, NULL, array(
				'took' => $entries_result->getResponse()->getEngineTime() . 'ms',
				'max-score' => $entries_result->getMaxScore()
			));
			
			$xml_keywords = new XMLElement('keywords', General::sanitize($params->keywords));
			$xml->appendChild($xml_keywords);
			
			// build pagination
			$xml->appendChild(General::buildPaginationElement(
				$entries_result->getTotalHits(),
				ceil($entries_result->getTotalHits() * (1 / $params->{'per-page'})),
				$params->{'per-page'},
				$params->{'current-page'}
			));
			
			$xml_sections = new XMLElement('sections');
			
			// sections facet, filtered by current query
			$filtered_facet = reset($facet_result->getFacets());
			$filtered_facet = $filtered_facet['terms'];
			
			foreach($facet_result_all->getFacets() as $name => $data) {
				
				// all sections, not filtered
				foreach($data['terms'] as $term) {
					
					$filtered_count = 0;
					foreach($filtered_facet as $facet) {
						if($facet['term'] !== $term['term']) continue;
						$filtered_count = $facet['count'];
					}
					
					$xml_sections->appendChild(new XMLElement('section', $section_full_names[$term['term']], array(
							'handle' => $term['term'],
							'entries' => $term['count'],
							'entries-matching' => $filtered_count,
							'active' => in_array($term['term'], $sections) ? 'yes' : 'no',
						)
					));
				}
			}
			$xml->appendChild($xml_sections);
			
			if($config->{'build-entry-xml'} === 'yes') {
				$em = new EntryManager(Frontend::instance());
				$field_pool = array();
			}
			
			// append entries
			$xml_entries = new XMLElement('entries');
			foreach($entries_result->getResults() as $data) {
				
				$entry = new XMLElement('entry', NULL, array(
					'id' => $data->getId(),
					'section' => $data->getType(),
					'score' => is_array($data->getScore()) ? reset($data->getScore()) : $data->getScore()
				));
				
				foreach($data->getHighlights() as $field => $highlight) {
					foreach($highlight as $html) {
						$entry->appendChild(new XMLElement('highlight', $html, array('field' => $field)));
					}
				}
				
				// build and append entry data
				if($config->{'build-entry-xml'} === 'yes') {
					$e = reset($em->fetch($data->getId()));
					$field_data = $e->getData();
					foreach($field_data as $field_id => $values) {
						if(!isset($field_pool[$field_id]) || !is_object($field_pool[$field_id])) {
							$field_pool[$field_id] = $em->fieldManager->fetch($field_id);
						}
						$field_pool[$field_id]->appendFormattedElement($entry, $values, FALSE, NULL, $e->get('id'));
					}
				}
				
				$xml_entries->appendChild($entry);
				
				// put each entry ID into the param pool for chaining
				$param_pool['ds-elasticsearch'][] = $data->getId();
			}
			$xml->appendChild($xml_entries);
			
			if ($config->{'log-searches'} === 'yes') {
				ElasticSearchLogs::save($params->keywords, $sections, $params->{'current-page'}, $entries_result->getTotalHits());
			}
			
			return $xml;
		}

	}
