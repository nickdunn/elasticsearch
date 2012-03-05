<?php

	require_once(TOOLKIT . '/class.datasource.php');
	require_once(TOOLKIT . '/class.entrymanager.php');
	
	require_once(EXTENSIONS . '/elasticsearch/lib/class.elasticsearch.php');

	Class datasourcemore_like_this extends Datasource{

		public $dsParamROOTELEMENT = 'elasticsearch-more-like-this';

		public function __construct(&$parent, $env=NULL, $process_params=true){
			parent::__construct($parent, $env, $process_params);
		}
		
		public function about(){
			return array(
				'name' => 'ElasticSearch: More Like This'
			);
		}

		public function grab(&$param_pool=NULL){
			
			$config = (object)Symphony::Configuration()->get('elasticsearch');
			
			ElasticSearch::init();
			
			$query_morelikethis = new Elastica_Query_MoreLikeThis();
			//$query_morelikethis->setFields(array('speaker', 'lines'));
			$query_morelikethis->setLikeText('wonder great');
			
			$query = new Elastica_Query($query_morelikethis);
			$query->setLimit(10);
			
			$search = new Elastica_Search(ElasticSearch::getClient());
			$search->addIndex(ElasticSearch::getIndex());
			
			$filter = new Elastica_Filter_Terms('_type');
			$filter->addTerm('speeches');
			$query->setFilter($filter);
			
			// run the entry search
			$entries_result = $search->search($query);
			
			var_dump($entries_result->getResults());die;
			
			return $xml;
		}

	}
