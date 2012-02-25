<?php
	
	require_once(TOOLKIT . '/class.administrationpage.php');
	require_once(TOOLKIT . '/class.sectionmanager.php');
	require_once(TOOLKIT . '/class.entrymanager.php');
	
	require_once(EXTENSIONS . '/elasticsearch/lib/class.elasticsearch.php');
	
	class contentExtensionElasticsearchReindex extends AdministrationPage {
		
		public function build($context) {
			$this->context = $context;
			parent::build($context);
		}
		
		public function view() {
			
			$section_handle = (string)$this->context[0];
			$page = isset($this->context[1]) ? (int)$this->context[1] : 1;
			if(empty($section_handle)) die('Invalid section handle');
			
			$config = (object)Symphony::Configuration()->get('elasticsearch');
			
			ElasticSearch::init();
			$type = ElasticSearch::getTypeByHandle($section_handle);
			
			if($page === 1) {
				// delete all documents in this index
				$query = new Elastica_Query(array(
					'query' => array(
						'match_all' => array()
					)
				));
				$type->type->deleteByQuery($query);
			}
			
			// get new entries
			$em = new EntryManager(Symphony::Engine());
			$entries = $em->fetchByPage(
				$page,
				$type->section->get('id'),
				(int)$config->{'reindex-batch-size'}, // page size
				NULL, //where
				NULL, // joins
				FALSE, //group
				FALSE, //records_only
				TRUE // build_entries
			);
			
			foreach($entries['records'] as $entry) {
				ElasticSearch::indexEntry($entry, $type->section);
			}
			
			$entries['total-entries'] = 0;
			
			// last page, count how many entries in the index
			if($entries['remaining-pages'] == 0) {
				// wait a few seconds, allow HTTP requests to complete...
				sleep(5);
				$entries['total-entries'] = $type->type->count();
			}
			
			header('Content-type: application/json');
			echo json_encode(array(
				'pagination' => array(
					'total-pages' => (int)$entries['total-pages'],
					'total-entries' => (int)$entries['total-entries'],
					'remaining-pages' => (int)$entries['remaining-pages'],
					'next-page' => $page + 1
				)
			));
			exit;

		}
	}