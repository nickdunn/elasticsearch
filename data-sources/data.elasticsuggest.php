<?php

	require_once(TOOLKIT . '/class.datasource.php');
	require_once(TOOLKIT . '/class.entrymanager.php');
	
	require_once(EXTENSIONS . '/elasticsearch/lib/class.elasticsearch.php');

	Class datasourceelasticsuggest extends Datasource{

		public $dsParamROOTELEMENT = 'elasticsearch-suggest';

		public function __construct(&$parent, $env=NULL, $process_params=true){
			parent::__construct($parent, $env, $process_params);
		}
		
		public function about(){
			return array(
				'name' => 'ElasticSearch: Suggest'
			);
		}

		public function grab(&$param_pool=NULL){
			
			$config = (object)Symphony::Configuration()->get('elasticsearch');
			
			// build an object of runtime parameters
			$params = (object)array(
				'keywords' => isset($_GET['keywords']) ? trim($_GET['keywords']) : '',
				'per-page' => isset($_GET['per-page']) ? $_GET['per-page'] : $config->{'per-page'},
				'sections' =>  isset($_GET['sections']) ? array_map('trim', explode(',', $_GET['sections'])) : NULL,
				'default-sections' => !empty($config->{'default-sections'}) ? explode(',', $config->{'default-sections'}) : NULL,
				'language' =>  (isset($_GET['language']) && !empty($_GET['language'])) ? array_map('trim', explode(',', $_GET['language'])) : NULL,
				'default-language' => !empty($config->{'default-language'}) ? explode(',', $config->{'default-language'}) : NULL
			);
			
			$params->keywords = ElasticSearch::filterKeywords($params->keywords);
			if(empty($params->keywords)) return;
			
			// add trailing wildcard if it's not already there
			if(end(str_split($params->keywords)) !== '*') $params->keywords = $params->keywords . '*';
			
			// if no language passed but there are defaults, use the defaults
			if($params->{'language'} === NULL && count($params->{'default-language'})) {
				$params->{'language'} = $params->{'default-language'};
			}
			
			ElasticSearch::init();
			
			$query_querystring = new Elastica_Query_QueryString();
			$query_querystring->setDefaultOperator('AND');
			$query_querystring->setQueryString($params->keywords);
			
			if($params->{'language'}) {
				$fields = array();
				foreach($params->{'language'} as $language) {
					$fields[] = '*_' . $language . '.symphony_fulltext';
				}
				$query_querystring->setFields($fields);
			} else {
				$query_querystring->setFields(array('*.symphony_fulltext'));
			}
			
			$query = new Elastica_Query($query_querystring);
			// returns loads. let's say we search for "romeo" and there are hundreds of lines that contain
			// romeo but also the play title "romeo and juliet", the first 10 or 20 results might just be script lines
			// containing "romeo", so the play title will not be included. so return a big chunk of hits to give a 
			// better chance of more different terms being in the result. a tradeoff of speed/hackiness over usefulness.
			$query->setLimit(1000);
			
			$search = new Elastica_Search(ElasticSearch::getClient());
			$search->addIndex(ElasticSearch::getIndex());
			
			$filter = new Elastica_Filter_Terms('_type');
			
			// build an array of all valid section handles that have mappings
			$all_mapped_sections = array();
			$section_full_names = array();
			foreach(ElasticSearch::getAllTypes() as $type) {
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
			
			//$autocomplete_fields = array();
			$highlights = array();
			
			foreach($sections as $section) {
				$filter->addTerm($section);
				$mapping = json_decode(ElasticSearch::getTypeByHandle($section)->mapping_json, FALSE);
				// find fields that have symphony_highlight
				foreach($mapping->{$section}->properties as $field => $properties) {
					if(!$properties->fields->symphony_autocomplete) continue;
					//$autocomplete_fields[] = $field;
					$highlights[] = array($field => (object)array());
				}
			}
			
			//$autocomplete_fields = array_unique($autocomplete_fields);
			
			$query->setFilter($filter);
			
			$query->setHighlight(array(
				'fields' => $highlights,
				// encode any HTML attributes or entities, ensures valid XML
				'encoder' => 'html',
				// number of characters of each fragment returned
				'fragment_size' => 100,
				// how many fragments allowed per field
				'number_of_fragments' => 3,
				// custom highlighting tags
				'pre_tags' => array('<strong>'),
				'post_tags' => array('</strong>')
			));
			
			// run the entry search
			$entries_result = $search->search($query);
			
			$xml = new XMLElement($this->dsParamROOTELEMENT, NULL, array(
				'took' => $entries_result->getResponse()->getEngineTime() . 'ms'
			));
			
			$words = array();
			foreach($entries_result->getResults() as $data) {
				foreach($data->getHighlights() as $field => $highlight) {
					foreach($highlight as $html) {
						$words[] = $html;
					}
				}
			}
			$words = array_unique($words);
			
			$xml_words = new XMLElement('words');
			foreach($words as $word) {
				$raw = General::sanitize(strip_tags($word));
				$highlighted = General::sanitize($word);
				$xml_word = new XMLElement('word');
				$xml_word->appendChild(new XMLElement('raw', $raw));
				$xml_word->appendChild(new XMLElement('highlighted', $highlighted));
				$xml_words->appendChild($xml_word);
			}
			$xml->appendChild($xml_words);
			
			return $xml;
		}

	}
