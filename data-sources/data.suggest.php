<?php

	require_once(TOOLKIT . '/class.datasource.php');
	require_once(TOOLKIT . '/class.entrymanager.php');
	
	require_once(EXTENSIONS . '/elasticsearch/lib/class.elasticsearch.php');

	Class datasourcesuggest extends Datasource{

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
				'fields' => isset($_GET['fields']) ? $_GET['fields'] : array(),
				'per-page' => isset($_GET['per-page']) ? $_GET['per-page'] : $config->{'per-page'},
				'sections' =>  isset($_GET['sections']) ? array_map('trim', explode(',', $_GET['sections'])) : NULL,
			);
			
			if(!array($params->fields) || empty($params->fields)) return;
			
			ElasticSearch::init();
			
			$bool_query = new Elastica_Query_Bool();
			
			foreach($params->fields as $key => $value) {
				$query_text = new Elastica_Query_Text();
			    $query_text->setFieldQuery($key, $value);
			    $query_text->setFieldParam($key, 'type', 'phrase_prefix');
				$bool_query->addShould($query_text);
			}
			
			$search = new Elastica_Search(ElasticSearch::getClient());
			$search->addIndex(ElasticSearch::getIndex());
			
			$sections = $tmp_sections = array();
			// build an array of all valid section handles that have mappings
			foreach(ElasticSearch::getAllTypes() as $type) {
				$tmp_sections[] = $type->section->get('handle');
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
			
			foreach($sections as $section) {
				$search->addType($section);
			}
			
			// run the entry search
			$entries_result = $search->search($bool_query);
			
			$xml = new XMLElement($this->dsParamROOTELEMENT, NULL, array(
				'took' => $entries_result->getResponse()->getEngineTime() . 'ms',
				'max-score' => $entries_result->getMaxScore()
			));
			
			$xml_entries = new XMLElement('entries');
			foreach($entries_result->getResults() as $data) {
				
				$entry = new XMLElement('entry', NULL, array(
					'id' => $data->getId(),
					'section' => $data->getType(),
					'score' => is_array($data->getScore()) ? reset($data->getScore()) : $data->getScore()
				));
				
				$source = $data->getSource();
				foreach($params->fields as $key => $value) {
					if(!isset($source[$key])) continue;
					$entry->appendChild(new XMLElement($key, General::sanitize($source[$key])));
				}
				
				$xml_entries->appendChild($entry);
			}
			
			$xml->appendChild($xml_entries);
			
			return $xml;
		}

	}
